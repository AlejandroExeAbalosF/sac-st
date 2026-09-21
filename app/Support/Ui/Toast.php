<?php

declare(strict_types=1);

namespace App\Support\Ui;

use Inertia\Inertia;

/**
 * Confirmaciones efímeras, por el canal que no queda pegado en pantalla.
 *
 * `Inertia::flash()` no viaja en las props: se resuelve al nivel de la
 * página, se consume una sola vez y no entra en el historial del navegador.
 * Eso es lo que distingue una confirmación de un estado —«traslado
 * cancelado» pasó, «este haber está anulado» *es*— y por eso lo segundo
 * sigue dibujándose dentro de la pantalla y no acá.
 *
 * El puente de `HandleInertiaRequests` sigue levantando los `->with('status',
 * ...)` históricos como `Success` sin descripción, que es lo correcto para
 * un mensaje de una sola oración. Esta clase existe para lo que ese puente
 * no puede expresar: el tono y el segundo renglón.
 *
 * `$message` es qué pasó y `$description` qué hacer con eso. Van separados
 * porque el front los jerarquiza —título y renglón secundario— y porque
 * partir la oración automáticamente no se puede: «Cuenta «Cta. Cte. 2693»
 * registrada.» tiene cuatro puntos y ninguno termina la idea.
 */
final class Toast
{
    public static function success(string $message, ?string $description = null): void
    {
        self::flash(ToastType::Success, $message, $description);
    }

    public static function info(string $message, ?string $description = null): void
    {
        self::flash(ToastType::Info, $message, $description);
    }

    public static function warning(string $message, ?string $description = null): void
    {
        self::flash(ToastType::Warning, $message, $description);
    }

    public static function error(string $message, ?string $description = null): void
    {
        self::flash(ToastType::Error, $message, $description);
    }

    private static function flash(ToastType $type, string $message, ?string $description): void
    {
        Inertia::flash('toast', array_filter([
            'type' => $type->value,
            'message' => $message,
            'description' => $description,
        ], static fn (?string $valor): bool => $valor !== null));
    }
}
