<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Multas\Enums\FineReasonCode;
use App\Modules\Multas\Enums\HistoricalMovementKind;
use App\Modules\Multas\Enums\HistoricalMovementStatus;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineHistoricalMovement;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Support\Reason;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Valida o descarta un antecedente, una sola vez.
 *
 * Validarlo lo hace contar en el saldo de la multa. Descartarlo dice por
 * qué —es de otra multa, está duplicado— y lo deja a la vista. Ninguno de
 * los dos toca el libro, salvo que cambie cuánto de lo cobrado en el sistema
 * es excedente: el antecedente consume la obligación primero.
 *
 * Uno importado entra provisional y ya cuenta: validarlo no cambia el saldo, y
 * descartarlo sí. Descartar un pago no puede dejar sin cobertura una
 * devolución que cuenta.
 */
final class ReviewFineHistoricalMovement
{
    public function __construct(
        private readonly ReconcileFineExcess $reclasificar,
        private readonly RecordAuditEvent $auditar,
    ) {}

    public function handle(
        FineHistoricalMovement $antecedente,
        HistoricalMovementStatus $resultado,
        ?string $note,
        ?int $actorId,
        ?FineReasonCode $rejectionReason = null,
    ): FineHistoricalMovement {
        if (! in_array($resultado, [HistoricalMovementStatus::Validated, HistoricalMovementStatus::Rejected], true)) {
            throw new InvalidArgumentException('Revisar es validar o descartar.');
        }

        $nota = trim((string) $note);

        // Descartar elige el motivo de la lista y la nota es su detalle.
        if ($resultado === HistoricalMovementStatus::Rejected && $rejectionReason === null) {
            throw ValidationException::withMessages(['reasonCode' => 'Elegí por qué se descarta el antecedente.']);
        }

        if ($rejectionReason === FineReasonCode::Other && $nota === '') {
            throw ValidationException::withMessages(['note' => 'Con «Otro», contá el motivo en el detalle.']);
        }

        $codigo = $resultado === HistoricalMovementStatus::Rejected ? $rejectionReason : null;

        return DB::transaction(function () use ($antecedente, $resultado, $nota, $codigo, $actorId): FineHistoricalMovement {
            $fila = FineHistoricalMovement::query()->whereKey($antecedente->id)->lockForUpdate()->firstOrFail();

            if (! $fila->status->isReviewable()) {
                throw ValidationException::withMessages(['note' => 'Ese antecedente ya se revisó.']);
            }

            $contaba = $fila->status->counts();

            if ($resultado === HistoricalMovementStatus::Validated && $fila->kind === HistoricalMovementKind::Refund && ! $contaba) {
                $this->assertRefundCovered($fila);
            }

            if ($resultado === HistoricalMovementStatus::Rejected && $fila->kind === HistoricalMovementKind::Payment && $contaba) {
                $this->assertRefundsStayCovered($fila);
            }

            $fila->update([
                'status' => $resultado,
                'reviewed_by' => $actorId,
                'reviewed_at' => now(),
                'review_reason_code' => $codigo,
                'review_note' => $nota === '' ? null : $nota,
            ]);

            // Solo si cambió lo que cuenta: validar un provisional no vuelve a descontarlo.
            if ($contaba !== $resultado->counts()) {
                $this->reclasificar->handle(
                    Fine::query()->whereKey($fila->fine_id)->lockForUpdate()->firstOrFail(),
                    $resultado === HistoricalMovementStatus::Validated
                        ? 'Validación de un pago anterior al sistema'
                        : 'Descarte de un pago anterior al sistema que contaba',
                    $actorId,
                );
            }

            $this->auditar->handle(
                $resultado === HistoricalMovementStatus::Validated ? 'multa.antecedente-validado' : 'multa.antecedente-descartado',
                Fine::query()->findOrFail($fila->fine_id),
                metadata: array_filter([
                    'amount' => $fila->amount,
                    'respaldo' => $fila->evidence_text,
                    'reason' => Reason::text($codigo, $fila->review_note),
                    'motivo_codigo' => $codigo?->value,
                ]),
                actorId: $actorId,
            );

            return $fila;
        });
    }

    /**
     * Una devolución devuelve algo que se pagó (revisión del 2026-10-20, R1).
     *
     * Validarla sin pagos anteriores validados que la cubran haría que la
     * multa deba más que su obligación sin ninguna resolución que la aumente.
     * Queda pendiente hasta que se valide el pago que devuelve. La base lo
     * impone igual (`fine_historical_refund_covered`); esto lo dice en
     * castellano, con la multa bloqueada como la base.
     */
    private function assertRefundCovered(FineHistoricalMovement $devolucion): void
    {
        Fine::query()->whereKey($devolucion->fine_id)->lockForUpdate()->first();

        $neto = $this->netoQueCuenta($devolucion->fine_id, null);

        if (Decimal::isNegative(Decimal::sub($neto, $devolucion->amount))) {
            throw ValidationException::withMessages([
                'note' => sprintf(
                    'La devolución de $ %s supera lo pagado antes del sistema que cuenta ($ %s). Queda pendiente hasta que se valide el pago que devuelve.',
                    Decimal::format($devolucion->amount),
                    Decimal::format($neto),
                ),
            ]);
        }
    }

    /** Descartar un pago que cuenta no puede dejar una devolución que cuenta sin lo que devuelve. La base lo repite. */
    private function assertRefundsStayCovered(FineHistoricalMovement $pago): void
    {
        Fine::query()->whereKey($pago->fine_id)->lockForUpdate()->first();

        if (Decimal::isNegative($this->netoQueCuenta($pago->fine_id, $pago->id))) {
            throw ValidationException::withMessages([
                'note' => 'Descartar este pago deja sin cobertura una devolución que cuenta: descartá primero la devolución.',
            ]);
        }
    }

    /** Lo pagado antes del sistema que cuenta, neto de devoluciones, sin contar uno. */
    private function netoQueCuenta(int $multaId, ?int $sin): string
    {
        $neto = '0.00';

        $cuentan = FineHistoricalMovement::query()
            ->where('fine_id', $multaId)
            ->whereIn('status', [HistoricalMovementStatus::Validated, HistoricalMovementStatus::Provisional])
            ->when($sin !== null, fn ($consulta) => $consulta->whereKeyNot($sin))
            ->get(['kind', 'amount']);

        foreach ($cuentan as $antecedente) {
            $neto = $antecedente->kind === HistoricalMovementKind::Payment
                ? Decimal::add($neto, $antecedente->amount)
                : Decimal::sub($neto, $antecedente->amount);
        }

        return $neto;
    }
}
