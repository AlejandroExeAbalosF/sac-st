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
 * Cambia desde cuándo una cuenta corresponde a la caja.
 *
 * Adelantar el inicio habilita un conjunto de créditos —todos los de la
 * cuenta en el período que se suma—, no solo el que se quiere reconocer: por
 * eso pide motivo y queda auditado. Atrasarlo no puede dejar afuera nada de
 * lo ya registrado con la cuenta, y la base lo repite.
 */
final class MoveCashBoxBankAccountStart
{
    public function __construct(
        private readonly CashBoxBankAccounts $cuentas,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /** @throws ValidationException */
    public function handle(CashBoxBankAccount $vinculo, ?CarbonImmutable $validFrom, string $reason, int $actorId): CashBoxBankAccount
    {
        $motivo = trim($reason);

        if (mb_strlen($motivo) < 5) {
            throw ValidationException::withMessages(['reason' => 'Contá por qué cambia la vigencia: habilita o deja afuera créditos de la cuenta.']);
        }

        return DB::transaction(function () use ($vinculo, $validFrom, $motivo, $actorId): CashBoxBankAccount {
            $vinculo = CashBoxBankAccount::query()->lockForUpdate()->findOrFail($vinculo->id);
            // Un depósito o una identificación en curso con esta cuenta termina antes de revisar los usos.
            $this->cuentas->lock($vinculo->cash_box_id, $vinculo->bank_account_id);

            if ($validFrom !== null && $validFrom->greaterThan(BusinessDate::today())) {
                throw ValidationException::withMessages(['validFrom' => 'La vigencia no empieza en una fecha futura.']);
            }

            if ($validFrom !== null && $vinculo->valid_until !== null && $validFrom->greaterThan($vinculo->valid_until)) {
                throw ValidationException::withMessages(['validFrom' => 'El inicio no puede ser posterior a la baja.']);
            }

            // Adelantarlo no puede alcanzar un período anterior de la misma cuenta.
            $previo = $this->cuentas->previousPeriod($vinculo);

            if ($previo?->valid_until !== null && ($validFrom === null || $validFrom->lessThanOrEqualTo($previo->valid_until))) {
                throw ValidationException::withMessages([
                    'validFrom' => sprintf('La cuenta tuvo otro período en la caja hasta el %s: este empieza después.', $previo->valid_until->format('d/m/Y')),
                ]);
            }

            $antes = $this->cuentas->firstUseBefore($vinculo, $validFrom);

            if ($antes !== null) {
                throw ValidationException::withMessages([
                    'validFrom' => sprintf('Con esta cuenta se registró algo el %s: el inicio no puede ser posterior.', $antes->format('d/m/Y')),
                ]);
            }

            $anterior = $vinculo->valid_from?->toDateString();
            $vinculo->forceFill(['valid_from' => $validFrom?->toDateString(), 'updated_by' => $actorId])->save();

            $this->auditar->handle(
                'caja.cuenta.vigencia-cambiada',
                $vinculo->bankAccount,
                before: ['valid_from' => $anterior],
                after: ['valid_from' => $validFrom?->toDateString()],
                metadata: ['caja' => $vinculo->cashBox->name, 'reason' => $motivo],
                actorId: $actorId,
            );

            return $vinculo;
        });
    }
}
