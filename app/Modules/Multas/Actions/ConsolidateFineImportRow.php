<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Multas\Enums\DatePrecision;
use App\Modules\Multas\Enums\FineDebtEntryKind;
use App\Modules\Multas\Enums\FineDocumentKind;
use App\Modules\Multas\Enums\FineDocumentRole;
use App\Modules\Multas\Enums\FineImportAction;
use App\Modules\Multas\Enums\FineImportCellStatus;
use App\Modules\Multas\Enums\FineImportIssue;
use App\Modules\Multas\Enums\FineProvenance;
use App\Modules\Multas\Enums\HistoricalMovementKind;
use App\Modules\Multas\Enums\HistoricalMovementStatus;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineCase;
use App\Modules\Multas\Models\FineDebtEntry;
use App\Modules\Multas\Models\FineDocument;
use App\Modules\Multas\Models\FineHistoricalMovement;
use App\Modules\Multas\Models\FineImportCell;
use App\Modules\Multas\Models\FineImportResolution;
use App\Modules\Multas\Models\FineImportRow;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Lleva una fila de la planilla al sistema, en su transacción.
 *
 * Crea el expediente —el que ya existe con esa identidad, o uno importado—, la
 * resolución y el acta con su texto original, la multa sin persona, la
 * constitución por el importe y un pago **provisional** por cada mes anterior
 * al corte. Los meses desde la apertura quedan pendientes de identificar. **Sin
 * asientos, recibos ni movimientos de caja**: es historia, no recaudación.
 *
 * Una fila vinculada a una multa cargada a mano no crea nada. Una fila que
 * agrega meses a una multa ya importada suma solo esos meses.
 *
 * Un expediente sin año se reconoce por su texto dentro del mismo lote: la
 * planilla lo escribió igual, así que es el mismo. Entre lotes, o contra lo
 * cargado a mano, solo lo decide una persona (posible coincidencia).
 */
final class ConsolidateFineImportRow
{
    public function __construct(private readonly ReconcileFineExcess $reclasificar) {}

    /** Devuelve si consolidó algo. */
    public function handle(FineImportRow $fila, ?int $actorId): bool
    {
        return DB::transaction(function () use ($fila, $actorId): bool {
            $fila = FineImportRow::query()->with('batch')->whereKey($fila->id)->lockForUpdate()->firstOrFail();

            if ($fila->fine_id !== null || $fila->kind !== 'fine') {
                return false;
            }

            $decisiones = $fila->resolutions()->get()->keyBy(fn (FineImportResolution $r): string => $r->issue_code->value);

            if ($decisiones->contains(fn (FineImportResolution $r): bool => $r->action === FineImportAction::Exclude)) {
                return false;
            }

            $enlace = $decisiones->first(fn (FineImportResolution $r): bool => $r->action === FineImportAction::Link);

            if ($enlace instanceof FineImportResolution) {
                $this->marcar($fila, (int) $enlace->fine_id, $actorId);

                return true;
            }

            // Ya importada: sin cambios se saltea; con meses nuevos, se suman.
            if ($fila->previous_row_id !== null) {
                if (! $decisiones->has(FineImportIssue::Changed->value)) {
                    return false;
                }

                $multaId = (int) FineImportRow::query()->whereKey($fila->previous_row_id)->value('fine_id');
                $multa = Fine::query()->whereKey($multaId)->lockForUpdate()->firstOrFail();
                $this->marcar($fila, $multa->id, $actorId);
                $this->pagos($fila, $multa, $actorId, soloNuevos: true);
                $this->pendientes($fila);
                $this->reclasificar->handle($multa, 'Meses agregados desde la planilla histórica', $actorId);

                return true;
            }

            $normal = $fila->normalized;
            $expediente = $this->expediente($fila, $decisiones->get(FineImportIssue::PossibleMatch->value), $actorId);
            $resolucion = $this->documento($expediente, FineDocumentKind::Resolution, $normal['resolution_text'] ?? '', $normal['resolution'] ?? null, $normal['date'] ?? null, $actorId);
            $acta = $this->documento($expediente, FineDocumentKind::Act, $normal['act_text'] ?? '', $normal['act'] ?? null, null, $actorId);
            $fecha = $normal['date'] ?? null;

            $multa = Fine::query()->create([
                'fine_case_id' => $expediente->id,
                'person_id' => null,
                'caption_original' => mb_substr((string) $normal['caption'], 0, 300),
                'caption' => mb_substr((string) $normal['caption'], 0, 300),
                'registered_on' => $fecha,
                'registered_precision' => $fecha === null ? DatePrecision::Unknown : DatePrecision::Day,
                'provenance' => FineProvenance::Import,
                'created_by' => $actorId,
            ]);

            foreach (array_filter([$resolucion, $acta]) as $documento) {
                $multa->documents()->attach($documento->id, [
                    'fine_case_id' => $expediente->id,
                    'role' => FineDocumentRole::Origin->value,
                    'created_by' => $actorId,
                ]);
            }

            FineDebtEntry::query()->create([
                'fine_id' => $multa->id,
                'kind' => FineDebtEntryKind::Constitution,
                'amount' => (string) $normal['amount'],
                'effective_on' => $fecha,
                'effective_precision' => $fecha === null ? DatePrecision::Unknown : DatePrecision::Day,
                'fine_document_id' => $resolucion?->id,
                'provenance' => FineProvenance::Import,
                'created_by' => $actorId,
            ]);

            $this->marcar($fila, $multa->id, $actorId);

            if (! $decisiones->contains(fn (FineImportResolution $r): bool => $r->action === FineImportAction::WithoutPayments)) {
                $this->pagos($fila, $multa, $actorId, soloNuevos: false);
            }

            $this->pendientes($fila);

            return true;
        });
    }

