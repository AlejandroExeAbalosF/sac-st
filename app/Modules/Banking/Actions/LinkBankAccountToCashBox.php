<?php

declare(strict_types=1);

namespace App\Modules\Banking\Actions;

use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\CashBoxBankAccount;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Models\CashBookOpening;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Habilita una cuenta bancaria para una caja, desde una fecha.
 *
 * La vigencia es la efectiva —desde cuándo la cuenta corresponde a la
 * caja—, no la de la carga, que queda en el vínculo y en la auditoría. Sin
 * fecha es «inicio histórico desconocido»: lo que ya estaba en la cuenta
 * antes puede respaldar lo histórico. Nunca una fecha futura.
 *
 * Una cuenta dada de baja que vuelve a la caja abre un período nuevo, que
 * empieza después de la baja: el tiempo en que no correspondía queda afuera,
 * y la base impide que dos períodos se superpongan. La primera de su moneda
 * queda predeterminada; las demás, si se pide.
 */
final class LinkBankAccountToCashBox
{
    public function __construct(private readonly RecordAuditEvent $auditar) {}

    /** @throws ValidationException */
    public function handle(int $cashBoxId, int $bankAccountId, ?CarbonImmutable $validFrom, bool $makeDefault, int $actorId): CashBoxBankAccount
    {
        return DB::transaction(function () use ($cashBoxId, $bankAccountId, $validFrom, $makeDefault, $actorId): CashBoxBankAccount {
            // Bloqueada antes de leer su moneda: un cambio de moneda en curso termina primero.
            $cuenta = BankAccount::query()->lockForUpdate()->find($bankAccountId);

            if ($cuenta === null || ! $cuenta->is_active) {
                throw ValidationException::withMessages(['bankAccountId' => 'Elegí una cuenta bancaria activa.']);
            }

            $moneda = Currency::from($cuenta->currency);

            if (CashBookOpening::for($cashBoxId, $moneda) === null) {
                throw ValidationException::withMessages([
                    'bankAccountId' => "La caja no tiene abierto su libro en {$moneda->value}: abrilo antes de habilitarle una cuenta en esa moneda.",
                ]);
            }

            if ($validFrom !== null && $validFrom->greaterThan(BusinessDate::today())) {
                throw ValidationException::withMessages(['validFrom' => 'La vigencia no empieza en una fecha futura: cargala el día en que la cuenta empieza a usarse.']);
            }

            $periodos = CashBoxBankAccount::query()
                ->where('cash_box_id', $cashBoxId)
                ->where('bank_account_id', $bankAccountId)
                ->lockForUpdate()
                ->get();

            if ($periodos->contains(fn (CashBoxBankAccount $p): bool => $p->valid_until === null)) {
                throw ValidationException::withMessages(['bankAccountId' => 'Esa cuenta ya está habilitada para la caja.']);
            }

            /** @var CarbonImmutable|null $ultimaBaja */
            $ultimaBaja = $periodos->max('valid_until');

            if ($ultimaBaja !== null && ($validFrom === null || $validFrom->lessThanOrEqualTo($ultimaBaja))) {
                throw ValidationException::withMessages([
                    'validFrom' => sprintf('La cuenta operó en la caja hasta el %s: el período nuevo empieza después.', $ultimaBaja->format('d/m/Y')),
                ]);
            }

            $predeterminada = CashBoxBankAccount::query()
                ->where('cash_box_id', $cashBoxId)
                ->where('currency', $moneda->value)
                ->where('is_default', true)
                ->lockForUpdate()
                ->first();
            $queda = $makeDefault || $predeterminada === null;

            // El índice de la predeterminada es inmediato: primero se suelta la anterior.
            if ($queda && $predeterminada !== null) {
                $predeterminada->forceFill(['is_default' => false, 'updated_by' => $actorId])->save();
            }

            $vinculo = CashBoxBankAccount::query()->create([
                'cash_box_id' => $cashBoxId,
                'bank_account_id' => $bankAccountId,
                'valid_from' => $validFrom?->toDateString(),
                'is_default' => $queda,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            $this->auditar->handle(
                'caja.cuenta.habilitada',
                $cuenta,
                after: ['valid_from' => $vinculo->valid_from?->toDateString(), 'valid_until' => null, 'is_default' => $queda],
                metadata: array_filter(['caja' => $vinculo->cashBox->name, 'periodo_anterior_hasta' => $ultimaBaja?->toDateString()]),
                actorId: $actorId,
            );

            return $vinculo;
        });
    }
}
