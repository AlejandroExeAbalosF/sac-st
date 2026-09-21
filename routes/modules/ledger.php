<?php

declare(strict_types=1);

use App\Modules\Ledger\Http\Controllers\CashCalendarController;
use App\Modules\Ledger\Http\Controllers\CashController;
use App\Modules\Ledger\Http\Controllers\CashCountController;
use App\Modules\Ledger\Http\Controllers\LegacyDisbursementController;
use App\Modules\Ledger\Http\Controllers\OpeningBalanceController;
use App\Modules\Ledger\Http\Controllers\PeriodClosingController;
use Illuminate\Support\Facades\Route;

/*
| Motor contable: eventos financieros, líneas del diario, recepciones,
| asignaciones, arqueos y cierres de período.
|
| Las tres pantallas cierran el recorrido del dinero: la caja del día
| muestra cuánto hay y de dónde salió, el arqueo lo cuenta contra el
| cajón, y el cierre lo congela.
|
| Los eventos y las líneas del diario **no tienen pantalla propia y no
| deberían tenerla**: nadie edita un asiento a mano. Se llega a ellos por
| el hecho que los produjo —la recepción, el egreso, el traslado— que es
| donde el operador entiende qué está mirando.
*/

Route::middleware(['auth', 'verified'])->prefix('caja')->name('caja.')->group(function (): void {
    Route::middleware('can:caja.ver')->group(function (): void {
        Route::get('/', [CashController::class, 'redirect'])->name('index');
        Route::get('dia', [CashController::class, 'index'])->name('dia');
        Route::get('arqueos', [CashCountController::class, 'index'])->name('arqueos.index');
    });

    /*
     * La apertura de los libros: el primer acto de la vida del sistema, y
     * uno solo. Va con permiso de administrador porque define todos los
     * saldos posteriores — un error acá no se corrige editando, se revierte.
     */
    Route::middleware('can:caja.abrir-saldo-inicial')->group(function (): void {
        Route::get('apertura', [OpeningBalanceController::class, 'index'])->name('apertura.index');
        Route::post('apertura', [OpeningBalanceController::class, 'store'])->name('apertura.store');
    });

    /*
     * Los pagos del sistema anterior: la única salida de `LEGACY_FUNDS`.
     * Sin ellos esa cuenta sube y no baja, y los casos viejos se siguen
     * pagando por planilla en paralelo.
     */
    Route::middleware('can:caja.pagar-anterior')->group(function (): void {
        Route::get('pagos-anteriores', [LegacyDisbursementController::class, 'index'])
            ->name('pagos-anteriores.index');
        Route::post('pagos-anteriores', [LegacyDisbursementController::class, 'store'])
            ->name('pagos-anteriores.store');
    });

    Route::middleware('can:caja.arquear')->group(function (): void {
        Route::post('arqueos', [CashCountController::class, 'store'])->name('arqueos.store');
    });

    Route::middleware('can:caja.revisar-arqueo')->group(function (): void {
        Route::post('arqueos/{cashCount}/revisar', [CashCountController::class, 'review'])
            ->whereNumber('cashCount')
            ->name('arqueos.review');
    });

    Route::middleware('can:caja.ajustar-diferencia')->group(function (): void {
        Route::post('arqueos/{cashCount}/imputar', [CashCountController::class, 'adjust'])
            ->whereNumber('cashCount')
            ->name('arqueos.adjust');
    });

    Route::middleware('can:cierres.ver')->group(function (): void {
        Route::get('cierres', [PeriodClosingController::class, 'index'])->name('cierres.index');
        /*
         * El calendario es otra forma de mirar los mismos cierres, así que
         * va con el mismo permiso. Lo que agrega es lo que una lista no
         * puede mostrar: los días que quedaron sin cerrar.
         */
        Route::get('calendario', [CashCalendarController::class, 'index'])->name('calendario');
    });

    Route::middleware('can:cierres.cerrar')->group(function (): void {
        Route::post('cierres', [PeriodClosingController::class, 'store'])->name('cierres.store');
    });

    Route::middleware('can:cierres.reabrir')->group(function (): void {
        Route::post('cierres/{closing}/reabrir', [PeriodClosingController::class, 'reopen'])
            ->whereNumber('closing')
            ->name('cierres.reopen');
    });

    /*
     * La planilla es un GET porque se pide para leerla, aunque la primera
     * vez escriba el adjunto. Es el mismo criterio que un comprobante que
     * se numera al imprimirse: el efecto se produce una vez y las demás
     * devuelve lo mismo.
     */
    Route::middleware('can:cierres.exportar')->group(function (): void {
        Route::get('cierres/{closing}/planilla', [PeriodClosingController::class, 'sheet'])
            ->whereNumber('closing')
            ->name('cierres.sheet');
    });

    /*
     * Rehacerla sí es un POST: produce un documento nuevo cada vez que se
     * pide, y eso no puede viajar en un enlace que el navegador precargue.
     */
    Route::middleware('can:cierres.regenerar-planilla')->group(function (): void {
        Route::post('cierres/{closing}/planilla/regenerar', [PeriodClosingController::class, 'regenerateSheet'])
            ->whereNumber('closing')
            ->name('cierres.sheet.regenerate');
    });
});
