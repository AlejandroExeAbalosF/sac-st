<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Multas\Enums\DatePrecision;
use App\Modules\Multas\Enums\FineDebtEntryKind;
use App\Modules\Multas\Enums\FineProvenance;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineBalance;
use App\Modules\Multas\Models\FineDebtEntry;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Support\BusinessDate;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deshace un ajuste cargado por error.
 *
 * La reversión es otra fila, por el **mismo importe**: el ajuste original
 * queda a la vista con la marca de revertido. Corregir una parte no es una
 * reversión, es otro ajuste con su resolución (DER físico v1.1, §3.6).
 *
 * El importe original no se revierte: una multa que queda sin efecto se
 * reduce hasta cero con la resolución que lo dispone.
 */
final class ReverseFineDebtEntry
{
    public function __construct(
        private readonly ReconcileFineExcess $reclasificar,
        private readonly RecordAuditEvent $auditar,
    ) {}

    public function handle(FineDebtEntry $ajuste, string $reason, ?int $actorId): FineDebtEntry
    {
        return DB::transaction(function () use ($ajuste, $reason, $actorId): FineDebtEntry {
            $multa = Fine::query()->whereKey($ajuste->fine_id)->lockForUpdate()->firstOrFail();

            if (in_array($ajuste->kind, [FineDebtEntryKind::Constitution, FineDebtEntryKind::Reversal], true)) {
                throw ValidationException::withMessages([
                    'reason' => $ajuste->kind === FineDebtEntryKind::Constitution
                        ? 'El importe original no se revierte. Si la multa quedó sin efecto, reducila hasta cero con su resolución.'
                        : 'Una reversión no se revierte: registrá el ajuste que corresponda.',
                ]);
            }

            if (FineDebtEntry::query()->where('reversal_of_id', $ajuste->id)->exists()) {
                throw ValidationException::withMessages(['reason' => 'Ese ajuste ya se revirtió.']);
            }

            $obligacion = (string) FineBalance::query()->whereKey($multa->id)->value('obligation');

            if ($ajuste->kind === FineDebtEntryKind::Increase && Decimal::isNegative(Decimal::sub($obligacion, $ajuste->amount))) {
                throw ValidationException::withMessages([
                    'reason' => 'Revertir ese aumento dejaría la obligación negativa: primero revertí la reducción que vino después.',
                ]);
            }

            $reversion = FineDebtEntry::query()->create([
                'fine_id' => $multa->id,
                'kind' => FineDebtEntryKind::Reversal,
                'amount' => $ajuste->amount,
                'effective_on' => BusinessDate::today()->toDateString(),
                'effective_precision' => DatePrecision::Day,
                'reason' => trim($reason),
                'reversal_of_id' => $ajuste->id,
                'provenance' => FineProvenance::Manual,
                'created_by' => $actorId,
            ]);

            $this->reclasificar->handle($multa, 'Reversión de un '.mb_strtolower($ajuste->kind->label()).' de la obligación', $actorId);

            $this->auditar->handle(
                'multa.ajuste-revertido',
                $multa,
                before: ['obligation' => $obligacion],
                after: ['obligation' => (string) FineBalance::query()->whereKey($multa->id)->value('obligation')],
                metadata: [
                    'tipo' => $ajuste->kind->label(),
                    'amount' => $ajuste->amount,
                    'reason' => $reversion->reason,
                ],
                actorId: $actorId,
            );

            return $reversion;
        });
    }
}
