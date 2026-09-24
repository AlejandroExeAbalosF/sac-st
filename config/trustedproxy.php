<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Proxies de confianza
    |--------------------------------------------------------------------------
    |
    | De quién se aceptan los X-Forwarded-*. El middleware TrustProxies de
    | Laravel lee esta clave; sin ella, detrás del nginx del stack la app
    | cree que todo pedido llegó por HTTP desde la IP del contenedor web.
    |
    | Lista separada por comas, con rangos CIDR: la red del stack y el proxy
    | del data center. No se usa '*': Laravel lo traduce como «confiar en
    | quien llama», y quien llama es el nginx, así que la IP que registra la
    | auditoría sería siempre la del proxy y no la del usuario.
    |
    | Vacío en desarrollo: nadie está adelante.
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),

];
