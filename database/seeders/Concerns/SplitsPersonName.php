<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

use RuntimeException;

/**
 * Reparte el nombre de un catálogo de demostración en las columnas reales.
 *
 * Los catálogos de los seeders siguen escribiéndose de un tirón —«Cardozo,
 * Ramón Alberto»— porque así se leen de un vistazo y así llegan los papeles
 * del área. La convención es la coma, y acá se exige: un nombre de persona
 * física sin coma corta el seeder en vez de inventar un reparto.
 *
 * Esto vale **solo para datos de demostración**. Lo que carga un operador
 * llega ya separado desde el formulario, y no pasa por ningún corte.
 */
trait SplitsPersonName
{
    /**
     * Las columnas de nombre que corresponden a este tipo de ficha.
     *
     * @return array{first_name: string|null, last_name: string|null, legal_name: string|null}
     */
    private function nameColumns(string $type, string $name): array
    {
        if ($type === 'company') {
            return ['first_name' => null, 'last_name' => null, 'legal_name' => $name];
        }

        if (! str_contains($name, ',')) {
            throw new RuntimeException(
                "El catálogo del seeder trae «{$name}» sin coma. Una persona física se escribe «Apellido, Nombre»."
            );
        }

        [$apellido, $nombre] = explode(',', $name, 2);

        return [
            'first_name' => trim($nombre),
            'last_name' => trim($apellido),
            'legal_name' => null,
        ];
    }
}
