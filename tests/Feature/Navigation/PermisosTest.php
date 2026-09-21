<?php

declare(strict_types=1);

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Permission;

/*
|--------------------------------------------------------------------------
| Permisos sembrados y quién los pregunta
|--------------------------------------------------------------------------
|
| Un permiso que nadie consulta no abre nada —ninguna ruta lo exige— pero
| miente sobre lo que el sistema sabe hacer: la pantalla de roles lo ofrece,
| alguien se lo asigna a un usuario, y ese usuario cree que puede hacer algo
| que no existe.
|
| Pasó dos veces y en las dos el permiso llegó antes que la función:
| `recepciones.revertir` estuvo sembrado sin uso desde la tanda del motor
| hasta que se construyó la reversión, y `recepciones.reconocer-excedente`
| sigue esperando su puerta.
|
| Ahora falla. Un permiso nuevo sin consumidor rompe CI, y quien lo agregue
| tiene que elegir: exigirlo en algún lado, o anotarlo acá abajo diciendo
| qué está esperando.
|
*/

/**
 * Permisos sembrados que todavía no pregunta nadie.
 *
 * No son un descuido: cada uno espera algo concreto, y está escrito qué.
 */
const PERMISOS_SIN_CONSUMIDOR = [
    /*
     * Espera la pantalla del excedente —punto 48 de las correcciones—. La
     * columna `fund_receipts.residual_status` modela el estado y nada lo
     * escribe: falta el Action que lo ponga, con su motivo y su auditoría.
     */
    'recepciones.reconocer-excedente',

    /*
     * Las fotos de comprobante sí se suben, pero gobernadas por el permiso
     * de la operación que las contiene —`depositos.registrar`,
     * `traslados.registrar`—, no por uno propio. El adjunto no es un acto
     * independiente: nace pegado a lo que documenta. Queda por si alguna
     * vez hay una carga suelta; si se decide que no la habrá, se saca.
     */
    'adjuntos.cargar',

    /*
     * Espera la pantalla de talonarios. Las series se siembran y se
     * consumen al emitir un comprobante, pero no hay dónde mirarlas.
     */
    'series.ver',
];

/**
 * Dónde puede estar preguntándose un permiso.
 *
 * Se lee el código fuente y no el contenedor de rutas porque un permiso
 * también se pregunta dentro de un controlador —`$user->can('x')` para
 * decidir qué props mandar— y eso no aparece en el middleware de ninguna
 * ruta.
 */
function permisosConsumidos(): array
{
    $encontrados = [];

    foreach (['app', 'routes', 'resources'] as $carpeta) {
        foreach (File::allFiles(base_path($carpeta)) as $archivo) {
            if (! in_array($archivo->getExtension(), ['php', 'tsx', 'ts'], true)) {
                continue;
            }

            preg_match_all(
                "/(?:can:|can\('|cannot\('|authorize\(')([a-z0-9.\-]+\.[a-z0-9.\-]+)/",
                (string) File::get($archivo->getPathname()),
                $coincidencias,
            );

            foreach ($coincidencias[1] as $permiso) {
                $encontrados[$permiso] = true;
            }
        }
    }

    return array_keys($encontrados);
}

test('todo permiso sembrado lo pregunta alguien', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $consumidos = permisosConsumidos();

    $huerfanos = Permission::query()
        ->pluck('name')
        ->reject(fn (string $permiso): bool => in_array($permiso, $consumidos, true))
        ->reject(fn (string $permiso): bool => in_array($permiso, PERMISOS_SIN_CONSUMIDOR, true))
        ->sort()
        ->values()
        ->all();

    expect($huerfanos)->toBe(
        [],
        'Estos permisos se siembran y no los exige ninguna ruta ni los '
        .'pregunta ningún controlador. Usalos donde corresponda, o sumalos '
        .'a PERMISOS_SIN_CONSUMIDOR explicando qué están esperando.',
    );
});

/**
 * Y al revés: la lista de excepciones no puede envejecer.
 *
 * Cuando se construye la función que el permiso esperaba, lo que falla es
 * esto —y obliga a sacarlo de la lista— en vez de dejar una excepción que
 * ya no excepciona nada.
 */
test('no sobra ninguna excepción contra un permiso que ya se usa', function (): void {
    $consumidos = permisosConsumidos();

    $deMas = collect(PERMISOS_SIN_CONSUMIDOR)
        ->filter(fn (string $permiso): bool => in_array($permiso, $consumidos, true))
        ->values()
        ->all();

    expect($deMas)->toBe(
        [],
        'Estos permisos ya se preguntan en algún lado: sacalos de '
        .'PERMISOS_SIN_CONSUMIDOR.',
    );
});

/** Ni contra uno que dejó de sembrarse. */
test('no sobra ninguna excepción contra un permiso que ya no existe', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);

    $sembrados = Permission::query()->pluck('name')->all();

    $deMas = collect(PERMISOS_SIN_CONSUMIDOR)
        ->reject(fn (string $permiso): bool => in_array($permiso, $sembrados, true))
        ->values()
        ->all();

    expect($deMas)->toBe([]);
});
