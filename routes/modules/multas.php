<?php

declare(strict_types=1);

use App\Modules\Ledger\Http\Controllers\CashCalendarController;
use App\Modules\Ledger\Http\Controllers\CashController;
use App\Modules\Ledger\Http\Controllers\CashCountController;
use App\Modules\Ledger\Http\Controllers\PeriodClosingController;
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
use App\Modules\Multas\Http\Controllers\FineMonthlyReportController;
use App\Modules\Multas\Http\Controllers\FineMovementController;
use App\Modules\Multas\Http\Controllers\FinePaymentController;
use App\Modules\Multas\Http\Controllers\FinePaymentQueueController;
use App\Support\Routing\ReadableRoute;
use Illuminate\Routing\RedirectController;
use Illuminate\Support\Facades\Route;

/*
| Multas: los expedientes, sus multas y lo que se debe de cada una.
|
| Se registra qué se debe y por qué papel —expedientes, documentos, multas,
| ajustes de la obligación y pagos anteriores al sistema— y se cobra en la
| caja con su recibo. La verificación bancaria llega en la etapa siguiente.
|
| Completar un dato que faltaba es parte de cargar (`multas.registrar`);
| corregir uno que ya tenía valor exige `multas.ajustar`, y eso lo decide
| `ReviseFineIdentifiers`, que es quien sabe qué cambió.
*/
/*
 * El expediente va en la dirección con su id y su número —`12/169607-2024-0`—:
 * el id identifica y el número describe (ver `ReadableRouteKey`).
 */
Route::pattern('fineCase', ReadableRoute::PATTERN);

/*
 * `/multas` era el listado y ahora vive en `/multas/expedientes`, como en
 * Haberes. Redirige en vez de quedar muerta: sin esto respondía 405 y no 404,
 * porque el alta es un `POST` a la misma dirección.
 *
 * Solo en GET y no con `Route::redirect`, que responde a todos los verbos y
 * se tragaba ese `POST`. Va fuera del grupo `multas.` para no quedar
 * registrada con el prefijo del grupo como nombre.
 */
Route::middleware(['auth', 'verified', 'can:multas.ver'])
    ->get('multas', RedirectController::class)
    ->defaults('destination', '/multas/expedientes')
    ->defaults('status', 302);

