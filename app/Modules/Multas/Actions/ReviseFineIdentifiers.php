<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Multas\Enums\FineRevisionKind;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineCase;
use App\Modules\Multas\Models\FineDebtEntry;
use App\Modules\Multas\Models\FineDocument;
use App\Modules\Multas\Models\FineHistoricalMovement;
use App\Modules\Multas\Models\FineIdentifierRevision;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Support\Reason;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Completa o corrige los datos normalizados de un expediente, un documento,
 * una multa, o la fecha de un movimiento de deuda o de un pago anterior.
 *
 * Nunca escribe en la tabla: inserta una revisión, y la revisión se aplica
 * sola desde la base (`fine_identifier_revision_apply`). El texto del papel
 * no cambia nunca; lo que se revisa es su lectura.
 *
 * - **Completar** es llenar un dato vacío o precisar una fecha dentro del
 *   mismo mes. No pide motivo: es terminar de cargar.
 * - **Corregir** es cambiar un dato que ya tenía valor, o volver menos
 *   precisa una fecha. Pide motivo y el permiso de ajustar (DER físico
 *   v1.1, §3.0).
 *
 * **Lo que vio el formulario.** Cada campo se compara contra el valor que el
 * operador tenía en pantalla (`$esperados`), no contra el que hay ahora en la
 * base: un campo que el operador no tocó no entra en la revisión aunque otro
 * lo haya cambiado mientras tanto, y uno que sí tocó lleva como valor
 * anterior el que vio. Si ese valor ya no es el actual, otro operador lo
 * cambió primero y la revisión se rechaza en vez de pisarlo (revisión del
 * 2026-10-20, R3).
 */
final class ReviseFineIdentifiers
{
    /**
     * Los datos revisables de cada tabla, con su tipo en JSON.
     *
     * @var array<string, array<string, 'int'|'string'>>
     */
    private const CAMPOS = [
        'fine_cases' => ['number' => 'int', 'year' => 'int', 'suffix' => 'string'],
        'fine_documents' => [
            'act_type' => 'string', 'number' => 'int', 'year' => 'int',
            'issued_on' => 'string', 'issued_precision' => 'string',
        ],
        'fines' => [
            'caption' => 'string', 'person_id' => 'int',
            'registered_on' => 'string', 'registered_precision' => 'string',
        ],
        'fine_debt_entries' => ['effective_on' => 'string', 'effective_precision' => 'string'],
        'fine_historical_movements' => ['occurred_on' => 'string', 'occurred_precision' => 'string'],
    ];

    /** Los prefijos de las fechas con precisión: se revisan como un par. */
    private const FECHAS = ['issued', 'registered', 'effective', 'occurred'];

    public function __construct(private readonly RecordAuditEvent $auditar) {}

    /**
     * @param  array<string, mixed>  $cambios  Dato normalizado → valor nuevo.
     * @param  array<string, mixed>  $esperados  Dato normalizado → valor que mostraba el formulario.
     * @param  bool  $puedeCorregir  Si quien lo pide tiene `multas.ajustar`.
     */
    public function handle(
        FineCase|FineDocument|Fine|FineDebtEntry|FineHistoricalMovement $sujeto,
        array $cambios,
        array $esperados,
        ?Reason $reason,
        bool $puedeCorregir,
        ?int $actorId,
    ): ?FineIdentifierRevision {
        $tabla = $sujeto->getTable();
        $campos = self::CAMPOS[$tabla];

        if (array_diff_key($cambios, $campos) !== []) {
            throw new InvalidArgumentException('Esos datos no se revisan: '.implode(', ', array_keys(array_diff_key($cambios, $campos))));
        }

        if (array_diff_key($cambios, $esperados) !== []) {
            throw new InvalidArgumentException('Falta lo que mostraba el formulario: '.implode(', ', array_keys(array_diff_key($cambios, $esperados))));
        }

        return DB::transaction(function () use ($sujeto, $cambios, $esperados, $reason, $puedeCorregir, $actorId, $tabla, $campos): ?FineIdentifierRevision {
            $actual = $this->actual($tabla, (int) $sujeto->getKey());

            [$anteriores, $nuevos] = $this->diferencias($cambios, $esperados, $campos);

            if ($nuevos === []) {
                return null;
            }

            $this->assertNotStale($anteriores, $actual);
            $this->assertManualStaysComplete($sujeto, $actual, $nuevos);

            $tipo = $this->esCompletar($anteriores, $nuevos) ? FineRevisionKind::Completion : FineRevisionKind::Correction;

            if ($tipo === FineRevisionKind::Correction) {
                if (! $puedeCorregir) {
                    throw new AuthorizationException('Corregir un dato ya cargado requiere el permiso de ajustar multas.');
                }

                if ($reason === null) {
                    throw ValidationException::withMessages(['reasonCode' => 'Corregir un dato ya cargado requiere elegir el motivo.']);
                }
            }

            // Completar no lleva motivo aunque el formulario mande uno.
            $motivo = $tipo === FineRevisionKind::Correction ? $reason : null;

            $revision = FineIdentifierRevision::query()->create([
                $this->columnaDelSujeto($sujeto) => $sujeto->getKey(),
                'kind' => $tipo,
                'old_values' => $anteriores,
                'new_values' => $nuevos,
                'reason_code' => $motivo?->code,
                'reason' => $motivo?->detail,
                'created_by' => $actorId,
            ]);

            $sujeto->refresh();

            $completa = $tipo === FineRevisionKind::Completion;

            /*
             * La fecha de un movimiento o de un antecedente se audita sobre la
             * multa: es donde se lee. Los códigos van literales: el catálogo
             * de auditoría los lee del código.
             */
            $this->auditar->handle(
                match (true) {
                    $sujeto instanceof FineCase && $completa => 'expediente-multa.datos-completados',
                    $sujeto instanceof FineCase => 'expediente-multa.datos-corregidos',
                    $sujeto instanceof FineDocument && $completa => 'documento-multa.datos-completados',
                    $sujeto instanceof FineDocument => 'documento-multa.datos-corregidos',
                    $sujeto instanceof FineDebtEntry && $completa => 'multa.fecha-de-movimiento-completada',
                    $sujeto instanceof FineDebtEntry => 'multa.fecha-de-movimiento-corregida',
                    $sujeto instanceof FineHistoricalMovement && $completa => 'multa.fecha-de-antecedente-completada',
                    $sujeto instanceof FineHistoricalMovement => 'multa.fecha-de-antecedente-corregida',
                    $completa => 'multa.datos-completados',
                    default => 'multa.datos-corregidos',
                },
                $this->sujetoAuditado($sujeto),
                before: $anteriores,
                after: $nuevos,
                metadata: array_filter([
                    'reason' => $motivo?->describe(),
                    'movimiento' => $sujeto instanceof FineDebtEntry ? $sujeto->kind->label() : null,
                    'antecedente' => $sujeto instanceof FineHistoricalMovement ? $sujeto->kind->label() : null,
                ]),
                actorId: $actorId,
            );

            return $revision;
        });
    }

