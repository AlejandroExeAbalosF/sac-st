<?php

declare(strict_types=1);

use App\Http\Controllers\Settings\AccountActivityController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

/*
| La cuenta del propio usuario.
|
| Se llama «Mi cuenta» y no «Perfil» porque contiene más que el perfil: la
| seguridad y la actividad de la sesión también son suyas. «Perfil» pasa a
| ser lo que siempre fue, la primera de sus secciones.
|
| Nada de acá lleva permiso: cada uno administra lo suyo. La administración
| del sistema —usuarios, roles, auditoría— vive en `modules/shared.php`,
| bajo «Configuración», y esa sí exige permisos.
*/

Route::middleware(['auth'])->group(function (): void {
    Route::get('mi-cuenta', [ProfileController::class, 'edit'])->name('mi-cuenta.perfil');
    Route::patch('mi-cuenta', [ProfileController::class, 'update'])->name('mi-cuenta.perfil.update');
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    /*
     * Cambiar la contraseña exige haberla confirmado antes. Es la misma
     * puerta que protege el segundo factor y las passkeys: quien se
     * encuentra una sesión abierta no puede quedarse con la cuenta.
     */
    Route::get('mi-cuenta/seguridad', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('mi-cuenta.seguridad');

    Route::put('mi-cuenta/contrasena', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    /*
     * Las sesiones abiertas y el historial propio. Los datos ya se venían
     * escribiendo desde el primer día en `user_login_events`; hasta acá
     * nadie los leía más que el tablero, y en sus últimas cinco filas.
     */
    Route::get('mi-cuenta/actividad', [AccountActivityController::class, 'index'])
        ->name('mi-cuenta.actividad');

    Route::delete('mi-cuenta/sesiones', [AccountActivityController::class, 'destroyOtherSessions'])
        ->name('mi-cuenta.sesiones.destroy');

    /*
     * La pantalla de apariencia queda fuera mientras el modo oscuro esté
     * apagado: ofrecerla sin que haga nada confunde más de lo que aporta.
     * Ver DARK_MODE_ENABLED en resources/js/hooks/use-appearance.tsx.
     */
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('mi-cuenta.seguridad'),
        'manage' => route('mi-cuenta.seguridad'),
    ]);
})->name('well-known.passkeys');
