<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Multas\Enums\DatePrecision;
use App\Modules\Multas\Enums\FineAppealStatus;
use App\Modules\Multas\Enums\FineDebtEntryKind;
use App\Modules\Multas\Enums\FinePaymentStatus;
use App\Modules\Multas\Enums\FineProvenance;
use App\Modules\Multas\Enums\FineReasonCode;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineAppeal;
use App\Modules\Multas\Models\FineBalance;
use App\Modules\Multas\Models\FineDebtEntry;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Support\BusinessDate;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * El veredicto del recurso (decisión del área, 03/11/2026).
 *
 * - **Prosperó:** la multa queda cancelada con lo cobrado según el recibo
 *   externo, no con el 20 % de referencia. Si es menos que la obligación,
 *   una reducción que cita el recurso la lleva exactamente a lo cobrado; si
 *   alcanza o sobra, no hay reducción y el excedente queda a la vista
 *   (M05). Nada se devuelve, y desde ahí el importe no se mueve.
 * - **No prosperó:** la multa se descongela y lo pagado queda a cuenta. Un
 *   depósito todavía informado sigue en su cola.
 *
 * No exige resolución: si llega una, se carga aparte en la multa. Revertir
 * un veredicto no está relevado.
 *
 * El veredicto no se deshace, así que se da sobre el recurso y el pago que
 * vio quien resuelve: si mientras tanto el recurso cayó y se registró otro,
 * o una corrección cambió el pago, se rechaza en vez de resolver lo que
 * nadie miró.
 */
final class ResolveFineAppeal
{
    public function __construct(
        private readonly ReconcileFineExcess $reclasificar,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /** @throws ValidationException */
    public function handle(Fine $multa, FineAppealStatus $veredicto, int $expectedAppealId, int $expectedPaymentId, ?int $actorId): FineAppeal
    {
        if (! $veredicto->isVerdict()) {
            throw new InvalidArgumentException('El recurso se resuelve prosperando o no.');
        }

        return DB::transaction(function () use ($multa, $veredicto, $expectedAppealId, $expectedPaymentId, $actorId): FineAppeal {
            $multa = Fine::query()->whereKey($multa->id)->lockForUpdate()->firstOrFail();
            $recurso = FineAppeal::query()
                ->where('fine_id', $multa->id)
                ->where('status', FineAppealStatus::Open)
                ->lockForUpdate()
                ->first();

            if ($recurso === null) {
                throw ValidationException::withMessages(['verdict' => 'La multa no tiene un recurso en trámite.']);
            }

            $pago = $recurso->payments()->where('status', '<>', FinePaymentStatus::Discarded)->sole();

            if ($recurso->id !== $expectedAppealId || $pago->id !== $expectedPaymentId) {
                throw ValidationException::withMessages([
                    'verdict' => 'El recurso cambió mientras lo mirabas: revisá la multa actualizada antes de resolverlo.',
                ]);
            }

            if ($veredicto === FineAppealStatus::Upheld && $pago->status !== FinePaymentStatus::Applied) {
                throw ValidationException::withMessages([
                    'verdict' => 'El 20 % todavía no se verificó: el recurso prospera con lo cobrado.',
                ]);
            }

            $recurso->forceFill(['status' => $veredicto, 'resolved_at' => now(), 'resolved_by' => $actorId])->save();

            $saldo = FineBalance::query()->whereKey($multa->id)->firstOrFail();
            $cobrado = Decimal::add($saldo->operational_paid, $saldo->historical_paid);
            $falta = Decimal::sub($saldo->obligation, $cobrado);
            $reduccion = $veredicto === FineAppealStatus::Upheld && ! Decimal::isNegative($falta) && ! Decimal::equals($falta, '0')
                ? $falta
                : null;

            if ($reduccion !== null) {
                FineDebtEntry::query()->create([
                    'fine_id' => $multa->id,
                    'kind' => FineDebtEntryKind::Reduction,
                    'amount' => $reduccion,
                    'effective_on' => BusinessDate::today()->toDateString(),
                    'effective_precision' => DatePrecision::Day,
                    'reason_code' => FineReasonCode::AppealAdmitted,
                    'fine_appeal_id' => $recurso->id,
                    'provenance' => FineProvenance::Manual,
                    'created_by' => $actorId,
                ]);

                $this->reclasificar->handle($multa, 'Reducción de la obligación por el recurso que prosperó', $actorId);
            }

            $this->auditar->handle(
                'multa.recurso-resuelto',
                $multa,
                before: ['obligation' => $saldo->obligation],
                after: ['obligation' => (string) FineBalance::query()->whereKey($multa->id)->value('obligation')],
                metadata: array_filter([
                    'veredicto' => $veredicto->label(),
                    'cobrado' => $cobrado,
                    'reduccion' => $reduccion,
                ]),
                actorId: $actorId,
            );

            return $recurso;
        });
    }
}
