<?php

declare(strict_types=1);

namespace App\Modules\Multas\Support;

use App\Modules\Banking\Enums\ReconciliationStatus;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Support\BankCreditHandler;
use Illuminate\Contracts\Auth\Access\Authorizable;

/**
 * Los créditos de la cuenta de Multas en la lista de movimientos bancarios.
 *
 * Desde la apertura de la caja, libres, se identifican: como el pago de una
 * multa o como la acreditación de un depósito de la caja (M08). Lo anterior a
 * la apertura ya estaba en el saldo que el área traía: no es un cobro, y solo
 * respalda un pago anterior desde la ficha de la multa. La cuenta es
 * compartida con Aranceles: lo que no sea de Multas queda pendiente, sin
 * asignarle una caja por descarte.
 */
final class FineBankCreditHandler implements BankCreditHandler
{
    public function __construct(private readonly FineBankCredits $creditos) {}

    public function actionsFor(array $credits, ?Authorizable $viewer): array
    {
        $apertura = $this->creditos->book();

        if ($apertura === null) {
            return [];
        }

        $propios = array_values(array_filter($credits, fn (BankTransaction $c): bool => $c->bank_account_id === $apertura->bank_account_id
            && $c->direction === TransactionDirection::Credit
            && $c->reconciliation_status === ReconciliationStatus::Pending));

        if ($propios === []) {
            return [];
        }

        $identificables = $this->creditos->pending($apertura)
            ->whereIn('id', array_map(fn (BankTransaction $c): int => $c->id, $propios))
            ->pluck('id')
            ->all();
        $concilia = $viewer?->can('multas.verificar-pagos') ?? false;
        $acciones = [];

        foreach ($propios as $credito) {
            if ($credito->transaction_date->lt($apertura->opened_on)) {
                $acciones[$credito->id] = [
                    'label' => 'Anterior a la apertura de Multas',
                    'href' => null,
                    'note' => 'Ya estaba en el saldo que el área traía: se usa como pago anterior desde la ficha de la multa.',
                ];
            } elseif ($concilia && in_array($credito->id, $identificables, true)) {
                $acciones[$credito->id] = [
                    'label' => 'Identificar',
                    'href' => route('multas.creditos.identificar', $credito, absolute: false),
                    'note' => null,
                ];
            }
        }

        return $acciones;
    }
}
