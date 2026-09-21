<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use App\Models\User;
use App\Modules\Shared\Data\LastChangeData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * El último cambio registrado sobre cada fila de un listado.
 *
 * Dos consultas para toda la página y no una por fila: `DISTINCT ON` deja
 * que PostgreSQL resuelva el «más reciente por sujeto» de una sola pasada,
 * apoyado en el índice `(subject_type, subject_id, occurred_at)` que la
 * tabla ya tiene. Es específico de PostgreSQL y acá eso no es una
 * concesión: los tests corren contra PostgreSQL real.
 *
 * Qué cuenta como cambio lo decide quien llama, pasando las acciones que
 * hay que ignorar. Esta clase vive en `Shared` y no puede saber que
 * `expediente.registrado` es un alta; lo que sí sabe es que el alta ya
 * tiene su propia columna y no es una modificación.
 */
final class LastChanges
{
    /**
     * @param  string  $subjectType  `class_basename` del modelo, como lo guarda `RecordAuditEvent`
     * @param  list<int>  $subjectIds
     * @param  list<string>  $ignoring  acciones que no cuentan como modificación
     * @return array<int, LastChangeData> id del sujeto => su último cambio
     */
    public function for(string $subjectType, array $subjectIds, array $ignoring = []): array
    {
        if ($subjectIds === []) {
            return [];
        }

        $eventos = DB::table('audit_events')
            ->select(['subject_id', 'occurred_at', 'user_id'])
            ->where('subject_type', $subjectType)
            ->whereIn('subject_id', $subjectIds)
            ->when($ignoring !== [], fn ($query) => $query->whereNotIn('action', $ignoring))
            // El orden no es cosmético: `DISTINCT ON` se queda con la
            // primera fila de cada sujeto, así que es lo que define
            // «la más reciente».
            ->distinct('subject_id')
            ->orderBy('subject_id')
            ->orderByDesc('occurred_at')
            ->get();

        $nombres = $this->nombresDe($eventos->pluck('user_id')->all());

        $cambios = [];

        foreach ($eventos as $evento) {
            $cambios[(int) $evento->subject_id] = new LastChangeData(
                at: Carbon::parse((string) $evento->occurred_at)->toIso8601String(),
                by: $evento->user_id === null ? null : ($nombres[(int) $evento->user_id] ?? null),
            );
        }

        return $cambios;
    }

    /**
     * @param  array<int, int|null>  $userIds
     * @return array<int, string>
     */
    private function nombresDe(array $userIds): array
    {
        $ids = array_values(array_unique(array_filter(
            $userIds,
            static fn (?int $id): bool => $id !== null,
        )));

        if ($ids === []) {
            return [];
        }

        /*
         * `name` es una columna generada por la base, no un accessor: hay
         * que pedirla por su nombre o no viene.
         */
        /** @var array<int, string> $nombres */
        $nombres = User::query()
            ->whereIn('id', $ids)
            ->get(['id', 'name'])
            ->mapWithKeys(fn (User $user): array => [$user->id => $user->name])
            ->all();

        return $nombres;
    }
}
