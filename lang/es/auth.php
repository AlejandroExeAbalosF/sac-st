<?php

declare(strict_types=1);

/*
| Mensajes de autenticación.
|
| `APP_LOCALE` es `es` y el framework solo trae `en`: sin estos archivos,
| cada mensaje salía a la pantalla como su propia clave —«auth.failed» en la
| cara de quien solo quería entrar—.
|
| El tratamiento es el mismo que el del resto del sistema: voseo, y decir qué
| pasó sin culpar a nadie.
*/

return [

    /*
     * A propósito no distingue entre «no existe ese usuario» y «la contraseña
     * está mal»: decirlo confirmaría qué nombres de usuario existen a
     * cualquiera que pruebe. El historial de accesos sí guarda la diferencia,
     * en `failure_reason`, que es donde importa.
     */
    'failed' => 'El usuario o la contraseña no coinciden.',

    'password' => 'La contraseña no es correcta.',

    'throttle' => 'Demasiados intentos. Probá de nuevo en :seconds segundos.',

];
