<?php

declare(strict_types=1);

namespace App\Support\Database;

/**
 * Patrones de `LIKE` / `ILIKE` a partir de lo que tipea una persona.
 *
 * Los valores ya iban como parámetros —no hay inyección SQL—, pero `%` y
 * `_` son comodines para PostgreSQL y llegaban sin escapar: buscar `%`
 * encontraba cualquier cosa. En la búsqueda de expediente al imputar
 * fondos eso ofrecía las cuotas del primer expediente que apareciera.
 *
 * PostgreSQL usa la barra invertida como escape por defecto en `LIKE`, así
 * que alcanza con anteponerla; no hace falta la cláusula `ESCAPE`.
 */
final class Like
{
    /** El término, literal, en cualquier parte del texto. */
    public static function contains(string $term): string
    {
        return '%'.self::escape($term).'%';
    }

    public static function escape(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
