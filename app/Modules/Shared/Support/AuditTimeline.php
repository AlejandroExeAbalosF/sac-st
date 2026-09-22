<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use App\Modules\Shared\Audit\AuditCatalog;
use App\Modules\Shared\Models\AuditEvent;
use App\Support\Money\Decimal;
use Illuminate\Support\Collection;

/**
 * Los eventos de auditoría, con la forma que las pantallas saben dibujar.
 *
 * Existe para que esa forma se defina **una sola vez**. El historial de la
 * cuota, el del haber, el del expediente y la auditoría de operaciones
 * leen los mismos eventos; si cada uno armara su propio JSON, el día que
 * uno cambie una clave dejaría de entender a los demás, que es la clase de
 * error que nadie nota hasta que alguien pregunta por qué una pantalla
 * quedó vacía.
 *
 * Qué significa cada acción, cómo se llama cada campo y cómo se traduce
 * cada id lo dice `AuditCatalog`. Acá vive la consulta, el orden y el
 * diff entre el antes y el después.
 */
final class AuditTimeline
{
    /**
     * Los enlaces con otras filas no son datos para leer: quien mira el
     * historial de un haber ya sabe de qué expediente es, y en la pantalla
     * general el sujeto ya viene nombrado y enlazado.
     */
    private const LINK_FIELDS = ['beneficiary_installment_id', 'expediente_id', 'haber_id'];

    public function __construct(private readonly AuditCatalog $catalog) {}

