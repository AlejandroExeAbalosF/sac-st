<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Multas\Enums\FineImportIssue;
use App\Modules\Multas\Enums\FineImportStatus;
use App\Modules\Multas\Import\RowClassifier;
use App\Modules\Multas\Models\FineImportBatch;
use App\Modules\Multas\Models\FineImportRow;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Support\Money\Decimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Termina de leer un lote: agrega los cruces que una hoja sola no ve y deja la
 * conciliación por hoja.
 *
 * - **Repetida en la planilla:** la misma identidad, o el mismo texto si la
 *   identidad está incompleta, en más de una fila.
 * - **Ya importada:** la misma identidad que una fila consolidada de otro lote.
 *   Si nada cambió, se saltea; si cambió algo, bloquea y dice qué.
 * - **Ya cargada a mano:** la misma identidad que una multa que no vino de la planilla.
 * - **Posible coincidencia:** sin identidad completa, el mismo texto que una
 *   multa o un expediente ya cargados. No se decide solo.
 *
 * Solo agrega problemas: lo que dijo la planilla no cambia. La base lo repite.
 */
final class FinishFineImportReading
{
    public function __construct(private readonly RecordAuditEvent $auditar) {}

    public function handle(FineImportBatch $lote, ?int $actorId = null): void
    {
        DB::transaction(function () use ($lote, $actorId): void {
            $lote = FineImportBatch::query()->whereKey($lote->id)->lockForUpdate()->firstOrFail();

            if ($lote->status !== FineImportStatus::Reading) {
                return;
            }

            $filas = FineImportRow::query()
                ->where('fine_import_batch_id', $lote->id)
                ->where('kind', 'fine')
                ->orderBy('id')
                ->get(['id', 'sheet', 'row_number', 'identity_key', 'normalized', 'issues']);

            $agregar = [];
            $anteriores = [];

            $this->repetidas($filas, $agregar);
            $this->yaImportadas($lote, $filas, $agregar, $anteriores);
            $this->cargadasAMano($filas, $agregar);
            $this->posiblesCoincidencias($filas, $agregar);

            foreach ($agregar as $id => $problemas) {
                $fila = $filas->firstWhere('id', $id);

                if (! $fila instanceof FineImportRow) {
                    continue;
                }

                $fila->forceFill([
                    'issues' => [...$fila->issues, ...$problemas],
                    'previous_row_id' => $anteriores[$id] ?? null,
                ])->save();
            }

            $conciliacion = $this->conciliacion($lote);

            $lote->forceFill([
                'status' => FineImportStatus::Ready,
                'reconciliation' => $conciliacion,
            ])->save();

            $this->auditar->handle('multa.importacion-leida', $lote, metadata: [
                'filas' => array_sum(array_column($conciliacion, 'rows')),
                'bloqueadas' => array_sum(array_column($conciliacion, 'blocked')),
            ], actorId: $actorId);
        });
    }

    /**
     * @param  Collection<int, FineImportRow>  $filas
     * @param  array<int, list<array<string, mixed>>>  $agregar
     */
    private function repetidas(Collection $filas, array &$agregar): void
    {
        $grupos = [];

        foreach ($filas as $fila) {
            $clave = $fila->identity_key ?? 'texto:'.((string) ($fila->normalized['text_key'] ?? ''));
            $grupos[$clave][] = $fila;
        }

        foreach ($grupos as $grupo) {
            if (count($grupo) < 2) {
                continue;
            }

            $otras = array_map(fn (FineImportRow $f): array => ['id' => $f->id, 'sheet' => $f->sheet, 'row' => $f->row_number], $grupo);

            foreach ($grupo as $fila) {
                $agregar[$fila->id][] = $this->problema(FineImportIssue::DuplicateInFile, null, null,
                    'La misma multa aparece en '.count($grupo).' filas de la planilla: se importa una sola.',
                    ['rows' => array_values(array_filter($otras, fn (array $o): bool => $o['id'] !== $fila->id))]);
            }
        }
    }

    /**
     * @param  Collection<int, FineImportRow>  $filas
     * @param  array<int, list<array<string, mixed>>>  $agregar
     * @param  array<int, int>  $anteriores
     */
    private function yaImportadas(FineImportBatch $lote, Collection $filas, array &$agregar, array &$anteriores): void
    {
        $claves = $filas->pluck('identity_key')->filter()->unique()->values()->all();

        if ($claves === []) {
            return;
        }

        $previas = FineImportRow::query()
            ->whereIn('identity_key', $claves)
            ->whereNotNull('fine_id')
            ->whereNull('previous_row_id')
            ->where('fine_import_batch_id', '<>', $lote->id)
            ->get(['id', 'identity_key', 'normalized', 'fine_id', 'sheet', 'row_number'])
            ->keyBy('identity_key');

        foreach ($filas as $fila) {
            $previa = $fila->identity_key === null ? null : $previas->get($fila->identity_key);

            if (! $previa instanceof FineImportRow) {
                continue;
            }

            $anteriores[$fila->id] = $previa->id;
            $diferencias = $this->diferencias($previa->normalized, $fila->normalized);

            if ($diferencias['fields'] === [] && $diferencias['new_months'] === []) {
                $agregar[$fila->id][] = $this->problema(FineImportIssue::Unchanged, null, null,
                    "Ya se importó desde {$previa->sheet}, fila {$previa->row_number}, y no cambió: se saltea.", ['fine_id' => $previa->fine_id]);

                continue;
            }

            $agregar[$fila->id][] = $this->problema(FineImportIssue::Changed, null, null,
                "Ya se importó desde {$previa->sheet}, fila {$previa->row_number}, y cambió: ".$this->describir($diferencias).'.',
                ['fine_id' => $previa->fine_id, 'fields' => $diferencias['fields'], 'new_months' => $diferencias['new_months'], 'only_new_months' => $diferencias['fields'] === []]);
        }
    }

