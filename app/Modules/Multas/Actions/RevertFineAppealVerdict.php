<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Multas\Enums\DatePrecision;
use App\Modules\Multas\Enums\FineAppealStatus;
use App\Modules\Multas\Enums\FineDebtEntryKind;
use App\Modules\Multas\Enums\FinePaymentStatus;
use App\Modules\Multas\Enums\FineProvenance;
use App\Modules\Multas\Enums\FineReasonCode;
use App\Modules\Multas\Enums\HistoricalMovementStatus;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineAppeal;
use App\Modules\Multas\Models\FineAppealVerdict;
use App\Modules\Multas\Models\FineBalance;
use App\Modules\Multas\Models\FineDebtEntry;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Multas\Support\FineDeposits;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Support\Reason;
use App\Support\BusinessDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deshace un veredicto cargado por error: el recurso vuelve a estar en trámite
 * y la multa se congela otra vez. Si prosperó con reducción, se revierte esa
 * reducción. El veredicto queda a la vista, con quién lo dio y quién lo revirtió.
 *
 * Volver a congelar contradiría lo que pasó desde entonces, así que exige que no
 * haya otros pagos, antecedentes ni movimientos de importe. El mensaje nombra lo
 * que bloquea y qué se puede hacer con eso; la base repite la condición.
 */
final class RevertFineAppealVerdict
{
    public function __construct(
        private readonly FineDeposits $depositos,
        private readonly ReconcileFineExcess $reclasificar,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /** @throws ValidationException */
    public function handle(Fine $multa, int $expectedVerdictId, Reason $motivo, ?int $actorId): FineAppeal
    {
        return DB::transaction(function () use ($multa, $expectedVerdictId, $motivo, $actorId): FineAppeal {
            $multa = Fine::query()->whereKey($multa->id)->lockForUpdate()->firstOrFail();
            $veredicto = FineAppealVerdict::query()
                ->where('fine_id', $multa->id)
                ->whereNull('reverted_at')
                ->lockForUpdate()
                ->first();

            if ($veredicto === null || $veredicto->id !== $expectedVerdictId) {
                throw ValidationException::withMessages([
                    'reason' => 'El veredicto cambió mientras lo mirabas: revisá la multa actualizada antes de revertirlo.',
                ]);
            }

            $this->assertNadaDesdeElVeredicto($multa, $veredicto);

            $antes = (string) FineBalance::query()->whereKey($multa->id)->value('obligation');
            $recurso = FineAppeal::query()->whereKey($veredicto->fine_appeal_id)->lockForUpdate()->firstOrFail();

            $veredicto->forceFill([
                'reverted_at' => now(),
                'reverted_by' => $actorId,
                'revert_reason_code' => $motivo->code,
                'revert_reason' => $motivo->detail,
            ])->save();

            $recurso->forceFill(['status' => FineAppealStatus::Open])->save();

            $reduccion = FineDebtEntry::query()
                ->where('fine_appeal_verdict_id', $veredicto->id)
                ->where('kind', FineDebtEntryKind::Reduction)
                ->first();

            if ($reduccion !== null) {
                FineDebtEntry::query()->create([
                    'fine_id' => $multa->id,
                    'kind' => FineDebtEntryKind::Reversal,
                    'amount' => $reduccion->amount,
                    'effective_on' => BusinessDate::today()->toDateString(),
                    'effective_precision' => DatePrecision::Day,
                    'reason_code' => FineReasonCode::AppealVerdictReverted,
                    'reason' => $motivo->describe(),
                    'reversal_of_id' => $reduccion->id,
                    'fine_appeal_verdict_id' => $veredicto->id,
                    'provenance' => FineProvenance::Manual,
                    'created_by' => $actorId,
                ]);

                $this->reclasificar->handle($multa, 'Reversión del veredicto del recurso', $actorId);
            }

            $this->auditar->handle(
                'multa.veredicto-revertido',
                $multa,
                before: ['obligation' => $antes],
                after: ['obligation' => (string) FineBalance::query()->whereKey($multa->id)->value('obligation')],
                metadata: [
                    'veredicto' => $veredicto->verdict->label(),
                    ...$motivo->auditMetadata(),
                ],
                actorId: $actorId,
            );

            return $recurso;
        });
    }

    /** @throws ValidationException */
    private function assertNadaDesdeElVeredicto(Fine $multa, FineAppealVerdict $veredicto): void
    {
        $otroPago = FinePayment::query()->where('fine_id', $multa->id)->whereNull('fine_appeal_id')->live()->with('application')->first();

        if ($otroPago !== null) {
            throw ValidationException::withMessages(['reason' => $this->porElPago($otroPago)]);
        }

        $antecedente = $multa->historicalMovements()->where('status', '<>', HistoricalMovementStatus::Rejected)->first();

        if ($antecedente !== null) {
            throw ValidationException::withMessages([
                'reason' => $antecedente->status === HistoricalMovementStatus::Validated
                    ? 'Desde el veredicto se validó un antecedente, y su corrección todavía no está disponible: el veredicto no se puede revertir por ahora.'
                    : 'Desde el veredicto se cargó un antecedente: rechazalo y volvé a intentar.',
            ]);
        }

        if (DB::scalar('SELECT fine_amount_moved_since(?, ?)', [$multa->id, $veredicto->debt_entries_until_id])) {
            throw ValidationException::withMessages([
                'reason' => 'Desde el veredicto cambió el importe de la multa: revertí ese ajuste y volvé a intentar. Si fue la reversión de un ajuste anterior, no se puede deshacer, y el veredicto tampoco se puede revertir por ahora.',
            ]);
        }
    }

    private function porElPago(FinePayment $pago): string
    {
        if ($pago->status === FinePaymentStatus::Reported) {
            return 'Desde el veredicto se informó otro pago: descartalo y volvé a intentar.';
        }

        $recepcion = $pago->medium->isCounter() && $pago->application !== null
            ? FundReceipt::query()->find($pago->application->fund_receipt_id)
            : null;

        return $recepcion !== null && $this->depositos->isAtTheCounter($recepcion)
            ? 'Desde el veredicto se cobró otro pago en la caja: anulalo y volvé a intentar.'
            : 'Desde el veredicto entró otro pago ya depositado o verificado, y su corrección todavía no está disponible: el veredicto no se puede revertir por ahora.';
    }
}
