<?php

declare(strict_types=1);

use App\Modules\Shared\Http\Controllers\AccessAuditController;
use App\Modules\Shared\Http\Controllers\AttachmentController;
use App\Modules\Shared\Http\Controllers\PersonController;
use App\Modules\Shared\Http\Controllers\RoleController;
use App\Modules\Shared\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/*
| Núcleo transversal: personas, cuentas bancarias de personas, cajas,
| series de numeración, recibos, adjuntos y auditoría.
*/

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('personas', [PersonController::class, 'page'])
        ->middleware('can:personas.ver')
        ->name('personas.index');
    Route::patch('personas/{person}', [PersonController::class, 'update'])
        ->middleware('can:personas.editar')
        ->name('personas.update');

    Route::get('people', [PersonController::class, 'index'])
        ->middleware('can:personas.ver')
        ->name('people.index');
    Route::get('people/resolve', [PersonController::class, 'resolve'])
        ->middleware('can:personas.ver')
        ->name('people.resolve');
    Route::post('people', [PersonController::class, 'store'])
        ->middleware('can:personas.crear')
        ->name('people.store');

    /*
     * Adjuntos. Los archivos viven en el disco privado y esta es la única
     * puerta que los sirve: no hay URL adivinable que los exponga.
     *
     * `preview` los muestra en pantalla en vez de descargarlos, que es lo
     * que permite tener la foto del ticket al lado del formulario mientras
     * se copian los datos.
     */
    Route::middleware('can:adjuntos.ver')->group(function (): void {
        Route::get('adjuntos/{attachment}', [AttachmentController::class, 'download'])
            ->whereNumber('attachment')
            ->name('adjuntos.download');
        Route::get('adjuntos/{attachment}/ver', [AttachmentController::class, 'preview'])
            ->whereNumber('attachment')
            ->name('adjuntos.preview');
    });

    /*
     * Configuración del sistema.
     *
     * Es lo que administra el sistema, no la cuenta de quien lo usa: eso
     * vive en `mi-cuenta.php` y no lleva permiso. La distinción importa
     * porque hasta ahora «Configuración» apuntaba al perfil propio, y el
     * operador entraba buscando administrar y se encontraba con su email.
     *
     * Los permisos de este bloque estaban sembrados desde la Fase 2 y
     * ninguna ruta los usaba.
     */
    Route::prefix('configuracion')->name('configuracion.')->group(function (): void {
        Route::middleware('can:usuarios.ver')->group(function (): void {
            Route::get('usuarios', [UserController::class, 'index'])->name('usuarios.index');
        });

        Route::post('usuarios', [UserController::class, 'store'])
            ->middleware('can:usuarios.crear')
            ->name('usuarios.store');

        Route::patch('usuarios/{user}', [UserController::class, 'update'])
            ->whereNumber('user')
            ->middleware('can:usuarios.editar')
            ->name('usuarios.update');

        Route::patch('usuarios/{user}/estado', [UserController::class, 'setActive'])
            ->whereNumber('user')
            ->middleware('can:usuarios.desactivar')
            ->name('usuarios.estado');

        Route::post('usuarios/{user}/contrasena', [UserController::class, 'resetPassword'])
            ->whereNumber('user')
            ->middleware(['can:usuarios.restablecer-password', 'throttle:6,1'])
            ->name('usuarios.contrasena');

        Route::middleware('can:roles.gestionar')->group(function (): void {
            Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
            Route::put('roles/{role}', [RoleController::class, 'update'])
                ->whereNumber('role')
                ->name('roles.update');
        });

        Route::get('accesos', [AccessAuditController::class, 'index'])
            ->middleware('can:auditoria.accesos.ver')
            ->name('accesos.index');

        Route::delete('sesiones', [AccessAuditController::class, 'destroySession'])
            ->middleware('can:auditoria.sesiones.revocar')
            ->name('sesiones.destroy');
    });
});