    /**
     * Lo que cambió entre la fila ya importada y la nueva. Un mes nuevo solo
     * cuenta si es anterior al corte: lo posterior queda pendiente de identificar.
     *
     * @param  array<string, mixed>  $antes
     * @param  array<string, mixed>  $ahora
     * @return array{fields: list<string>, new_months: list<array{month: string, amount: string}>}
     */
    private function diferencias(array $antes, array $ahora): array
    {
        $campos = [];

        foreach (['amount', 'caption', 'case_text', 'resolution_text', 'act_text', 'date'] as $campo) {
            if (($antes[$campo] ?? null) !== ($ahora[$campo] ?? null)) {
                $campos[] = $campo;
            }
        }

        $pagosAntes = $this->porMes($antes['payments'] ?? []);
        $nuevos = [];

        foreach ($this->porMes($ahora['payments'] ?? []) as $mes => $monto) {
            if (! isset($pagosAntes[$mes])) {
                $nuevos[] = ['month' => $mes, 'amount' => $monto];
            } elseif (! Decimal::equals($pagosAntes[$mes], $monto)) {
                $campos[] = 'payment:'.$mes;
            }

            unset($pagosAntes[$mes]);
        }

        foreach (array_keys($pagosAntes) as $mes) {
            $campos[] = 'payment:'.$mes;
        }

        return ['fields' => $campos, 'new_months' => $nuevos];
    }

    /**
     * @return array<string, numeric-string>
     */
    private function porMes(mixed $pagos): array
    {
        $porMes = [];

        foreach (is_array($pagos) ? $pagos : [] as $pago) {
            $mes = (string) $pago['month'];
            $porMes[$mes] = Decimal::add($porMes[$mes] ?? '0', (string) $pago['amount']);
        }

        return $porMes;
    }

    /**
     * @param  array{fields: list<string>, new_months: list<array{month: string, amount: string}>}  $diferencias
     */
    private function describir(array $diferencias): string
    {
        $nombres = ['amount' => 'el importe', 'caption' => 'la carátula', 'case_text' => 'el expediente',
            'resolution_text' => 'la resolución', 'act_text' => 'el acta', 'date' => 'la fecha'];
        $partes = [];

        foreach ($diferencias['fields'] as $campo) {
            $partes[] = str_starts_with($campo, 'payment:')
                ? 'el pago de '.substr($campo, 13, 2).'/'.substr($campo, 8, 4)
                : $nombres[$campo];
        }

        if ($diferencias['new_months'] !== []) {
            $partes[] = count($diferencias['new_months']).' mes(es) con pago nuevo';
        }

        return implode(', ', $partes);
    }

    /**
     * @param  Collection<int, FineImportRow>  $filas
     * @param  array<int, list<array<string, mixed>>>  $agregar
     */
    private function cargadasAMano(Collection $filas, array &$agregar): void
    {
        $existentes = [];

        foreach ($this->identidadesDeLaBase() as $multa) {
            $existentes[$multa->clave] = $multa;
        }

        foreach ($filas as $fila) {
            $multa = $fila->identity_key === null ? null : ($existentes[$fila->identity_key] ?? null);

            if ($multa === null || isset($agregar[$fila->id]) && $this->tiene($agregar[$fila->id], [FineImportIssue::Unchanged, FineImportIssue::Changed])) {
                continue;
            }

            $agregar[$fila->id][] = $this->problema(FineImportIssue::DuplicateManual, null, null,
                "Esta multa ya está cargada en el sistema: {$multa->caption}. No se importa: se puede vincular o excluir.",
                ['fine_id' => (int) $multa->id, 'caption' => (string) $multa->caption]);
        }
    }

