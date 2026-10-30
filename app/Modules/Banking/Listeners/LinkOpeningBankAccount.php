<?php

declare(strict_types=1);

namespace App\Modules\Banking\Listeners;

use App\Modules\Banking\Models\CashBoxBankAccount;
use App\Modules\Ledger\Events\CashBookOpened;

/**
 * La cuenta con la que abre el libro es la primera habilitada de la caja:
 * predeterminada y sin inicio, para que lo que ya estaba en ella antes de
 * abrir pueda respaldar lo histórico. Igual en todas las cajas.
 *
 * Una apertura sin cuenta no habilita nada. Si la caja ya tiene esa cuenta
 * vigente —otra moneda no comparte cuenta—, no se duplica.
 */
final class LinkOpeningBankAccount
{
    public function onCashBookOpened(CashBookOpened $event): void
    {
        $apertura = $event->opening;

        if ($apertura->bank_account_id === null) {
            return;
        }

        $vigente = CashBoxBankAccount::query()
            ->where('cash_box_id', $apertura->cash_box_id)
            ->where('bank_account_id', $apertura->bank_account_id)
            ->whereNull('valid_until')
            ->exists();

        if ($vigente) {
            return;
        }

        $predeterminada = CashBoxBankAccount::query()
            ->where('cash_box_id', $apertura->cash_box_id)
            ->where('currency', $apertura->currency->value)
            ->where('is_default', true)
            ->exists();

        CashBoxBankAccount::query()->create([
            'cash_box_id' => $apertura->cash_box_id,
            'bank_account_id' => $apertura->bank_account_id,
            'is_default' => ! $predeterminada,
            'created_by' => $event->actorId,
            'updated_by' => $event->actorId,
        ]);
    }
}
