<?php

declare(strict_types=1);

namespace App\Modules\Shared\Data;

use App\Modules\Shared\Enums\QueueTone;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Una cola de trabajo del tablero.
 *
 * El tablero no muestra métricas acumuladas: muestra **cosas que esperan
 * que alguien haga algo**. Cada cola se deriva de un estado real del
 * modelo —fondos sin identificar, cuotas financiadas sin Orden, egresos
 * por validar— y su número siempre significa lo mismo: cuántos casos hay
 * pendientes de esa acción.
 *
 * `count` en `null` no es cero: significa que el módulo que alimenta esa
 * cola todavía no existe. La distinción importa, porque un cero es
 * información —«no hay nada pendiente»— y un módulo ausente no lo es.
 */
#[TypeScript]
final class WorkQueueData extends Data
{
    /**
     * @param  list<QueueSampleData>  $samples
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $description,
        public ?int $count,
        public QueueTone $tone,
        /** Módulo que la alimenta, para el aviso cuando no está disponible. */
        public ?string $pendingModule = null,
        /**
         * La pantalla que muestra exactamente estas filas.
         *
         * Solo cuando existe y queda filtrada igual que el número. Un
         * enlace al listado completo diría «buscalas vos», que es lo que
         * el tablero vino a reemplazar.
         */
        public ?string $href = null,
        /** Los primeros casos, cuando no hay pantalla filtrada que ofrecer. */
        public array $samples = [],
        /**
         * El número es un piso, no el total.
         *
         * Las colas que se resuelven caso por caso recorren un tope de
         * candidatos: pasado ese tope la pantalla dice «250+» en lugar de
         * mentir con un número redondo.
         */
        public bool $hasMore = false,
    ) {}

    /**
     * Una cola con su número resuelto.
     *
     * @param  list<QueueSampleData>  $samples
     */
    public static function resuelta(
        string $key,
        string $title,
        string $description,
        int $count,
        QueueTone $tone = QueueTone::Neutral,
        ?string $href = null,
        array $samples = [],
        bool $hasMore = false,
    ): self {
        return new self(
            key: $key,
            title: $title,
            description: $description,
            /*
             * Sin pendientes no hay urgencia que señalar. El tono es
             * información —«esto espera que alguien haga algo»— y pintar
             * de rojo un cero sería decir que hay trabajo donde no lo hay.
             */
            count: $count,
            tone: $count === 0 ? QueueTone::Done : $tone,
            pendingModule: null,
            href: $href,
            samples: $samples,
            hasMore: $hasMore,
        );
    }

    public static function pendiente(
        string $key,
        string $title,
        string $description,
        string $pendingModule,
        QueueTone $tone = QueueTone::Neutral,
    ): self {
        return new self(
            key: $key,
            title: $title,
            description: $description,
            count: null,
            tone: $tone,
            pendingModule: $pendingModule,
        );
    }
}
