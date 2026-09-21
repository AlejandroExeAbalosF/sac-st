@include('errors.partials.marco', [
    'codigo' => 404,
    'titulo' => __('errores.404.titulo'),
    'detalle' => __('errores.404.detalle'),
    'volver' => __('errores.404.volver'),
])
