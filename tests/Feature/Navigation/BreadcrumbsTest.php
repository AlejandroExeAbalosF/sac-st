<?php

declare(strict_types=1);

use App\Support\Navigation\Breadcrumbs;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Migas de navegación
|--------------------------------------------------------------------------
|
| El encabezado es lo único que le dice al operador dónde está parado dentro
| de un circuito de tres o cuatro niveles. Antes de este test, quince de las
| veintiséis pantallas no declaraban camino y el encabezado quedaba vacío: no
| se notaba porque nada fallaba.
|
| Ahora sí falla. Una pantalla nueva sin camino rompe CI, y quien la agregue
| tiene que elegir: declararle el camino en `routes/breadcrumbs.php`, o
| anotarla acá abajo diciendo por qué no dibuja un encabezado.
|
*/

/**
 * Rutas GET con sesión que no dibujan una pantalla del sistema.
 *
 * No llevan migas porque no hay encabezado donde ponerlas: devuelven un PDF,
 * un archivo o un JSON que alimenta un componente.
 */
const RUTAS_SIN_PANTALLA = [
    // Flujo de autenticación: usan AuthLayout, que no tiene encabezado.
    'verification.notice',
    'password.confirm',
    'password.confirmation',

    // Archivos.
    'adjuntos.download',
    'adjuntos.preview',
    'caja.cierres.sheet',

    // Acceso anterior a la caja del día; ahora redirige a su URL canónica.
    'caja.index',

    // Comprobantes en PDF, para imprimir o previsualizar.
    'ordenes.print',
    'ordenes.view',
    'pases.print',
    'pases.view',
    'recibos.print',
    'recibos.view',
    'haberes.installments.order.preview',
    'haberes.installments.pase.preview',
    // Las mismas dos hojas en PDF, que es lo que se manda al papel. La
    // previa devuelve HTML para meter en el iframe; éstas las abre el
    // lector del navegador.
    'haberes.installments.order.preview.print',
    'haberes.installments.pase.preview.print',
    'haberes.installments.receipt.preview',
    'haberes.installments.disbursement.preview',

    /*
     * Las planillas de pendientes. No son comprobantes ni pantallas: son
     * hojas de trabajo que salen de la cola que se está mirando, y el
     * encabezado lo dibuja la pantalla desde la que se piden.
     */
    'depositos.planilla',
    'planillas.print',

    // JSON que consumen componentes de una pantalla ya abierta.
    'people.index',
    'people.resolve',
    'traslados.candidates',
    'haberes.installments.history',
    'expedientes.history',
    'haberes.haber.history',
    'recibos.panel',
    'haberes.installments.disbursement.debits',

    /*
     * Redirección heredada: se definió sin `->name()` dentro del grupo
     * `->name('banco.')`, así que quedó registrada con el prefijo del grupo
     * como nombre. No es una pantalla, es un atajo a `banco.extractos.create`.
     */
    'banco.',
];

test('cada pantalla del sistema declara su camino de migas', function (): void {
    $sinCamino = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => in_array('GET', $route->methods(), true))
        ->filter(fn (RoutingRoute $route): bool => in_array('auth', $route->gatherMiddleware(), true))
        ->map(fn (RoutingRoute $route): ?string => $route->getName())
        ->filter()
        ->reject(fn (string $name): bool => in_array($name, RUTAS_SIN_PANTALLA, true))
        ->reject(fn (string $name): bool => Breadcrumbs::has($name))
        ->values()
        ->all();

    expect($sinCamino)->toBe(
        [],
        'Estas rutas dibujan una pantalla y no tienen camino declarado. '
        .'Agregalo en routes/breadcrumbs.php, o sumalas a RUTAS_SIN_PANTALLA '
        .'explicando por qué no dibujan un encabezado.',
    );
});

test('no sobra ningún camino declarado contra una ruta que ya no existe', function (): void {
    $huerfanos = collect(Breadcrumbs::registered())
        ->reject(fn (string $name): bool => Route::has($name))
        ->values()
        ->all();

    expect($huerfanos)->toBe([]);
});

test('el camino del listado de haberes tiene un solo eslabón', function (): void {
    expect(Breadcrumbs::resolve('expedientes.index'))->toBe([
        ['title' => 'Haberes', 'href' => null],
    ]);
});

test('las pantallas hermanas de caja tienen una sola miga', function (): void {
    foreach ([
        'caja.dia' => 'Caja del día',
        'caja.arqueos.index' => 'Arqueos',
        'caja.cierres.index' => 'Cierres',
        'caja.calendario' => 'Calendario',
    ] as $ruta => $titulo) {
        expect(Breadcrumbs::resolve($ruta))->toBe([
            ['title' => $titulo, 'href' => null],
        ]);
    }
});

test('las pantallas auxiliares vuelven a caja del día', function (): void {
    foreach (['caja.apertura.index', 'caja.pagos-anteriores.index'] as $ruta) {
        expect(Breadcrumbs::resolve($ruta)[0])->toBe([
            'title' => 'Caja del día',
            'href' => route('caja.dia'),
        ]);
    }
});

test('una ruta sin camino declarado devuelve un camino vacío y no una excepción', function (): void {
    expect(Breadcrumbs::resolve('ruta.que.no.existe'))->toBe([])
        ->and(Breadcrumbs::resolve(null))->toBe([]);
});
