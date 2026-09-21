@include('errors.partials.marco', [
    'codigo' => 401,
    'titulo' => __('errores.401.titulo'),
    'detalle' => __('errores.401.detalle'),
    'volver' => __('errores.401.volver'),
    'volverA' => route('login'),
])