    /**
     * El rastro de un sujeto, del cambio más reciente al más antiguo.
     *
     * @param  string  $subjectType  `class_basename` del modelo, como lo guarda `RecordAuditEvent`
     * @return Collection<int, AuditEvent>
     */
    public function eventsFor(string $subjectType, int $subjectId): Collection
    {
        return AuditEvent::query()
            ->with('user:id,name')
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->orderByDesc('occurred_at')
            // Desempata los que caen en el mismo instante, que en una
            // transacción es lo normal.
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Los eventos, con la forma que el panel de historial consume.
     *
     * @param  Collection<int, AuditEvent>  $eventos
     * @return list<array{id: int, action: string, label: string, critical: bool, at: string, by: string|null, changes: list<array{field: string, before: string|null, after: string|null}>}>
     */
    public function shape(Collection $eventos): array
    {
        $cambios = $this->changes($eventos);

        return array_values($eventos
            ->map(function (AuditEvent $evento) use ($cambios): array {
                $accion = $this->catalog->action($evento->action);

                return [
                    'id' => $evento->id,
                    'action' => $evento->action,
                    'label' => $accion->label,
                    'critical' => $accion->isCritical(),
                    'at' => $evento->occurred_at->toIso8601String(),
                    'by' => $evento->user?->name,
                    'changes' => $cambios[$evento->id],
                ];
            })
            ->all());
    }

    /**
     * Antes y después de cada evento, en palabras, indexado por id.
     *
     * Las traducciones se resuelven en lote para todos los eventos juntos:
     * una consulta por entidad referida, no una por evento.
     *
     * @param  Collection<int, AuditEvent>  $eventos
     * @return array<int, list<array{field: string, before: string|null, after: string|null}>>
     */
    public function changes(Collection $eventos): array
    {
        $referencias = $this->references($eventos);

        $resultado = [];

        foreach ($eventos as $evento) {
            $sujeto = $this->catalog->subject($evento->subject_type);

            $resultado[$evento->id] = $this->cambios(
                $evento,
                $sujeto?->fields() ?? [],
                [...$referencias, ...($sujeto?->valueLabels() ?? [])],
                [...($sujeto?->moneyFields() ?? []), ...$this->catalog->action($evento->action)->money],
            );
        }

        return $resultado;
    }

    /**
     * Los nombres de las filas que los eventos mencionan, por campo.
     *
     * @param  Collection<int, AuditEvent>  $eventos
     * @return array<string, array<int, string>>
     */
    private function references(Collection $eventos): array
    {
        $porResolver = [];

        foreach ($this->catalog->references() as $campo => $resolver) {
            $clave = spl_object_id($resolver);
            $porResolver[$clave]['resolver'] = $resolver;
            $porResolver[$clave]['campos'][] = $campo;
            $porResolver[$clave]['ids'] = [
                ...($porResolver[$clave]['ids'] ?? []),
                ...$this->idsMencionados($eventos, $campo),
            ];
        }

        $diccionarios = [];

        foreach ($porResolver as $grupo) {
            $ids = array_values(array_unique($grupo['ids']));
            $nombres = $ids === [] ? [] : $grupo['resolver']->labels($ids);

            foreach ($grupo['campos'] as $campo) {
                $diccionarios[$campo] = $nombres;
            }
        }

        return $diccionarios;
    }

    /**
     * Los valores distintos que un campo toma a lo largo del rastro.
     *
     * @param  Collection<int, AuditEvent>  $eventos
     * @return list<int>
     */
    private function idsMencionados(Collection $eventos, string $campo): array
    {
        /** @var list<int> $ids */
        $ids = $eventos
            ->flatMap(fn (AuditEvent $e): array => [
                $e->old_values[$campo] ?? null,
                $e->new_values[$campo] ?? null,
            ])
            ->filter(fn (mixed $valor): bool => is_numeric($valor))
            ->map(fn (mixed $valor): int => (int) $valor)
            ->unique()
            ->values()
            ->all();

        return $ids;
    }

    /**
     * Antes y después, campo por campo y en palabras.
     *
     * @param  array<string, string>  $campos
     * @param  array<string, array<array-key, string>>  $diccionarios
     * @param  list<string>  $importes
     * @return list<array{field: string, before: string|null, after: string|null}>
     */
    private function cambios(AuditEvent $evento, array $campos, array $diccionarios, array $importes): array
    {
        $viejos = $evento->old_values ?? [];
        $nuevos = $evento->new_values ?? [];

        foreach (self::LINK_FIELDS as $enlace) {
            unset($viejos[$enlace], $nuevos[$enlace]);
        }

        /** @var list<string> $claves */
        $claves = array_values(array_unique([...array_keys($viejos), ...array_keys($nuevos)]));

        $cambios = array_map(
            fn (string $campo): array => [
                'field' => $campos[$campo] ?? $campo,
                'before' => $this->legible($campo, $viejos[$campo] ?? null, $diccionarios, $importes),
                'after' => $this->legible($campo, $nuevos[$campo] ?? null, $diccionarios, $importes),
            ],
            $claves,
        );

        /*
         * Hay eventos cuyo dato principal no es un campo cambiado sino la
         * metadata: la cancelación de un traslado cuenta de cuánto era y
         * por qué se dio de baja, y ninguna de las dos cosas es una
         * columna. Se muestra solo lo que la acción declaró —más el
         * motivo, que vale para todas—; el resto de la metadata queda en
         * la tabla.
         */
        $metadata = $evento->metadata ?? [];

        foreach ($this->catalog->metadataFor($evento->action) as $clave => $rotulo) {
            $valor = $this->legible($clave, $metadata[$clave] ?? null, $diccionarios, $importes);

            if ($valor !== null && trim($valor) !== '') {
                $cambios[] = ['field' => $rotulo, 'before' => null, 'after' => $valor];
            }
        }

        return $cambios;
    }

    /**
     * @param  array<string, array<array-key, string>>  $diccionarios
     * @param  list<string>  $importes
     */
    private function legible(string $campo, mixed $valor, array $diccionarios, array $importes = []): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        /*
         * El registro guarda ids y códigos, que es lo correcto: la
         * etiqueta de una cuenta o el nombre de una persona pueden
         * cambiar y el historial tiene que seguir señalando lo mismo. Un
         * número en pantalla, en cambio, no le dice nada a nadie.
         */
        if ((is_int($valor) || is_string($valor)) && isset($diccionarios[$campo][$valor])) {
            return $diccionarios[$campo][$valor];
        }

        if (is_bool($valor)) {
            return $valor ? 'Sí' : 'No';
        }

        /*
         * Listas —los permisos de un rol, las señales de un cotejo—. Una
         * lista de valores simples se lee separada por comas; cualquier
         * otra cosa sale como JSON antes que como «Array».
         */
        if (is_array($valor)) {
            return array_is_list($valor) && array_filter($valor, fn (mixed $v): bool => ! is_scalar($v)) === []
                ? implode(', ', array_map(fn (mixed $v): string => is_bool($v) ? ($v ? 'Sí' : 'No') : (string) $v, $valor))
                : (string) json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if ($campo === 'amount' || str_ends_with($campo, '_amount') || in_array($campo, $importes, true)) {
            return '$ '.Decimal::format((string) $valor);
        }

        /*
         * Las fechas salían como las guarda la base —`2026-09-16`— y el
         * resto del sistema las escribe `16/09/2026`. Eran tres pantallas
         * diciendo la fecha de dos formas distintas.
         *
         * Se reordenan los tres números y nada más: **no se parsea ni se
         * convierte de huso**. Un `date` de PostgreSQL describe un día del
         * almanaque, no un instante; interpretarlo como medianoche UTC y
         * presentarlo en Salta lo correría al día anterior, y acá la fecha
         * de un depósito decide a qué período pertenece.
         *
         * Se reconoce por la forma y no por el nombre del campo: una cadena
         * que es exactamente `AAAA-MM-DD` es un día, se llame `due_date`,
         * `deposited_at` o `desde`.
         */
        if (is_string($valor) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valor, $partes) === 1) {
            return "{$partes[3]}/{$partes[2]}/{$partes[1]}";
        }

        return is_scalar($valor) ? (string) $valor : null;
    }
}
