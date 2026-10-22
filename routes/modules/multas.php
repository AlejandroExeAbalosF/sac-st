<?php

declare(strict_types=1);

use App\Modules\Multas\Http\Controllers\FineAppealController;
use App\Modules\Multas\Http\Controllers\FineAttachmentController;
use App\Modules\Multas\Http\Controllers\FineBankPaymentController;
use App\Modules\Multas\Http\Controllers\FineCaseController;
use App\Modules\Multas\Http\Controllers\FineCashController;
use App\Modules\Multas\Http\Controllers\FineChequeRejectionController;
use App\Modules\Multas\Http\Controllers\FineController;
use App\Modules\Multas\Http\Controllers\FineDebtController;
use App\Modules\Multas\Http\Controllers\FineDepositController;
use App\Modules\Multas\Http\Controllers\FineDocumentController;
use App\Modules\Multas\Http\Controllers\FineHistoricalMovementController;
use App\Modules\Multas\Http\Controllers\FinePaymentController;
use App\Modules\Multas\Http\Controllers\FinePaymentQueueController;
use Illuminate\Support\Facades\Route;

/*
| Multas: la cuenta corriente de multas laborales y de higiene y seguridad.
|
| Se registra qué se debe y por qué papel —expedientes, documentos, multas,
| ajustes de la obligación y pagos anteriores al sistema— y se cobra en la
| caja con su recibo. La verificación bancaria llega en la etapa siguiente.
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

    /*
     * El cobro en la caja: efectivo o cheque, con su recibo en el acto (M02).
     */
    Route::middleware('can:multas.cobrar')->group(function (): void {
        Route::post('{fine}/cobros', [FinePaymentController::class, 'store'])->whereNumber('fine')->name('cobros.store');
    });

    /*
     * Lo que llega por el banco se informa —desde el mostrador o desde la
     * conciliación— y el recurso del 20 % se registra según su medio: cada
     * controlador exige el permiso que corresponde.
     */
    Route::middleware('can:multas.ver')->group(function (): void {
        Route::post('{fine}/pagos-bancarios', [FineBankPaymentController::class, 'store'])->whereNumber('fine')->name('pagos-bancarios.store');
        Route::post('pagos/{payment}/adjuntos', [FineBankPaymentController::class, 'attach'])->whereNumber('payment')->name('pagos.adjuntos.store');
        Route::post('{fine}/recursos', [FineAppealController::class, 'store'])->whereNumber('fine')->name('recursos.store');
    });

    // Lo cobrado en la caja va al banco entero, cada cobro en su depósito (M06).
    Route::middleware('can:multas.depositar')->group(function (): void {
        Route::get('pagos/{payment}/deposito', [FineDepositController::class, 'create'])->whereNumber('payment')->name('pagos.deposito.create');
        Route::post('pagos/{payment}/deposito', [FineDepositController::class, 'store'])->whereNumber('payment')->name('pagos.deposito.store');
    });

    /*
     * El cheque rechazado (M03): el rechazo, lo que trae el extracto y lo que
     * se completa después son de quien concilia; el reemplazo, de la caja.
     */
    Route::middleware('can:multas.gestionar-rechazos')->group(function (): void {
        Route::post('pagos/{payment}/rechazo', [FineChequeRejectionController::class, 'store'])->whereNumber('payment')->name('pagos.rechazo.store');
        Route::get('pagos/{payment}/rechazo/debitos', [FineChequeRejectionController::class, 'debitOptions'])->whereNumber('payment')->name('pagos.rechazo.debitos');
        Route::get('rechazos/{rejection}/movimientos', [FineChequeRejectionController::class, 'movementOptions'])->whereNumber('rejection')->name('rechazos.movimientos.index');
        Route::post('rechazos/{rejection}/movimientos', [FineChequeRejectionController::class, 'movements'])->whereNumber('rejection')->name('rechazos.movimientos.store');
        Route::post('rechazos/{rejection}/no-acreditado', [FineChequeRejectionController::class, 'neverCredited'])->whereNumber('rejection')->name('rechazos.no-acreditado');
        Route::post('rechazos/{rejection}/completar', [FineChequeRejectionController::class, 'complete'])->whereNumber('rejection')->name('rechazos.completar');
    });
    Route::middleware('can:multas.cobrar')->group(function (): void {
        Route::post('rechazos/{rejection}/reemplazo', [FineChequeRejectionController::class, 'replace'])->whereNumber('rejection')->name('rechazos.reemplazo');
    });

    // Verificar contra el extracto, o descartar, es de quien concilia.
    Route::middleware('can:multas.verificar-pagos')->group(function (): void {
        Route::get('traslados/{transfer}/candidatos', [FineDepositController::class, 'candidates'])->whereNumber('transfer')->name('traslados.candidates');
        Route::post('traslados/{transfer}/acreditar', [FineDepositController::class, 'confirm'])->whereNumber('transfer')->name('traslados.confirm');
        Route::post('traslados/{transfer}/cancelar', [FineDepositController::class, 'cancel'])->whereNumber('transfer')->name('traslados.cancel');
        Route::get('pagos', [FinePaymentQueueController::class, 'index'])->name('pagos.index');
        Route::post('{fine}/pagos-bancarios/verificados', [FineBankPaymentController::class, 'storeVerified'])
            ->whereNumber('fine')
            ->name('pagos-bancarios.verificados.store');
        Route::post('pagos/{payment}/verificacion', [FineBankPaymentController::class, 'verify'])->whereNumber('payment')->name('pagos.verify');
        Route::post('pagos/{payment}/descarte', [FineBankPaymentController::class, 'discard'])->whereNumber('payment')->name('pagos.discard');
    });

    Route::middleware('can:multas.ver')->group(function (): void {
        // El recibo de Multas: el mismo papel en el visor y en la impresora.
        Route::get('recibos/{receipt}/ver', [FinePaymentController::class, 'view'])->whereNumber('receipt')->name('recibos.view');
        Route::get('recibos/{receipt}/imprimir', [FinePaymentController::class, 'print'])->whereNumber('receipt')->name('recibos.print');

        Route::get('/', [FineController::class, 'index'])->name('index');
        Route::get('caja', [FineCashController::class, 'index'])->name('caja.index');
        Route::get('exportar', [FineController::class, 'export'])->name('exportar');
        Route::get('expedientes/{fineCase}', [FineCaseController::class, 'show'])->whereNumber('fineCase')->name('expedientes.show');
        Route::get('{fine}', [FineController::class, 'show'])->whereNumber('fine')->name('show');
    });
});