    private function marcar(FineImportRow $fila, int $multaId, ?int $actorId): void
    {
        $fila->forceFill([
            'fine_id' => $multaId,
            'consolidated_by' => $actorId,
            'consolidated_at' => now(),
            'failure' => null,
        ])->save();
    }

    private function expediente(FineImportRow $fila, ?FineImportResolution $coincidencia, ?int $actorId): FineCase
    {
        if ($coincidencia?->action === FineImportAction::SameCase) {
            return FineCase::query()->findOrFail((int) $coincidencia->fine_case_id);
        }

        $normal = $fila->normalized;
        $texto = trim((string) $normal['case_text']);
        $leido = $normal['case'] ?? null;

        if (is_array($leido) && $leido['year'] !== null) {
            $existente = FineCase::query()
                ->where('number', $leido['number'])
                ->where('year', $leido['year'])
                ->when($leido['suffix'] === null, fn ($q) => $q->whereNull('suffix'), fn ($q) => $q->where('suffix', $leido['suffix']))
                ->first();

            if ($existente instanceof FineCase) {
                return $existente;
            }
        } elseif ($coincidencia?->action !== FineImportAction::NewCase) {
            // Sin año: el mismo texto dentro del mismo lote es el mismo expediente.
            $mismoLote = FineImportRow::query()
                ->join('fines', 'fines.id', '=', 'fine_import_rows.fine_id')
                ->where('fine_import_rows.fine_import_batch_id', $fila->fine_import_batch_id)
                ->whereNotNull('fine_import_rows.fine_id')
                ->whereRaw("fine_import_rows.normalized->>'case_text' = ?", [$normal['case_text']])
                ->value('fines.fine_case_id');

            if ($mismoLote !== null) {
                return FineCase::query()->findOrFail((int) $mismoLote);
            }
        }

        return FineCase::query()->create([
            'original_text' => mb_substr($texto, 0, 60),
            'number' => is_array($leido) ? $leido['number'] : null,
            'year' => is_array($leido) ? $leido['year'] : null,
            'suffix' => is_array($leido) ? $leido['suffix'] : null,
            'provenance' => FineProvenance::Import,
            'created_by' => $actorId,
        ]);
    }

    /**
     * @param  array{number: int, year: int|null, type: string|null}|null  $leido
     */
    private function documento(FineCase $expediente, FineDocumentKind $tipo, string $texto, ?array $leido, ?string $fecha, ?int $actorId): ?FineDocument
    {
        $texto = trim($texto);

        if ($texto === '') {
            return null;
        }

        $documento = FineDocument::query()->create([
            'kind' => $tipo,
            'act_type' => $tipo === FineDocumentKind::Act ? ($leido['type'] ?? null) : null,
            'number' => $leido['number'] ?? null,
            'year' => $leido['year'] ?? null,
            'original_text' => mb_substr($texto, 0, 120),
            'issued_on' => $fecha,
            'issued_precision' => $fecha === null ? DatePrecision::Unknown : DatePrecision::Day,
            'provenance' => FineProvenance::Import,
            'created_by' => $actorId,
        ]);

        $expediente->documents()->attach($documento->id, ['created_by' => $actorId]);

        return $documento;
    }

    /**
     * Un pago provisional por mes. Si la planilla repite un mes en dos columnas,
     * se suman: el mes entra una vez, y la base lo repite.
     */
    private function pagos(FineImportRow $fila, Fine $multa, ?int $actorId, bool $soloNuevos): void
    {
        $porMes = [];

        foreach ($fila->normalized['payments'] ?? [] as $pago) {
            $mes = (string) $pago['month'];
            $porMes[$mes]['amount'] = Decimal::add($porMes[$mes]['amount'] ?? '0', (string) $pago['amount']);
            $porMes[$mes]['columns'][] = (string) $pago['column'];
        }

        $yaCargados = $soloNuevos
            ? FineHistoricalMovement::query()
                ->where('fine_id', $multa->id)
                ->where('provenance', FineProvenance::Import)
                ->where('status', '<>', HistoricalMovementStatus::Rejected)
                ->pluck('occurred_on')
                ->map(fn ($fecha): string => substr((string) $fecha, 0, 10))
                ->all()
            : [];

        ksort($porMes);

        foreach ($porMes as $mes => $pago) {
            if (in_array($mes, $yaCargados, true)) {
                continue;
            }

            FineHistoricalMovement::query()->create([
                'fine_id' => $multa->id,
                'kind' => HistoricalMovementKind::Payment,
                'amount' => $pago['amount'],
                'occurred_on' => $mes,
                'occurred_precision' => DatePrecision::Month,
                'evidence_text' => sprintf('Planilla %s, fila %d, %s %s (%s)', $fila->sheet, $fila->row_number,
                    count($pago['columns']) > 1 ? 'columnas' : 'columna', implode(' y ', $pago['columns']), substr($mes, 5, 2).'/'.substr($mes, 0, 4)),
                'status' => HistoricalMovementStatus::Provisional,
                'provenance' => FineProvenance::Import,
                'import_row_id' => $fila->id,
                'created_by' => $actorId,
            ]);
        }
    }

    private function pendientes(FineImportRow $fila): void
    {
        foreach ($fila->normalized['after_cutoff'] ?? [] as $celda) {
            FineImportCell::query()->firstOrCreate(
                ['fine_import_row_id' => $fila->id, 'column' => (string) $celda['column']],
                ['month' => (string) $celda['month'], 'amount' => (string) $celda['amount'], 'status' => FineImportCellStatus::Pending],
            );
        }
    }
}
