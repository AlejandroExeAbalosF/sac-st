<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\InitialPasswordController;
use App\Http\Controllers\InicioController;
use Illuminate\Support\Facades\Route;

/*
| El sistema es una intranet: no hay portada pública que presentar. La
| raíz manda al tablero si hay sesión y al login si no.
*/
Route::get('/', fn () => redirect()->route(auth()->check() ? 'inicio' : 'login'))
    ->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('inicio', InicioController::class)->name('inicio');
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
// Shared → Ledger → Banking → Haberes.
require __DIR__.'/modules/shared.php';
require __DIR__.'/modules/ledger.php';
require __DIR__.'/modules/banking.php';
require __DIR__.'/modules/haberes.php';
