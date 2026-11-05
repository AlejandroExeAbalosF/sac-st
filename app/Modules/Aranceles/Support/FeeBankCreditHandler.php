<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Support;

use App\Modules\Banking\Enums\ReconciliationStatus;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Support\BankCreditHandler;
use Illuminate\Contracts\Auth\Access\Authorizable;

/**
 * Los créditos de las cuentas de Aranceles en la lista de movimientos bancarios.
 *
 * Desde la apertura de la caja y dentro de la vigencia de la cuenta se ofrecen
 * para identificar, también si ya se usaron en parte: lo que sobró de otro arancel
 * sigue libre (regla A54). Ofrecerlos no los hace de Aranceles: la cuenta es
 * compartida con Multas, y lo que no sea de acá queda sin identificar.
 */
final class FeeBankCreditHandler implements BankCreditHandler
{
    public function __construct(private readonly FeeBankCredits $creditos) {}

    public function actionsFor(array $credits, ?Authorizable $viewer): array
    {
        $apertura = $this->creditos->book();

        if ($apertura === null) {
            return [];
        }

        $cuentas = $this->creditos->accounts($apertura);
        $propios = array_values(array_filter($credits, fn (BankTransaction $c): bool => in_array($c->bank_account_id, $cuentas, true)
            && $c->direction === TransactionDirection::Credit
            && in_array($c->reconciliation_status, [ReconciliationStatus::Pending, ReconciliationStatus::Partial], true)));

        if ($propios === []) {
            return [];
        }

        $identificables = $this->creditos->identifiable($apertura)
            ->whereIn('id', array_map(fn (BankTransaction $c): int => $c->id, $propios))
            ->pluck('id')
            ->all();
        $concilia = $viewer?->can('aranceles.verificar-pagos') ?? false;
        $acciones = [];

        foreach ($propios as $credito) {
            if ($credito->transaction_date->lt($apertura->opened_on)) {
                $acciones[$credito->id] = [
                    'label' => 'Anterior a la apertura de Aranceles',
                    'href' => null,
                    'note' => 'Ya estaba en la cuenta antes de abrir la caja de Aranceles: no es un cobro de esta caja.',
                ];
            } elseif (! in_array($credito->id, $identificables, true)) {
                $acciones[$credito->id] = [
                    'label' => 'Fuera de la vigencia en Aranceles',
                    'href' => null,
                    'note' => 'La cuenta no estaba habilitada para la caja de Aranceles en la fecha del crédito.',
                ];
            } elseif ($concilia) {
                $acciones[$credito->id] = [
                    'label' => 'Identificar como arancel',
                    'href' => route('aranceles.creditos.identificar', $credito, absolute: false),
                    'note' => null,
                ];
            }
        }

        return $acciones;
    }
}
