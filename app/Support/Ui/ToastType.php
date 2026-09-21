<?php

declare(strict_types=1);

namespace App\Support\Ui;

/**
 * El tono de una confirmación efímera.
 *
 * No es decoración: el front le da a cada tono una duración distinta y
 * `Error` no se va solo, porque un rechazo hay que leerlo antes de corregir
 * lo que lo produjo. `Warning` es el punto medio que faltaba —la operación
 * salió, pero dejó algo que conviene mirar— y es lo que separa «cuenta
 * verificada» de «cuenta rechazada»: las dos terminaron bien, una habilita
 * y la otra clausura.
 */
enum ToastType: string
{
    case Success = 'success';
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
}
