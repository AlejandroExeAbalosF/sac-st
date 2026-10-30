<?php

declare(strict_types=1);

namespace App\Modules\Banking\Actions;

use App\Modules\Banking\Models\CashBoxBankAccount;
use App\Modules\Banking\Support\CashBoxBankAccounts;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Da de baja una cuenta de una caja: desde el día siguiente a `until` no
 * admite depósitos ni identificaciones nuevas. Lo que ya se hizo con ella
 * sigue: un depósito en tránsito se acredita, se revierte o se rechaza contra
 * su cuenta, y un crédito llegado mientras estaba vigente se puede
 * identificar después.
 *
 * Si era la predeterminada y quedan otras vigentes en su moneda, se elige
 * cuál la reemplaza en el mismo acto; si era la última, la caja queda sin
 * cuenta para operaciones nuevas en esa moneda.
 */
final class RetireCashBoxBankAccount
{
    public function __construct(
        private readonly CashBoxBankAccounts $cuentas,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /** @throws ValidationException */
    public function handle(CashBoxBankAccount $vinculo, CarbonImmutable $until, ?int $newDefaultBankAccountId, int $actorId): CashBoxBankAccount
    {
        return DB::transaction(function () use ($vinculo, $until, $newDefaultBankAccountId, $actorId): CashBoxBankAccount {
            $vinculo = CashBoxBankAccount::query()->lockForUpdate()->findOrFail($vinculo->id);
            // Un depósito o una identificación en curso con esta cuenta termina antes de revisar los usos.
            $this->cuentas->lock($vinculo->cash_box_id, $vinculo->bank_account_id);

            if ($vinculo->valid_until !== null) {
                throw ValidationException::withMessages(['until' => 'Esa cuenta ya está dada de baja para la caja.']);
            }

            if ($until->greaterThan(BusinessDate::today())) {
                throw ValidationException::withMessages(['until' => 'La baja no se carga con fecha futura: es el último día en que la cuenta opera.']);
            }

            if ($vinculo->valid_from !== null && $until->lessThan($vinculo->valid_from)) {
                throw ValidationException::withMessages(['until' => 'La baja no puede ser anterior al inicio de la vigencia.']);
            }

            $despues = $this->cuentas->lastUseAfter($vinculo, $until);

            if ($despues !== null) {
                throw ValidationException::withMessages([
                    'until' => sprintf('Con esta cuenta se registró algo el %s: la baja no puede ser anterior.', $despues->format('d/m/Y')),
                ]);
            }

            $reemplazo = null;

            if ($vinculo->is_default) {
                $otras = CashBoxBankAccount::query()
                    ->where('cash_box_id', $vinculo->cash_box_id)
                    ->where('currency', $vinculo->currency)
                    ->whereKeyNot($vinculo->id)
                    ->whereNull('valid_until')
                    ->lockForUpdate()
                    ->get();

                if ($otras->isNotEmpty()) {
                    $reemplazo = $otras->firstWhere('bank_account_id', $newDefaultBankAccountId)
                        ?? throw ValidationException::withMessages([
                            'newDefaultBankAccountId' => 'Es la cuenta predeterminada: elegí cuál de las otras habilitadas la reemplaza.',
                        ]);
                }
            }

            // El índice de la predeterminada es inmediato: primero se suelta esta.
            $vinculo->forceFill(['is_default' => false, 'valid_until' => $until->toDateString(), 'updated_by' => $actorId])->save();
            $reemplazo?->forceFill(['is_default' => true, 'updated_by' => $actorId])->save();

            $this->auditar->handle(
                'caja.cuenta.retirada',
                $vinculo->bankAccount,
                before: ['valid_until' => null],
                after: ['valid_until' => $until->toDateString()],
                metadata: array_filter([
                    'caja' => $vinculo->cashBox->name,
                    'predeterminada' => $reemplazo?->bankAccount->label,
                ]),
                actorId: $actorId,
            );

            return $vinculo;
        });
    }
}
