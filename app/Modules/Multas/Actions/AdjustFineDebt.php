<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Multas\Enums\DatePrecision;
use App\Modules\Multas\Enums\FineDebtEntryKind;
use App\Modules\Multas\Enums\FineDocumentKind;
use App\Modules\Multas\Enums\FineDocumentRole;
use App\Modules\Multas\Enums\FineProvenance;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineBalance;
use App\Modules\Multas\Models\FineDebtEntry;
use App\Modules\Multas\Models\FineDocument;
use App\Modules\Multas\Support\NewDocument;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Support\Reason;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Lleva la obligación al importe que corresponde (M04).
 *
 * Se pide el importe nuevo, no la diferencia: el aumento o la reducción y su
 * monto salen de comparar con el vigente, con la multa bloqueada. El importe
 * que vio el formulario tiene que seguir siendo el vigente; si otro lo cambió
 * mientras tanto, se rechaza en vez de aplicar una diferencia pensada sobre
 * otro número.
 *
 * Hay dos vías, y las dos dejan otra fila: el importe original no se toca.
 *
 * - **Con resolución**, la vía normal: la resolución queda vinculada a la
 *   multa como «modificación», si todavía no lo estaba, que es lo que exige
 *   la base para citarla.
 * - **Sin documento**, la corrección de un importe mal cargado: el área no
 *   la respalda con un papel (confirmado el 28/10/2026), así que la explica
 *   el motivo, con detalle obligatorio. Quién puede hacerla lo decide la
 *   ruta (`multas.corregir-importe`); la base exige el motivo de su lista.
 *
 * Los pagos ya hechos no cambian; si la obligación queda por debajo de lo
 * pagado, la deuda es cero y lo demás es excedente; si eso cambia cuánto de
 * lo cobrado en el sistema es excedente, el libro se reclasifica en la misma
 * transacción (M05).
 */
final class AdjustFineDebt
{
    public function __construct(
        private readonly AttachFineDocument $registrarDocumento,
        private readonly ReconcileFineExcess $reclasificar,
        private readonly RecordAuditEvent $auditar,
    ) {}

    public function handle(
        Fine $multa,
        string $newObligation,
        string $expectedObligation,
        CarbonImmutable $effectiveOn,
        FineDocument|NewDocument|null $documento,
        Reason $reason,
        ?int $actorId,
    ): FineDebtEntry {
        $nueva = Decimal::scale($newObligation);

        if (Decimal::isNegative($nueva)) {
            throw new InvalidArgumentException('La obligación no puede ser negativa.');
        }

        return DB::transaction(function () use ($multa, $nueva, $expectedObligation, $effectiveOn, $documento, $reason, $actorId): FineDebtEntry {
            // Dos ajustes simultáneos no leen la misma obligación.
            Fine::query()->whereKey($multa->id)->lockForUpdate()->first();

            $multa->loadMissing('fineCase');

            $obligacion = (string) FineBalance::query()->whereKey($multa->id)->value('obligation');

            if (! Decimal::equals($obligacion, $expectedObligation)) {
                throw ValidationException::withMessages([
                    'newAmount' => sprintf('El importe de la multa cambió mientras cargabas: ahora es $ %s. Revisá el nuevo importe.', Decimal::format($obligacion)),
                ]);
            }

            if (Decimal::equals($obligacion, $nueva)) {
                throw ValidationException::withMessages(['newAmount' => 'Es el mismo importe que la multa ya tiene.']);
            }

            $diferencia = Decimal::sub($nueva, $obligacion);
            $tipo = Decimal::isNegative($diferencia) ? FineDebtEntryKind::Reduction : FineDebtEntryKind::Increase;
            $importe = Decimal::abs($diferencia);

            $respaldo = $documento instanceof NewDocument
                ? $this->registrarDocumento->handle($multa->fineCase, $documento, $actorId, 'document.text')
                : $documento;

            if ($respaldo !== null) {
                if ($respaldo->kind !== FineDocumentKind::Resolution) {
                    throw ValidationException::withMessages(['documentId' => 'Un ajuste lo dispone una resolución.']);
                }

                if (! $multa->fineCase->documents()->whereKey($respaldo->id)->exists()) {
                    throw ValidationException::withMessages(['documentId' => 'Esa resolución no pertenece al expediente de la multa.']);
                }

                $vinculado = $multa->documents()
                    ->whereKey($respaldo->id)
                    ->wherePivot('role', FineDocumentRole::Modification->value)
                    ->exists();

                if (! $vinculado) {
                    $multa->documents()->attach($respaldo->id, [
                        'fine_case_id' => $multa->fine_case_id,
                        'role' => FineDocumentRole::Modification->value,
                        'created_by' => $actorId,
                    ]);
                }
            } elseif ($reason->detail === null) {
                throw ValidationException::withMessages(['reason' => 'Contá en el detalle qué estaba mal cargado.']);
            }

            $ajuste = FineDebtEntry::query()->create([
                'fine_id' => $multa->id,
                'kind' => $tipo,
                'amount' => $importe,
                'effective_on' => $effectiveOn->toDateString(),
                'effective_precision' => DatePrecision::Day,
                'fine_document_id' => $respaldo?->id,
                'reason_code' => $reason->code,
                'reason' => $reason->detail,
                'provenance' => FineProvenance::Manual,
                'created_by' => $actorId,
            ]);

            $this->reclasificar->handle(
                $multa,
                $respaldo !== null
                    ? sprintf('%s de la obligación por %s', $tipo->label(), $respaldo->label())
                    : sprintf('Corrección del importe de la multa (%s)', mb_strtolower($tipo->label())),
                $actorId,
            );

            $this->auditar->handle(
                $respaldo !== null ? 'multa.ajuste-registrado' : 'multa.importe-corregido',
                $multa,
                before: ['obligation' => $obligacion],
                after: ['obligation' => (string) FineBalance::query()->whereKey($multa->id)->value('obligation')],
                metadata: [
                    'tipo' => $tipo->label(),
                    'amount' => $importe,
                    ...($respaldo !== null ? ['resolucion' => $respaldo->label()] : []),
                    ...$reason->auditMetadata(),
                ],
                actorId: $actorId,
            );

            return $ajuste;
        });
    }
}
