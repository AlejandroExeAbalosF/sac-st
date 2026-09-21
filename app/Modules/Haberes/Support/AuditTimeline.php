<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Shared\Models\AuditEvent;
use App\Support\Money\Decimal;
use Illuminate\Support\Collection;

/**
 * Los eventos de auditoría, con la forma que el panel sabe dibujar.
 *
 * Existe para que esa forma se defina **una sola vez**. El historial se
 * mira con el mismo componente para la cuota, el haber y el expediente; si
 * cada controlador armara su propio JSON, el día que uno cambie una clave
 * el panel dejaría de entender a ese sujeto y a los otros no, que es la
 * clase de error que nadie nota hasta que alguien pregunta por qué una
 * pantalla quedó vacía.
 *
 * Lo que cambia entre sujetos son los rótulos y las traducciones, y eso
 * viaja por parámetro. Lo que no cambia —la consulta, el orden, el diff
 * entre el antes y el después— vive acá.
 */
final class AuditTimeline
{
    /**
     * El rastro de un sujeto, del cambio más reciente al más antiguo.
     *
     * Devuelve los eventos y no el JSON porque quien llama necesita
     * mirarlos antes: los diccionarios se arman con los ids que los
     * eventos nombran, y traer el maestro entero para traducir dos
     * nombres sería pagar de más en cada apertura del panel.
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
     * Los valores distintos que un campo toma a lo largo del rastro.
     *
     * Es lo que hace falta para traducir un id a un nombre sin leer el
     * maestro entero: el antes y el después de cada corrección, y nada
     * más.
     *
     * @param  Collection<int, AuditEvent>  $eventos
     * @return list<int>
     */
    public function idsMencionados(Collection $eventos, string $campo): array
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
     * Los eventos, con la forma que el panel consume.
     *
     * @param  Collection<int, AuditEvent>  $eventos
     * @param  array<string, string>  $campos
     * @param  array<string, array<array-key, string>>  $diccionarios
     * @return list<array{id: int, action: string, at: string, by: string|null, changes: list<array{field: string, before: string|null, after: string|null}>}>
     */
    public function shape(Collection $eventos, array $campos, array $diccionarios = []): array
    {
        return array_values($eventos
            ->map(fn (AuditEvent $evento): array => [
                'id' => $evento->id,
                'action' => $evento->action,
                'at' => $evento->occurred_at->toIso8601String(),
                'by' => $evento->user?->name,
                'changes' => $this->cambios($evento, $campos, $diccionarios),
            ])
            ->all());
    }

    /**
     * Antes y después, campo por campo y en palabras.
     *
     * @param  array<string, string>  $campos
     * @param  array<string, array<array-key, string>>  $diccionarios
     * @return list<array{field: string, before: string|null, after: string|null}>
     */
    private function cambios(AuditEvent $evento, array $campos, array $diccionarios): array
    {
        $viejos = $evento->old_values ?? [];
        $nuevos = $evento->new_values ?? [];

        /*
         * Los enlaces con otras filas no son datos para leer: quien mira
         * el historial de un haber ya sabe de qué expediente es.
         */
        foreach (['beneficiary_installment_id', 'expediente_id', 'haber_id'] as $enlace) {
            unset($viejos[$enlace], $nuevos[$enlace]);
        }

        /** @var list<string> $claves */
        $claves = array_values(array_unique([...array_keys($viejos), ...array_keys($nuevos)]));

        $cambios = array_map(
            fn (string $campo): array => [
                'field' => $campos[$campo] ?? $campo,
                'before' => $this->legible($campo, $viejos[$campo] ?? null, $diccionarios),
                'after' => $this->legible($campo, $nuevos[$campo] ?? null, $diccionarios),
            ],
            $claves,
        );

        $metadata = $evento->metadata ?? [];

        /*
         * Hay eventos que no cambian ningún campo. La cancelación de un
         * traslado es uno: lo que importa es de cuánto era y por qué se
         * dio de baja, y las dos cosas viven en la metadata. Sin esto el
         * evento llegaría a la pantalla sin una sola palabra.
         */
        if ($cambios === [] && isset($metadata['amount'])) {
            $cambios[] = [
                'field' => $campos['amount'] ?? 'amount',
                'before' => null,
                'after' => $this->legible('amount', $metadata['amount'], $diccionarios),
            ];
        }

        /*
         * El motivo se suma siempre, haya o no campos cambiados. Anular y
         * reactivar sí mueven el estado, así que llegan con su antes y su
         * después, y el motivo quedaría afuera — que es justo la única
         * parte que explica por qué alguien lo hizo.
         */
        $motivo = $metadata['motivo'] ?? null;

        if (is_string($motivo) && trim($motivo) !== '') {
            $cambios[] = [
                'field' => $campos['motivo'] ?? 'Motivo',
                'before' => null,
                'after' => $motivo,
            ];
        }

        return $cambios;
    }

    /**
     * @param  array<string, array<array-key, string>>  $diccionarios
     */
    private function legible(string $campo, mixed $valor, array $diccionarios): ?string
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

        if ($campo === 'amount' || str_ends_with($campo, '_amount')) {
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
         */
        if (str_ends_with($campo, '_date')
            && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string) $valor, $partes) === 1
        ) {
            return "{$partes[3]}/{$partes[2]}/{$partes[1]}";
        }

        return (string) $valor;
    }
}
