@include('errors.partials.marco', [
    'codigo' => 500,
    'titulo' => __('errores.500.titulo'),
    'detalle' => __('errores.500.detalle'),
    'volver' => __('errores.500.volver'),
])
