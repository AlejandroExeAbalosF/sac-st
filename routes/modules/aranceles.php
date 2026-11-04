<?php

declare(strict_types=1);

use App\Modules\Aranceles\Http\Controllers\FeeTypeController;
use Illuminate\Support\Facades\Route;

/*
| Aranceles: el catálogo de tipos y sus precios con vigencia.
|
| Mirarlo es de quien ve aranceles; cambiarlo, de `aranceles.catalogo`.
*/
Route::middleware(['auth', 'verified'])->prefix('aranceles')->name('aranceles.')->group(function (): void {
    Route::middleware('can:aranceles.ver')->group(function (): void {
        Route::get('tipos', [FeeTypeController::class, 'index'])->name('tipos.index');
    });

    Route::middleware('can:aranceles.catalogo')->group(function (): void {
        Route::post('tipos', [FeeTypeController::class, 'store'])->name('tipos.store');
        Route::patch('tipos/{feeType}', [FeeTypeController::class, 'update'])->whereNumber('feeType')->name('tipos.update');
        Route::post('tipos/{feeType}/precios', [FeeTypeController::class, 'storePrice'])->whereNumber('feeType')->name('tipos.precios.store');
        Route::post('precios/{feeTypePrice}/anular', [FeeTypeController::class, 'voidPrice'])->whereNumber('feeTypePrice')->name('precios.anular');
    });
});
