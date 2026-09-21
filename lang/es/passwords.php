<?php

declare(strict_types=1);

/*
| Recuperación de contraseña.
|
| Los usa el flujo de Fortify: el enlace por correo, el formulario de nueva
| contraseña y sus rechazos.
*/

return [

    'reset' => 'Tu contraseña quedó cambiada.',

    'sent' => 'Te enviamos un enlace para recuperar la contraseña.',

    'throttled' => 'Esperá un momento antes de volver a intentarlo.',

    'token' => 'El enlace para recuperar la contraseña no es válido o ya venció.',

    /*
     * No dice «no existe ese correo», por lo mismo que `auth.failed`: sería
     * una forma de averiguar qué casillas están registradas.
     */
    'user' => 'No pudimos enviar el enlace a ese correo.',

];
