<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\InitialPasswordController;
use App\Http\Controllers\InicioController;
use Illuminate\Routing\RedirectController;
use Illuminate\Support\Facades\Route;

/*
| El sistema es una intranet: no hay portada pública que presentar. La
| raíz manda al tablero si hay sesión y al login si no.
*/
Route::get('/', fn () => redirect()->route(auth()->check() ? 'inicio' : 'login'))
    ->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('inicio', InicioController::class)->name('inicio');
    Route::get('inicio/buscar', [InicioController::class, 'buscar'])
        ->middleware('throttle:120,1')
        ->name('inicio.buscar');
    Route::get('haberes/inicio', [InicioController::class, 'haberes'])->name('inicio.haberes');
    Route::get('multas/inicio', [InicioController::class, 'multas'])->name('inicio.multas');

    // Solo en GET: `Route::redirect` responde a todos los verbos, DELETE incluido.
    Route::get('inicio/haberes', RedirectController::class)
        ->defaults('destination', '/haberes/inicio')
        ->defaults('status', 302);
    Route::get('inicio/multas', RedirectController::class)
        ->defaults('destination', '/multas/inicio')
        ->defaults('status', 302);
});

/*
| El primer ingreso: donde se deja la contraseña temporal. Es la única
| pantalla que atiende `ForcePasswordChange` mientras la marca siga puesta,
| y sin la marca devuelve al tablero.
*/
Route::middleware(['auth'])->group(function () {
    Route::get('primer-ingreso', [InitialPasswordController::class, 'edit'])
        ->name('primer-ingreso');

    Route::put('primer-ingreso', [InitialPasswordController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('primer-ingreso.update');
});

require __DIR__.'/mi-cuenta.php';

// Un archivo por módulo, en el mismo orden de dependencia que el código:
// Shared → Ledger → Banking → Haberes y Multas.
require __DIR__.'/modules/shared.php';
require __DIR__.'/modules/ledger.php';
require __DIR__.'/modules/banking.php';
require __DIR__.'/modules/haberes.php';
require __DIR__.'/modules/multas.php';
