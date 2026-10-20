<?php

declare(strict_types=1);

use App\Modules\Multas\Http\Controllers\FineAttachmentController;
use App\Modules\Multas\Http\Controllers\FineCaseController;
use App\Modules\Multas\Http\Controllers\FineCashController;
use App\Modules\Multas\Http\Controllers\FineController;
use App\Modules\Multas\Http\Controllers\FineDebtController;
use App\Modules\Multas\Http\Controllers\FineDocumentController;
use App\Modules\Multas\Http\Controllers\FineHistoricalMovementController;
use Illuminate\Support\Facades\Route;

/*
| Multas: la cuenta corriente de multas laborales y de higiene y seguridad.
|
| En esta etapa se registra qué se debe y por qué papel: expedientes,
| documentos, multas, ajustes de la obligación y pagos anteriores al sistema.
| Los cobros, la caja y el banco llegan en las etapas siguientes.
|
| Completar un dato que faltaba es parte de cargar (`multas.registrar`);
| corregir uno que ya tenía valor exige `multas.ajustar`, y eso lo decide
| `ReviseFineIdentifiers`, que es quien sabe qué cambió.
*/
Route::middleware(['auth', 'verified'])->prefix('multas')->name('multas.')->group(function (): void {
    Route::middleware('can:multas.registrar')->group(function (): void {
        // Antes de `{fine}`: una ruta literal delante de una con parámetro.
        Route::get('nueva', [FineController::class, 'create'])->name('create');
        Route::post('/', [FineController::class, 'store'])->name('store');

        Route::post('{fine}/documentos', [FineDocumentController::class, 'store'])->whereNumber('fine')->name('documentos.store');
        Route::post('{fine}/antecedentes', [FineHistoricalMovementController::class, 'store'])->whereNumber('fine')->name('antecedentes.store');
        Route::post('{fine}/antecedentes/credito', [FineHistoricalMovementController::class, 'storeFromBankCredit'])
            ->whereNumber('fine')
            ->name('antecedentes.credito.store');

        Route::post('expedientes/{fineCase}/documentos', [FineCaseController::class, 'storeDocument'])
            ->whereNumber('fineCase')
            ->name('expedientes.documentos.store');

        // Adjuntar es parte de cargar: el papel aparece después (M11).
        Route::post('expedientes/{fineCase}/adjuntos', [FineAttachmentController::class, 'storeForCase'])
            ->whereNumber('fineCase')
            ->name('expedientes.adjuntos.store');
        Route::post('{fine}/adjuntos', [FineAttachmentController::class, 'storeForFine'])->whereNumber('fine')->name('adjuntos.store');
        Route::post('antecedentes/{movement}/adjuntos', [FineAttachmentController::class, 'storeForMovement'])
            ->whereNumber('movement')
            ->name('antecedentes.adjuntos.store');
    });

    /*
     * Las revisiones las puede pedir quien carga —para completar lo que
     * faltaba— y quien ajusta —para corregir lo ya cargado—. Cuál de las dos
     * es lo decide el Action al ver qué cambió, así que la ruta solo pide
     * ver y el controlador exige uno de los dos permisos.
     */
    Route::middleware('can:multas.ver')->group(function (): void {
        Route::post('{fine}/revisiones', [FineController::class, 'revise'])->whereNumber('fine')->name('revisiones.store');
        Route::post('expedientes/{fineCase}/revisiones', [FineCaseController::class, 'revise'])
            ->whereNumber('fineCase')
            ->name('expedientes.revisiones.store');
        Route::post('documentos/{fineDocument}/revisiones', [FineDocumentController::class, 'revise'])
            ->whereNumber('fineDocument')
            ->name('documentos.revisiones.store');
        Route::post('movimientos/{entry}/revisiones', [FineDebtController::class, 'reviseDate'])
            ->whereNumber('entry')
            ->name('movimientos.revisiones.store');
        Route::post('antecedentes/{movement}/revisiones', [FineHistoricalMovementController::class, 'reviseDate'])
            ->whereNumber('movement')
            ->name('antecedentes.revisiones.store');
    });

    Route::middleware('can:multas.ajustar')->group(function (): void {
        Route::post('{fine}/ajustes', [FineDebtController::class, 'store'])->whereNumber('fine')->name('ajustes.store');
        Route::post('ajustes/{entry}/reversion', [FineDebtController::class, 'reverse'])->whereNumber('entry')->name('ajustes.reverse');
        Route::post('antecedentes/{movement}/revision', [FineHistoricalMovementController::class, 'review'])
            ->whereNumber('movement')
            ->name('antecedentes.review');
    });

    /*
     * La apertura de la caja: una sola vez, como la de Haberes. El saldo
     * de la cuenta bancaria no va acá: es compartido y se declara en
     * Banco › Cuentas.
     */
    Route::middleware('can:multas.caja.abrir')->group(function (): void {
        Route::post('caja/apertura', [FineCashController::class, 'store'])->name('caja.apertura.store');
    });

    Route::middleware('can:multas.ver')->group(function (): void {
        Route::get('/', [FineController::class, 'index'])->name('index');
        Route::get('caja', [FineCashController::class, 'index'])->name('caja.index');
        Route::get('exportar', [FineController::class, 'export'])->name('exportar');
        Route::get('expedientes/{fineCase}', [FineCaseController::class, 'show'])->whereNumber('fineCase')->name('expedientes.show');
        Route::get('{fine}', [FineController::class, 'show'])->whereNumber('fine')->name('show');
    });
});
