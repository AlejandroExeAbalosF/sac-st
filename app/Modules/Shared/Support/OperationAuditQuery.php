<?php

declare(strict_types=1);

namespace App\Modules\Shared\Support;

use App\Models\User;
use App\Modules\Shared\Audit\AuditCatalog;
use App\Modules\Shared\Data\AuditChangeData;
use App\Modules\Shared\Data\OperationAuditEventData;
use App\Modules\Shared\Http\Requests\OperationAuditFilterRequest;
use App\Modules\Shared\Models\AuditEvent;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * La consulta de la auditoría de operaciones, una sola para la pantalla y
 * para el Excel.
 *
 * Si cada una armara la suya, el día que una cambie un filtro el archivo
 * descargado dejaría de ser lo que se estaba mirando, y nadie lo notaría
 * hasta que las cifras no cierren en una revisión.
 *
 * @phpstan-type Filters array{desde: string|null, hasta: string|null, usuario: int|'sistema'|null, accion: string|null, entidad: string|null, criticas: bool}
 */
final class OperationAuditQuery
{
    public function __construct(
        private readonly AuditCatalog $catalog,
        private readonly AuditTimeline $timeline,
    ) {}

    /**
     * Los eventos que cumplen los filtros, del más reciente al más antiguo.
     *
     * @param  Filters  $filters
     * @return Builder<AuditEvent>
     */
    public function query(array $filters): Builder
    {
        return AuditEvent::query()
            ->with('user:id,name')
            /*
             * Las fechas son días del calendario de Salta. Se comparan como
             * un rango semiabierto sobre el instante —desde el inicio del
             * primer día hasta antes del inicio del día siguiente al
             * último— y no con `whereDate`, que mira el día en UTC y lo
             * corre tres horas, además de no poder usar el índice.
             */
            ->when($filters['desde'] !== null, fn (Builder $q) => $q
                ->where('occurred_at', '>=', BusinessDate::startOfDay((string) $filters['desde'])))
            ->when($filters['hasta'] !== null, fn (Builder $q) => $q
                ->where('occurred_at', '<', BusinessDate::startOfDay(
                    CarbonImmutable::parse((string) $filters['hasta'])->addDay()->toDateString(),
                )))
            ->when($filters['usuario'] === OperationAuditFilterRequest::SYSTEM_USER, fn (Builder $q) => $q
                ->whereNull('user_id'))
            ->when(is_int($filters['usuario']), fn (Builder $q) => $q
                ->where('user_id', $filters['usuario']))
            ->when($filters['accion'] !== null, fn (Builder $q) => $q
                ->where('action', $filters['accion']))
            ->when($filters['entidad'] !== null, fn (Builder $q) => $q
                ->where('subject_type', $filters['entidad']))
            ->when($filters['criticas'], fn (Builder $q) => $q
                ->whereIn('action', $this->catalog->criticalCodes()))
            ->orderByDesc('occurred_at')
            // Desempata los que caen en el mismo instante, que dentro de
            // una transacción es lo normal.
            ->orderByDesc('id');
    }

    /**
     * Los eventos en bloques, para el Excel.
     *
     * Dos cosas lo hacen estable:
     *
     * - **Una foto al empezar.** Se fija el id más alto antes del primer
     *   bloque; lo que se registre mientras se descarga no se mete en el
     *   medio del archivo ni corre a los demás.
     * - **Un cursor por (instante, id)**, en el mismo orden que la
     *   pantalla. Recorrer por id solo, como `lazyById`, no respeta ese
     *   orden: el id y el instante no siempre crecen juntos entre
     *   transacciones concurrentes.
     *
     * El instante del cursor se toma tal como lo devuelve la base, con sus
     * microsegundos; pasarlo por Carbon los recortaría y el bloque
     * siguiente repetiría o saltearía filas.
     *
     * @param  Filters  $filters
     * @return Generator<int, Collection<int, AuditEvent>>
     */
    public function chunks(array $filters, int $size = 500): Generator
    {
        $maxId = AuditEvent::query()->max('id');

        if ($maxId === null) {
            return;
        }

        $cursor = null;

        do {
            $bloque = $this->query($filters)
                ->where('id', '<=', $maxId)
                ->when($cursor !== null, fn (Builder $q) => $q
                    ->whereRaw('(occurred_at, id) < (?::timestamptz, ?)', $cursor))
                ->limit($size)
                ->get();

            if ($bloque->isEmpty()) {
                return;
            }

            yield $bloque;

            /** @var AuditEvent $ultimo */
            $ultimo = $bloque->last();
            $cursor = [(string) $ultimo->getRawOriginal('occurred_at'), $ultimo->id];
        } while ($bloque->count() === $size);
    }

    /**
     * Los eventos con su sujeto nombrado y sus cambios en palabras.
     *
     * Todo en lote: una consulta por tipo de sujeto y por entidad
     * referida, no una por evento.
     *
     * @param  Collection<int, AuditEvent>  $eventos
     * @return list<OperationAuditEventData>
     */
    public function present(Collection $eventos, ?User $viewer): array
    {
        $descripciones = [];

        foreach ($eventos->groupBy('subject_type') as $tipo => $delTipo) {
            $descripciones[$tipo] = $this->catalog->describe(
                (string) $tipo,
                array_values(array_map('intval', $delTipo->pluck('subject_id')->all())),
                $viewer,
            );
        }

        $cambios = $this->timeline->changes($eventos);

        return array_values($eventos
            ->map(function (AuditEvent $evento) use ($descripciones, $cambios): OperationAuditEventData {
                $accion = $this->catalog->action($evento->action);
                $sujeto = $descripciones[$evento->subject_type][$evento->subject_id];

                return new OperationAuditEventData(
                    id: $evento->id,
                    occurredAt: $evento->occurred_at->toIso8601String(),
                    userName: $evento->user?->name,
                    action: $evento->action,
                    label: $accion->label,
                    critical: $accion->isCritical(),
                    category: $accion->category,
                    subjectType: $evento->subject_type,
                    subjectId: $evento->subject_id,
                    subjectLabel: $this->catalog->subjectLabel($evento->subject_type),
                    subjectDescription: $sujeto->label,
                    subjectUrl: $sujeto->url,
                    changes: array_map(
                        fn (array $cambio): AuditChangeData => new AuditChangeData(...$cambio),
                        $cambios[$evento->id],
                    ),
                    ipAddress: $evento->ip_address,
                );
            })
            ->all());
    }
}