    /**
     * Lo que el operador cambió respecto de lo que veía.
     *
     * Una fecha y su precisión viajan juntas: si cambia una, van las dos,
     * con el par que mostraba el formulario como valor anterior.
     *
     * @param  array<string, mixed>  $cambios
     * @param  array<string, mixed>  $esperados
     * @param  array<string, 'int'|'string'>  $campos
     * @return array{0: array<string, int|string|null>, 1: array<string, int|string|null>}
     */
    private function diferencias(array $cambios, array $esperados, array $campos): array
    {
        $anteriores = [];
        $nuevos = [];

        foreach ($cambios as $campo => $valor) {
            $nuevo = $this->normalizar($valor, $campos[$campo]);
            $visto = $this->normalizar($esperados[$campo], $campos[$campo]);

            if ($nuevo !== $visto) {
                $nuevos[$campo] = $nuevo;
                $anteriores[$campo] = $visto;
            }
        }

        foreach (self::FECHAS as $prefijo) {
            $fecha = "{$prefijo}_on";
            $precision = "{$prefijo}_precision";

            if (! array_key_exists($fecha, $nuevos) && ! array_key_exists($precision, $nuevos)) {
                continue;
            }

            foreach ([$fecha, $precision] as $campo) {
                $nuevos[$campo] = $this->normalizar($cambios[$campo] ?? null, 'string');
                $anteriores[$campo] = $this->normalizar($esperados[$campo] ?? null, 'string');
            }
        }

        return [$anteriores, $nuevos];
    }

    /**
     * Si lo que vio el formulario ya no es lo actual, otro operador lo
     * cambió primero. La base lo rechaza igual; esto lo dice en castellano.
     *
     * @param  array<string, int|string|null>  $anteriores
     * @param  array<string, mixed>  $actual
     */
    private function assertNotStale(array $anteriores, array $actual): void
    {
        foreach ($anteriores as $campo => $visto) {
            if ($visto !== $actual[$campo]) {
                throw ValidationException::withMessages([
                    'reason' => 'Otro operador cambió estos datos desde que abriste el formulario. Volvé a abrirlo para ver lo vigente antes de guardar.',
                ]);
            }
        }
    }

