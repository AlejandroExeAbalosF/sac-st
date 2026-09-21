<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Data;

use App\Models\User;
use App\Modules\Haberes\Models\Expediente;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Lo que el expediente guarda y la tarjeta no muestra de entrada.
 *
 * Son datos de procedencia y de control: no se leen todos los días, pero
 * cuando un importe no cierra son los que dicen de dónde salió la carga y
 * contra qué papel compararla.
 *
 * Viajan aparte de `ExpedienteListItemData` a propósito. Esa la usa el
 * listado, y sumarle la procedencia obligaría a traerla —y a cargar el
 * autor de cada fila— en una pantalla que no la muestra.
 */
#[TypeScript]
final class ExpedienteExtraData extends Data
{
    public function __construct(
        /**
         * Total informado en el acta.
         *
         * `null` no es cero: es que el papel no lo traía. La pantalla los
         * dice distinto porque son cosas distintas.
         *
         * @var numeric-string|null
         */
        public ?string $declaredTotalAmount,
        /**
         * El «Cod.» del pie de la carátula.
         *
         * `source_system` no viaja: hoy la escribe el alta como constante,
         * así que decir «SiCE v3.0» en cada ficha no informa nada. Vuelve
         * el día que haya una segunda procedencia.
         */
        public ?string $externalId,
        /** Otro número con el que el área también lo busca. */
        public ?string $externalReference,
        public ?string $createdByName,
        public ?string $createdAt,
    ) {}

    public static function fromModel(Expediente $expediente): self
    {
        $autor = $expediente->creator;

        return new self(
            declaredTotalAmount: self::importe($expediente->declared_total_amount),
            externalId: $expediente->external_id,
            externalReference: $expediente->external_reference,
            // El usuario puede haberse borrado; el expediente no.
            createdByName: $autor instanceof User ? $autor->name : null,
            createdAt: $expediente->created_at?->toIso8601String(),
        );
    }

    /**
     * @return numeric-string|null
     */
    private static function importe(?string $valor): ?string
    {
        /** @var numeric-string|null $valor */
        return $valor;
    }
}
