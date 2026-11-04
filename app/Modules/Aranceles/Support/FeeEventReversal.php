<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Support;

use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Models\FinancialEvent;
use App\Modules\Ledger\Support\InverseEntry;
use App\Modules\Shared\Support\Reason;
use App\Support\BusinessDate;

/**
 * La reversión de un asiento de un cobro: el inverso exacto, con fecha de hoy y
 * el motivo de quien la pide. Un período cerrado se corrige en el abierto.
 */
final class FeeEventReversal
{
    public function __construct(
        private readonly PostJournalEntry $asentar,
        private readonly InverseEntry $inverso,
    ) {}

    public function of(int $eventoId, string $clave, Reason $motivo, ?int $actorId): FinancialEvent
    {
        $original = FinancialEvent::query()->findOrFail($eventoId);

        return $this->asentar->handle(
            type: FinancialEventType::Reversal,
            idempotencyKey: $clave,
            lines: $this->inverso->of($eventoId),
            date: BusinessDate::today(),
            cashBoxId: $original->cash_box_id,
            description: $motivo->describe(),
            actorId: $actorId,
            reversalOfId: $eventoId,
            reversalReason: $motivo->describe(),
        );
    }
}