    /**
     * La fila como la ve el trigger: `to_jsonb` de PostgreSQL.
     *
     * @return array<string, mixed>
     */
    private function actual(string $tabla, int $id): array
    {
        $fila = match ($tabla) {
            'fine_cases' => 'to_jsonb(fine_cases.*) AS fila',
            'fine_documents' => 'to_jsonb(fine_documents.*) AS fila',
            'fine_debt_entries' => 'to_jsonb(fine_debt_entries.*) AS fila',
            'fine_historical_movements' => 'to_jsonb(fine_historical_movements.*) AS fila',
            default => 'to_jsonb(fines.*) AS fila',
        };

        $json = DB::table($tabla)->where('id', $id)->lockForUpdate()->selectRaw($fila)->value('fila');

        /** @var array<string, mixed> */
        return json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Lo que la base exige a una carga manual, dicho en castellano antes de
     * que la CHECK lo rechace: una corrección no puede dejarla incompleta.
     *
     * @param  array<string, mixed>  $actual
     * @param  array<string, int|string|null>  $nuevos
     */
    private function assertManualStaysComplete(Model $sujeto, array $actual, array $nuevos): void
    {
        if (($actual['provenance'] ?? null) !== 'manual') {
            return;
        }

        $resultado = [...$actual, ...$nuevos];

        $falta = match (true) {
            $sujeto instanceof FineCase => $resultado['number'] === null || $resultado['year'] === null
                ? ['number' => 'Un expediente cargado a mano tiene número y año.']
                : [],
            $sujeto instanceof Fine => $resultado['registered_precision'] !== 'day'
                ? ['registeredOn' => 'Una multa cargada a mano tiene la fecha de registro completa.']
                : [],
            $sujeto instanceof FineDebtEntry => $resultado['effective_precision'] !== 'day'
                ? ['date' => 'Un movimiento cargado a mano tiene la fecha completa.']
                : [],
            $sujeto instanceof FineHistoricalMovement => [],
            default => match (true) {
                $resultado['kind'] === 'act' && $resultado['act_type'] === null => ['actType' => 'Un acta cargada a mano dice si es laboral o de higiene y seguridad.'],
                $resultado['kind'] !== 'other' && $resultado['number'] === null => ['number' => 'El documento cargado a mano tiene número.'],
                $resultado['kind'] === 'resolution' && $resultado['year'] === null => ['year' => 'La resolución cargada a mano tiene año.'],
                default => [],
            },
        };

        if ($falta !== []) {
            throw ValidationException::withMessages($falta);
        }
    }

    private function normalizar(mixed $valor, string $tipo): int|string|null
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return $tipo === 'int' ? (int) $valor : trim((string) $valor);
    }

    /**
     * Completar es llenar vacíos o precisar una fecha sin moverla de mes.
     * La base vuelve a comprobarlo; esto decide si hace falta motivo.
     *
     * La fecha y su precisión se juzgan juntas aunque el día no cambie:
     * «01/05/2026» con día a «mayo de 2026» pierde precisión, y eso es
     * corregir (revisión del 2026-10-20, R2).
     *
     * @param  array<string, int|string|null>  $anteriores
     * @param  array<string, int|string|null>  $nuevos
     */
    private function esCompletar(array $anteriores, array $nuevos): bool
    {
        foreach ($nuevos as $campo => $valor) {
            if ($this->esDeFecha($campo) || $anteriores[$campo] === $valor) {
                continue;
            }

            if ($anteriores[$campo] !== null) {
                return false;
            }
        }

        foreach (self::FECHAS as $prefijo) {
            if (! array_key_exists("{$prefijo}_on", $nuevos)) {
                continue;
            }

            $antesFecha = $anteriores["{$prefijo}_on"];
            $antesPrecision = $anteriores["{$prefijo}_precision"];
            $despuesFecha = $nuevos["{$prefijo}_on"];
            $despuesPrecision = $nuevos["{$prefijo}_precision"];

            if ($antesFecha === $despuesFecha && $antesPrecision === $despuesPrecision) {
                continue;
            }

            if ($antesPrecision === 'unknown') {
                continue;
            }

            $mismoMes = is_string($despuesFecha) && is_string($antesFecha)
                && substr($despuesFecha, 0, 7) === substr($antesFecha, 0, 7);

            if ($antesPrecision === 'month' && $despuesPrecision === 'day' && $mismoMes) {
                continue;
            }

            return false;
        }

        return true;
    }

    private function esDeFecha(string $campo): bool
    {
        foreach (self::FECHAS as $prefijo) {
            if ($campo === "{$prefijo}_on" || $campo === "{$prefijo}_precision") {
                return true;
            }
        }

        return false;
    }

    private function columnaDelSujeto(Model $sujeto): string
    {
        return match (true) {
            $sujeto instanceof FineCase => 'fine_case_id',
            $sujeto instanceof FineDocument => 'fine_document_id',
            $sujeto instanceof FineDebtEntry => 'fine_debt_entry_id',
            $sujeto instanceof FineHistoricalMovement => 'fine_historical_movement_id',
            default => 'fine_id',
        };
    }

    /**
     * La fecha de un movimiento o de un antecedente se audita sobre su
     * multa, que es la pantalla donde se lee.
     */
    private function sujetoAuditado(Model $sujeto): Model
    {
        return match (true) {
            $sujeto instanceof FineDebtEntry, $sujeto instanceof FineHistoricalMovement => Fine::query()->findOrFail($sujeto->fine_id),
            default => $sujeto,
        };
    }
}
