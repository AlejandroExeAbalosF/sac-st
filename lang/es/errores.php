<?php

declare(strict_types=1);

/*
 * Los textos de las pantallas de error, en un solo lugar.
 *
 * La misma situación se muestra de dos formas según cómo llegue el pedido:
 * dentro del sistema, como pantalla de Inertia con la barra lateral puesta,
 * y fuera, como vista Blade suelta (ver resources/views/errors). Sin este
 * archivo cada texto estaría escrito dos veces y se corregiría una sola.
 */

return [
    401 => [
        'titulo' => 'Necesitás iniciar sesión',
        'detalle' => 'La sesión no está activa o dejó de ser válida. Ingresá de nuevo para continuar.',
        'volver' => 'Ir al ingreso',
    ],

    403 => [
        'titulo' => 'No tenés permiso para esta pantalla',
        'detalle' => 'Tu usuario no tiene habilitada esta parte del sistema. Si creés que debería tenerla, pedísela a un administrador.',
        'volver' => 'Volver al inicio',
    ],

    404 => [
        'titulo' => 'No encontramos esa página',
        'detalle' => 'La dirección no existe, o el registro que buscabas ya no está. Revisá el enlace y volvé al inicio.',
        'volver' => 'Volver al inicio',
    ],

    419 => [
        'titulo' => 'La sesión venció',
        'detalle' => 'Por seguridad el sistema cierra las sesiones inactivas. Lo que estabas cargando no llegó a enviarse: ingresá de nuevo y repetí la operación.',
        'volver' => 'Ir al ingreso',
    ],

    429 => [
        'titulo' => 'Demasiados intentos seguidos',
        'detalle' => 'El sistema limita los intentos seguidos para proteger las cuentas. Esperá un momento antes de volver a probar.',
        'volver' => 'Volver al inicio',
    ],

    500 => [
        'titulo' => 'Algo falló de nuestro lado',
        // Nunca el mensaje de la excepción: puede traer datos internos.
        'detalle' => 'El error quedó registrado. Volvé a intentar en un momento; si vuelve a pasar, avisá al área de sistemas.',
        'volver' => 'Volver al inicio',
    ],

    503 => [
        'titulo' => 'El sistema está en mantenimiento',
        'detalle' => 'Se está aplicando una actualización. Volvé a intentar en unos minutos.',
        'volver' => 'Volver al inicio',
    ],

    'ayuda' => 'Si el problema sigue, avisá al área de sistemas indicando qué estabas haciendo y el código :codigo.',
];
