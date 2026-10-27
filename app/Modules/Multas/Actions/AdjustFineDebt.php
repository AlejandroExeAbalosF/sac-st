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
 * Sube o baja la obligación por lo que dispone una resolución (M04).
 *
 * El importe original no se toca: el ajuste es otra fila, con su documento,
 * su fecha y su motivo. Los pagos ya hechos no cambian; si la obligación
 * queda por debajo de lo pagado, la deuda es cero y lo demás es excedente;
 * si eso cambia cuánto de lo cobrado en el sistema es excedente, el libro se
 * reclasifica en la misma transacción (M05).
 *
 * La resolución que lo respalda queda vinculada a la multa como
 * «modificación», si todavía no lo estaba: es lo que exige la base para
 * citarla.
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
        FineDebtEntryKind $tipo,
        string $amount,
        CarbonImmutable $effectiveOn,
        FineDocument|NewDocument $documento,
        Reason $reason,
        ?int $actorId,
    ): FineDebtEntry {
        if (! in_array($tipo, [FineDebtEntryKind::Increase, FineDebtEntryKind::Reduction], true)) {
            throw new InvalidArgumentException('Un ajuste aumenta o reduce la obligación.');
        }

        $importe = Decimal::scale($amount);

        return DB::transaction(function () use ($multa, $tipo, $importe, $effectiveOn, $documento, $reason, $actorId): FineDebtEntry {
            // Dos ajustes simultáneos no leen la misma obligación.
            Fine::query()->whereKey($multa->id)->lockForUpdate()->first();

            $multa->loadMissing('fineCase');

            $respaldo = $documento instanceof NewDocument
                ? $this->registrarDocumento->handle($multa->fineCase, $documento, $actorId, 'document.text')
                : $documento;

            if ($respaldo->kind !== FineDocumentKind::Resolution) {
                throw ValidationException::withMessages(['documentId' => 'Un ajuste lo dispone una resolución.']);
            }

            if (! $multa->fineCase->documents()->whereKey($respaldo->id)->exists()) {
                throw ValidationException::withMessages(['documentId' => 'Esa resolución no pertenece al expediente de la multa.']);
            }

            $obligacion = (string) FineBalance::query()->whereKey($multa->id)->value('obligation');

            if ($tipo === FineDebtEntryKind::Reduction && Decimal::isNegative(Decimal::sub($obligacion, $importe))) {
                throw ValidationException::withMessages([
                    'amount' => sprintf(
                        'La obligación es de $ %s: una reducción de $ %s la dejaría negativa. Para dejarla sin efecto, reducila hasta cero.',
                        Decimal::format($obligacion),
                        Decimal::format($importe),
                    ),
                ]);
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

            $ajuste = FineDebtEntry::query()->create([
                'fine_id' => $multa->id,
                'kind' => $tipo,
                'amount' => $importe,
                'effective_on' => $effectiveOn->toDateString(),
                'effective_precision' => DatePrecision::Day,
                'fine_document_id' => $respaldo->id,
                'reason_code' => $reason->code,
                'reason' => $reason->detail,
                'provenance' => FineProvenance::Manual,
                'created_by' => $actorId,
            ]);

            $this->reclasificar->handle($multa, sprintf('%s de la obligación por %s', $tipo->label(), $respaldo->label()), $actorId);

            $this->auditar->handle(
                'multa.ajuste-registrado',
                $multa,
                before: ['obligation' => $obligacion],
                after: ['obligation' => (string) FineBalance::query()->whereKey($multa->id)->value('obligation')],
                metadata: [
                    'tipo' => $tipo->label(),
                    'amount' => $importe,
                    'resolucion' => $respaldo->label(),
                    'reason' => $reason->describe(),
                ],
                actorId: $actorId,
            );

            return $ajuste;
        });
    }
}
