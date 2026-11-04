<?php

declare(strict_types=1);

use App\Modules\Aranceles\Http\Controllers\FeeCashController;
use App\Modules\Aranceles\Http\Controllers\FeeChargeController;
use App\Modules\Aranceles\Http\Controllers\FeeTypeController;
use App\Modules\Banking\Http\Controllers\CashBoxAccountController;
use App\Modules\Ledger\Http\Controllers\CashCalendarController;
use App\Modules\Ledger\Http\Controllers\CashController;
use App\Modules\Ledger\Http\Controllers\CashCountController;
use App\Modules\Ledger\Http\Controllers\PeriodClosingController;
use Illuminate\Support\Facades\Route;

/*
| Aranceles: el catálogo, los cobros con su recibo y la caja.
|
| Mirar es de quien ve aranceles; cada acto lleva su permiso.
*/
Route::middleware(['auth', 'verified'])->prefix('aranceles')->name('aranceles.')->group(function (): void {
    Route::middleware('can:aranceles.ver')->group(function (): void {
        Route::get('tipos', [FeeTypeController::class, 'index'])->name('tipos.index');
        Route::get('cobros', [FeeChargeController::class, 'index'])->name('cobros.index');
        Route::get('caja/apertura', [FeeCashController::class, 'apertura'])->name('caja.apertura.index');
        // El recibo: el mismo papel en el visor y en la impresora.
        Route::get('recibos/{receipt}/ver', [FeeChargeController::class, 'viewReceipt'])->whereNumber('receipt')->name('recibos.view');
        Route::get('recibos/{receipt}/imprimir', [FeeChargeController::class, 'printReceipt'])->whereNumber('receipt')->name('recibos.print');
    });

    Route::middleware('can:aranceles.catalogo')->group(function (): void {
        Route::post('tipos', [FeeTypeController::class, 'store'])->name('tipos.store');
        Route::patch('tipos/{feeType}', [FeeTypeController::class, 'update'])->whereNumber('feeType')->name('tipos.update');
        Route::post('tipos/{feeType}/precios', [FeeTypeController::class, 'storePrice'])->whereNumber('feeType')->name('tipos.precios.store');
        Route::post('precios/{feeTypePrice}/anular', [FeeTypeController::class, 'voidPrice'])->whereNumber('feeTypePrice')->name('precios.anular');
    });

    Route::middleware('can:aranceles.cobrar')->group(function (): void {
        Route::get('nuevo', [FeeChargeController::class, 'create'])->name('cobros.create');
        // La vista previa va antes del cobro y con el mismo permiso.
        Route::get('cobros/previsualizar', [FeeChargeController::class, 'preview'])->name('cobros.preview');
        Route::post('cobros', [FeeChargeController::class, 'store'])->name('cobros.store');
    });

    Route::middleware('can:aranceles.anular')->group(function (): void {
        Route::post('cobros/{feeCharge}/anular', [FeeChargeController::class, 'void'])->whereNumber('feeCharge')->name('cobros.anular');
    });

    Route::middleware('can:aranceles.reemplazar-recibos')->group(function (): void {
        Route::post('cobros/{feeCharge}/reemplazar-recibo', [FeeChargeController::class, 'replace'])->whereNumber('feeCharge')->name('cobros.reemplazar');
    });

    Route::middleware('can:aranceles.corregir')->group(function (): void {
        Route::get('cobros/{feeCharge}/corregir', [FeeChargeController::class, 'editCorrection'])->whereNumber('feeCharge')->name('cobros.corregir.edit');
        Route::post('cobros/{feeCharge}/corregir', [FeeChargeController::class, 'correct'])->whereNumber('feeCharge')->name('cobros.corregir');
    });

    Route::middleware('can:aranceles.caja.abrir')->group(function (): void {
        Route::post('caja/apertura', [FeeCashController::class, 'store'])->name('caja.apertura.store');
    });
});

/*
| La caja del día de Aranceles: son las pantallas de caja de Ledger, con su caja
| (`caja:aranceles`) y sus permisos propios.
*/
Route::middleware(['auth', 'verified', 'caja:aranceles'])->prefix('aranceles/caja')->name('aranceles.caja.')->group(function (): void {
    Route::middleware('can:aranceles.caja.ver')->group(function (): void {
        Route::get('cuentas', [CashBoxAccountController::class, 'index'])->name('cuentas.index');
        Route::get('dia', [CashController::class, 'index'])->name('dia');
        Route::get('arqueos', [CashCountController::class, 'index'])->name('arqueos.index');
        Route::get('cierres', [PeriodClosingController::class, 'index'])->name('cierres.index');
        Route::get('calendario', [CashCalendarController::class, 'index'])->name('calendario');
    });

    Route::middleware('can:aranceles.caja.cuentas')->group(function (): void {
        Route::post('cuentas', [CashBoxAccountController::class, 'store'])->name('cuentas.store');
        Route::post('cuentas/{link}/baja', [CashBoxAccountController::class, 'retire'])->whereNumber('link')->name('cuentas.retire');
        Route::post('cuentas/{link}/predeterminada', [CashBoxAccountController::class, 'default'])->whereNumber('link')->name('cuentas.default');
        Route::post('cuentas/{link}/vigencia', [CashBoxAccountController::class, 'start'])->whereNumber('link')->name('cuentas.start');
    });

    Route::middleware('can:aranceles.caja.arquear')->group(function (): void {
        Route::post('arqueos', [CashCountController::class, 'store'])->name('arqueos.store');
    });

    Route::middleware('can:aranceles.caja.revisar-arqueo')->group(function (): void {
        Route::post('arqueos/{cashCount}/revisar', [CashCountController::class, 'review'])->whereNumber('cashCount')->name('arqueos.review');
    });

    Route::middleware('can:aranceles.caja.ajustar-diferencia')->group(function (): void {
        Route::post('arqueos/{cashCount}/imputar', [CashCountController::class, 'adjust'])->whereNumber('cashCount')->name('arqueos.adjust');
    });

    Route::middleware('can:aranceles.caja.cerrar')->group(function (): void {
        Route::post('cierres', [PeriodClosingController::class, 'store'])->name('cierres.store');
    });

    Route::middleware('can:aranceles.caja.reabrir')->group(function (): void {
        Route::post('cierres/{closing}/reabrir', [PeriodClosingController::class, 'reopen'])->whereNumber('closing')->name('cierres.reopen');
    });

    Route::middleware('can:aranceles.caja.exportar')->group(function (): void {
        Route::get('cierres/{closing}/planilla', [PeriodClosingController::class, 'sheet'])->whereNumber('closing')->name('cierres.sheet');
    });

    Route::middleware('can:aranceles.caja.regenerar-planilla')->group(function (): void {
        Route::post('cierres/{closing}/planilla/regenerar', [PeriodClosingController::class, 'regenerateSheet'])->whereNumber('closing')->name('cierres.sheet.regenerate');
    });
});
