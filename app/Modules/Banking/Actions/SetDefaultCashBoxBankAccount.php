<?php

declare(strict_types=1);

namespace App\Modules\Banking\Actions;

use App\Modules\Banking\Models\CashBoxBankAccount;
use App\Modules\Shared\Actions\RecordAuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hace predeterminada una cuenta vigente de la caja en su moneda: la que se
 * propone al depositar. No mueve plata entre bancos ni cambia lo ya hecho.
 */
final class SetDefaultCashBoxBankAccount
{
    public function __construct(private readonly RecordAuditEvent $auditar) {}

    /** @throws ValidationException */
    public function handle(CashBoxBankAccount $vinculo, int $actorId): CashBoxBankAccount
    {
        return DB::transaction(function () use ($vinculo, $actorId): CashBoxBankAccount {
            $vinculo = CashBoxBankAccount::query()->lockForUpdate()->findOrFail($vinculo->id);

            if ($vinculo->valid_until !== null) {
                throw ValidationException::withMessages(['bankAccountId' => 'Una cuenta dada de baja no puede ser la predeterminada.']);
            }

            if ($vinculo->is_default) {
                return $vinculo;
            }

            $anterior = CashBoxBankAccount::query()
                ->where('cash_box_id', $vinculo->cash_box_id)
                ->where('currency', $vinculo->currency)
                ->where('is_default', true)
                ->lockForUpdate()
                ->first();

            // El índice de la predeterminada es inmediato: primero se suelta la anterior.
            $anterior?->forceFill(['is_default' => false, 'updated_by' => $actorId])->save();
            $vinculo->forceFill(['is_default' => true, 'updated_by' => $actorId])->save();

            $this->auditar->handle(
                'caja.cuenta.predeterminada',
                $vinculo->bankAccount,
                metadata: array_filter(['caja' => $vinculo->cashBox->name, 'anterior' => $anterior?->bankAccount->label]),
                actorId: $actorId,
            );

            return $vinculo;
        });
    }
}
