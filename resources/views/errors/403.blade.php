{{--
    `abort(403, '…')` lleva un motivo escrito por quien puso la guarda, y
    ese motivo explica mejor que cualquier texto genérico. Si viene, gana.
--}}
@include('errors.partials.marco', [
    'codigo' => 403,
    'titulo' => __('errores.403.titulo'),
    'detalle' => ($exception ?? null)?->getMessage() ?: __('errores.403.detalle'),
    'volver' => __('errores.403.volver'),
])