    /**
     * Las multas que no vinieron de la planilla, por expediente y resolución completos.
     *
     * @return list<object{id: int, caption: string, clave: string}>
     */
    private function identidadesDeLaBase(): array
    {
        /** @var list<object{id: int, caption: string, clave: string}> */
        return DB::select(<<<'SQL'
            SELECT f.id, f.caption,
                   c.number || '/' || c.year || '/' || COALESCE(c.suffix, '') || '|' || d.number || '/' || d.year AS clave
              FROM fines f
              JOIN fine_cases c ON c.id = f.fine_case_id
              JOIN fine_document_links l ON l.fine_id = f.id AND l.role = 'origin' AND l.revoked_at IS NULL
              JOIN fine_documents d ON d.id = l.fine_document_id AND d.kind = 'resolution'
             WHERE c.number IS NOT NULL AND c.year IS NOT NULL AND d.number IS NOT NULL AND d.year IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM fine_import_rows r WHERE r.fine_id = f.id)
        SQL);
    }

    /**
     * @param  Collection<int, FineImportRow>  $filas
     * @param  array<int, list<array<string, mixed>>>  $agregar
     */
    private function posiblesCoincidencias(Collection $filas, array &$agregar): void
    {
        $sinIdentidad = $filas->whereNull('identity_key');

        if ($sinIdentidad->isEmpty()) {
            return;
        }

        $multas = [];

        foreach (DB::select(<<<'SQL'
            SELECT f.id, f.caption, c.original_text AS expediente, d.original_text AS resolucion
              FROM fines f
              JOIN fine_cases c ON c.id = f.fine_case_id
              LEFT JOIN fine_document_links l ON l.fine_id = f.id AND l.role = 'origin' AND l.revoked_at IS NULL
              LEFT JOIN fine_documents d ON d.id = l.fine_document_id AND d.kind = 'resolution'
        SQL) as $multa) {
            $multas[RowClassifier::textKey((string) $multa->expediente, (string) $multa->resolucion)][] = ['id' => (int) $multa->id, 'caption' => (string) $multa->caption];
        }

        $expedientes = [];

        foreach (DB::select('SELECT id, original_text FROM fine_cases') as $expediente) {
            $expedientes[RowClassifier::textKey((string) $expediente->original_text, '')][] = ['id' => (int) $expediente->id, 'label' => (string) $expediente->original_text];
        }

        foreach ($sinIdentidad as $fila) {
            $normal = $fila->normalized;
            $porMulta = $multas[(string) ($normal['text_key'] ?? '')] ?? [];
            $porExpediente = $expedientes[RowClassifier::textKey((string) ($normal['case_text'] ?? ''), '')] ?? [];

            if ($porMulta === [] && $porExpediente === []) {
                continue;
            }

            $agregar[$fila->id][] = $this->problema(FineImportIssue::PossibleMatch, null, null,
                $porMulta !== []
                    ? 'Sin expediente o resolución completos, hay una multa cargada con el mismo texto. Decidí si es el mismo expediente.'
                    : 'Sin expediente completo, hay un expediente cargado con el mismo texto. Decidí si es el mismo.',
                ['fines' => $porMulta, 'cases' => $porExpediente]);
        }
    }

    /**
     * La conciliación por hoja: lo que suma el detalle contra lo que dicen los pies de control.
     *
     * @return list<array<string, mixed>>
     */
    private function conciliacion(FineImportBatch $lote): array
    {
        $hojas = [];

        foreach (FineImportRow::query()->where('fine_import_batch_id', $lote->id)->orderBy('id')->cursor() as $fila) {
            $hoja = $hojas[$fila->sheet] ??= ['sheet' => $fila->sheet, 'rows' => 0, 'blocked' => 0, 'amount' => '0.00', 'paid' => '0.00', 'after_cutoff' => 0, 'footers' => []];

            if ($fila->kind === 'footer') {
                $hoja['footers'][] = [
                    'row' => $fila->row_number,
                    'amount' => $fila->normalized['amount'] ?? null,
                    'paid' => $fila->normalized['paid'] ?? '0.00',
                ];
            } else {
                $hoja['rows']++;
                $hoja['amount'] = Decimal::add($hoja['amount'], (string) ($fila->normalized['amount'] ?? '0'));
                $hoja['paid'] = Decimal::add($hoja['paid'], (string) ($fila->normalized['paid'] ?? '0'));
                $hoja['after_cutoff'] += count($fila->normalized['after_cutoff'] ?? []);
                $hoja['blocked'] += $fila->blockingIssues() === [] ? 0 : 1;
            }

            $hojas[$fila->sheet] = $hoja;
        }

        foreach ($hojas as $nombre => $hoja) {
            foreach ($hoja['footers'] as $i => $pie) {
                $hoja['footers'][$i]['amount_matches'] = $pie['amount'] !== null && Decimal::equals((string) $pie['amount'], $hoja['amount']);
                $hoja['footers'][$i]['paid_matches'] = Decimal::equals((string) $pie['paid'], $hoja['paid']);
            }

            $hojas[$nombre] = $hoja;
        }

        return array_values($hojas);
    }

    /**
     * @param  list<array<string, mixed>>  $problemas
     * @param  list<FineImportIssue>  $codigos
     */
    private function tiene(array $problemas, array $codigos): bool
    {
        foreach ($problemas as $problema) {
            foreach ($codigos as $codigo) {
                if ($problema['code'] === $codigo->value) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function problema(FineImportIssue $codigo, ?string $columna, ?string $valor, string $mensaje, array $meta = []): array
    {
        return [
            'code' => $codigo->value,
            'blocking' => $codigo->blocking(),
            'column' => $columna,
            'value' => $valor,
            'message' => $mensaje,
            'meta' => $meta,
        ];
    }
}