Route::middleware(['auth', 'verified'])->prefix('multas')->name('multas.')->group(function (): void {
    Route::middleware('can:multas.registrar')->group(function (): void {
        // Antes de `{fine}`: una ruta literal delante de una con parámetro.
        Route::get('nueva', [FineController::class, 'create'])->name('create');
        // La multa que se agrega desde la ficha: el expediente ya está elegido.
        Route::get('expedientes/{fineCase}/agregar-multa', [FineController::class, 'createForCase'])
            ->name('expedientes.multas.create');
        Route::post('/', [FineController::class, 'store'])->name('store');

        Route::post('{fine}/documentos', [FineDocumentController::class, 'store'])->whereNumber('fine')->name('documentos.store');
        Route::post('{fine}/antecedentes', [FineHistoricalMovementController::class, 'store'])->whereNumber('fine')->name('antecedentes.store');
        Route::post('{fine}/antecedentes/credito', [FineHistoricalMovementController::class, 'storeFromBankCredit'])
            ->whereNumber('fine')
            ->name('antecedentes.credito.store');

        Route::post('expedientes/{fineCase}/documentos', [FineCaseController::class, 'storeDocument'])
            ->name('expedientes.documentos.store');

        // Adjuntar es parte de cargar: el papel aparece después (M11).
        Route::post('expedientes/{fineCase}/adjuntos', [FineAttachmentController::class, 'storeForCase'])
            ->name('expedientes.adjuntos.store');
        Route::post('documentos/{fineDocument}/adjuntos', [FineAttachmentController::class, 'storeForDocument'])
            ->whereNumber('fineDocument')
            ->name('documentos.adjuntos.store');
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

    // Reemplazar de qué resolución nace la multa: un permiso propio, en pocas manos.
    Route::middleware('can:multas.reemplazar-origen')->group(function (): void {
        Route::post('{fine}/resolucion-de-origen', [FineDocumentController::class, 'replaceOrigin'])
            ->whereNumber('fine')
            ->name('resolucion-de-origen.store');
    });

    Route::middleware('can:multas.ajustar')->group(function (): void {
        Route::post('{fine}/ajustes', [FineDebtController::class, 'store'])->whereNumber('fine')->name('ajustes.store');
        Route::post('ajustes/{entry}/reversion', [FineDebtController::class, 'reverse'])->whereNumber('entry')->name('ajustes.reverse');
        // Desvincular es anular el vínculo, con motivo: el documento sigue en el expediente.
        Route::post('{fine}/documentos/{fineDocument}/{role}/anulacion', [FineDocumentController::class, 'revoke'])
            ->whereNumber(['fine', 'fineDocument'])
            ->name('documentos.anulacion.store');
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
        // La vista previa va antes del cobro y con el mismo permiso: es el anticipo del recibo.
        Route::get('{fine}/cobros/previsualizar', [FinePaymentController::class, 'preview'])->whereNumber('fine')->name('cobros.preview');
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
        // Las multas candidatas para identificar un crédito que nadie informó (M08).
        Route::get('caja/multas', [FineCashController::class, 'fines'])->name('caja.multas');
        Route::post('pagos/{payment}/descarte', [FineBankPaymentController::class, 'discard'])->whereNumber('payment')->name('pagos.discard');
    });

    // La rendición mensual a la SAF: la emite quien rinde, con el mes cerrado.
    Route::middleware('can:multas.rendicion.emitir')->group(function (): void {
        Route::post('rendiciones/{closing}', [FineMonthlyReportController::class, 'store'])->whereNumber('closing')->name('rendiciones.store');
    });

    Route::middleware('can:multas.ver')->group(function (): void {
        // El recibo de Multas: el mismo papel en el visor y en la impresora.
        Route::get('recibos/{receipt}/ver', [FinePaymentController::class, 'view'])->whereNumber('receipt')->name('recibos.view');
        Route::get('recibos/{receipt}/imprimir', [FinePaymentController::class, 'print'])->whereNumber('receipt')->name('recibos.print');

        // Como `/haberes/expedientes`: el listado, por expediente o por multa.
        Route::get('expedientes', [FineController::class, 'index'])->name('index');
        Route::get('caja', [FineCashController::class, 'index'])->name('caja.index');
        // La consulta operativa de un mes: no es la rendición oficial (P02).
        Route::get('movimientos', [FineMovementController::class, 'index'])->name('movimientos.index');
        Route::get('movimientos/exportar', [FineMovementController::class, 'export'])->name('movimientos.exportar');
        // La rendición oficial a la SAF (P02): sus dos notas por mes cerrado.
        Route::get('rendiciones', [FineMonthlyReportController::class, 'index'])->name('rendiciones.index');
        Route::get('rendiciones/informes/{report}/ver', [FineMonthlyReportController::class, 'view'])->whereNumber('report')->name('rendiciones.view');
        Route::get('rendiciones/informes/{report}/pdf', [FineMonthlyReportController::class, 'download'])->whereNumber('report')->name('rendiciones.pdf');
        Route::get('exportar', [FineController::class, 'export'])->name('exportar');
        Route::get('expedientes/{fineCase}', [FineCaseController::class, 'show'])->name('expedientes.show');
        // La multa cuelga de su expediente y va por su número dentro de él (ver `Fine::showParameters`).
        Route::get('expedientes/{fineCase}/multa/{fine:fine_number}', [FineController::class, 'show'])
            ->whereNumber('fine')
            ->name('show');
    });
});

/*
| La caja del día de Multas: arqueo, cierre, calendario y planilla (Etapa 6).
|
| Son las pantallas de caja de Ledger, las mismas de Haberes, con la caja de
| Multas (`caja:multas`) y sus permisos propios: cerrar una caja no habilita
| a cerrar la otra. La apertura, la cuenta y los depósitos siguen en
| `/multas/caja`, que es la puerta de esta caja.
*/
Route::middleware(['auth', 'verified', 'caja:multas'])->prefix('multas/caja')->name('multas.caja.')->group(function (): void {
    Route::middleware('can:multas.caja.ver')->group(function (): void {
        Route::get('dia', [CashController::class, 'index'])->name('dia');
        Route::get('arqueos', [CashCountController::class, 'index'])->name('arqueos.index');
        Route::get('cierres', [PeriodClosingController::class, 'index'])->name('cierres.index');
        Route::get('calendario', [CashCalendarController::class, 'index'])->name('calendario');
        // La cartera de cheques de hoy, para el modal de la caja del día.
        Route::get('cheques-en-custodia', [FineCashController::class, 'cheques'])->name('cheques');
    });

    Route::middleware('can:multas.caja.arquear')->group(function (): void {
        Route::post('arqueos', [CashCountController::class, 'store'])->name('arqueos.store');
    });

    Route::middleware('can:multas.caja.revisar-arqueo')->group(function (): void {
        Route::post('arqueos/{cashCount}/revisar', [CashCountController::class, 'review'])
            ->whereNumber('cashCount')
            ->name('arqueos.review');
    });

    Route::middleware('can:multas.caja.ajustar-diferencia')->group(function (): void {
        Route::post('arqueos/{cashCount}/imputar', [CashCountController::class, 'adjust'])
            ->whereNumber('cashCount')
            ->name('arqueos.adjust');
    });

    Route::middleware('can:multas.caja.cerrar')->group(function (): void {
        Route::post('cierres', [PeriodClosingController::class, 'store'])->name('cierres.store');
    });

    Route::middleware('can:multas.caja.reabrir')->group(function (): void {
        Route::post('cierres/{closing}/reabrir', [PeriodClosingController::class, 'reopen'])
            ->whereNumber('closing')
            ->name('cierres.reopen');
    });

    // Como en Haberes: bajarla es un GET, rehacerla un POST.
    Route::middleware('can:multas.caja.exportar')->group(function (): void {
        Route::get('cierres/{closing}/planilla', [PeriodClosingController::class, 'sheet'])
            ->whereNumber('closing')
            ->name('cierres.sheet');
    });

    Route::middleware('can:multas.caja.regenerar-planilla')->group(function (): void {
        Route::post('cierres/{closing}/planilla/regenerar', [PeriodClosingController::class, 'regenerateSheet'])
            ->whereNumber('closing')
            ->name('cierres.sheet.regenerate');
    });
});
