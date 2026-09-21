<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Un movimiento del libro, tal como lo lee el tablero.
 *
 * Es el pulso del sistema: qué pasó recién, sin filtrar por caja ni por
 * día. No reemplaza a la planilla de caja —esa lista comprobantes y esta
 * lista hechos—, y por eso vive acá y no en `CashDayBook`.
 *
 * El importe es la suma de los débitos del asiento. Un asiento balancea
 * por construcción —lo impone el trigger—, así que débitos y créditos
 * dicen el mismo número y elegir uno de los dos no es una convención
 * discutible.
 */
#[TypeScript]
final class MovementRowData extends Data
{
    public function __construct(
        public string $publicId,
        /** «Recepción de fondos», «Pago por transferencia». */
        public string $typeLabel,
        /** El valor del enum, para que la pantalla elija ícono y color. */
        public string $type,
        /** Fecha operativa del hecho, no la de registración. */
        public string $eventDate,
        /** Instante en que el asiento quedó registrado. */
        public string $recordedAt,
        /** @var numeric-string */
        public string $amount,
        public ?string $description,
        public ?string $cashBoxName,
        /** Si el asiento fue revertido después. */
        public bool $isReversed,
        /** El día de la caja en el que este movimiento se explica. */
        public string $href,
    ) {}
}
