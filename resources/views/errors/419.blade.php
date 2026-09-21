@include('errors.partials.marco', [
    'codigo' => 419,
    'titulo' => __('errores.419.titulo'),
    'detalle' => __('errores.419.detalle'),
    'volver' => __('errores.419.volver'),
    'volverA' => route('login'),
])
