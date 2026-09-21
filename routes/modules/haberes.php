<?php

declare(strict_types=1);

use App\Modules\Haberes\Http\Controllers\CashTransferController;
use App\Modules\Haberes\Http\Controllers\DepositTicketController;
use App\Modules\Haberes\Http\Controllers\DisbursementController;
use App\Modules\Haberes\Http\Controllers\ExpedienteController;
use App\Modules\Haberes\Http\Controllers\ExpedienteHistoryController;
use App\Modules\Haberes\Http\Controllers\FundReceiptController;
use App\Modules\Haberes\Http\Controllers\HaberController;
use App\Modules\Haberes\Http\Controllers\HaberHistoryController;
use App\Modules\Haberes\Http\Controllers\InstallmentController;
use App\Modules\Haberes\Http\Controllers\InstallmentHistoryController;
use App\Modules\Haberes\Http\Controllers\PaymentOrderController;
use App\Modules\Haberes\Http\Controllers\PayoutQueueController;
use App\Modules\Haberes\Http\Controllers\ReceiptPanelController;
use App\Modules\Shared\Http\Controllers\PersonBankAccountController;
use Illuminate\Routing\RedirectController;
use Illuminate\Support\Facades\Route;

/*
| Haberes en consignación: expedientes, haberes, cuotas, Órdenes de Pago,
| Pases, remisiones y egresos al beneficiario.
*/

/*
| Tickets de depósito: el comprobante que llega con el expediente y espera
| a que el crédito aparezca en el extracto. No es una recepción —no
| financia nada— pero es lo que permite encontrar el movimiento después.
*/
/*
 * El expediente se identifica en la URL con su número y su id —«125958-2026-7»—,
 * así que el parámetro deja de ser solo dígitos. El patrón sigue dejando afuera
 * a `nuevo`, que comparte lugar con él en la ruta de alta.
 */
Route::pattern('expediente', '[0-9]+(?:-[0-9]+)*');

Route::middleware(['auth', 'verified'])->prefix('depositos')->name('depositos.')->group(function (): void {
    Route::middleware('can:depositos.ver')->group(function (): void {
        Route::get('/', [DepositTicketController::class, 'index'])->name('index');

        /*
         * La cola en papel. Va con `ver` y no con `vincular`: imprimir la
         * lista de lo que falta es consultar, y quien reclama al empleador
         * no es necesariamente quien después cruza el movimiento.
         *
         * Antes de `{ticket}/buscar` a propósito: con el patrón numérico de
         * `whereNumber` no habría colisión, pero una ruta literal delante de
         * una con parámetro es la que no depende de eso.
         */
        Route::get('planilla', [DepositTicketController::class, 'sheet'])->name('planilla');

        Route::get('{ticket}/buscar', [DepositTicketController::class, 'search'])
            ->whereNumber('ticket')
            ->name('search');
    });

    Route::middleware('can:depositos.registrar')->group(function (): void {
        /*
         * El alta no vive acá: cuelga de la cuota que paga, más abajo.
         *
         * Corregir lo transcripto del papel. Mismo permiso que cargarlo:
         * es el mismo acto de copiar bien un dato, y quien puede lo uno
         * tiene que poder lo otro —si no, un dígito mal tipeado queda
         * congelado para siempre—.
         *
         * Es además la única puerta que queda para el importe y el tipo:
         * el alta los toma de la cuota y del medio, así que cuando el papel
         * dice otra cosa se arregla acá.
         */
        Route::post('{ticket}/corregir', [DepositTicketController::class, 'update'])
            ->whereNumber('ticket')
            ->name('update');
    });

    Route::middleware('can:depositos.vincular')->group(function (): void {
        Route::post('{ticket}/vincular', [DepositTicketController::class, 'link'])
            ->whereNumber('ticket')
            ->name('link');
        Route::patch('{ticket}/desvincular', [DepositTicketController::class, 'unlink'])
            ->whereNumber('ticket')
            ->name('unlink');
    });

    Route::middleware('can:depositos.descartar')->group(function (): void {
        Route::patch('{ticket}/descartar', [DepositTicketController::class, 'discard'])
            ->whereNumber('ticket')
            ->name('discard');
        Route::patch('{ticket}/reabrir', [DepositTicketController::class, 'reopen'])
            ->whereNumber('ticket')
            ->name('reopen');
    });
});

/*
| Recepciones: el dinero que entró y a qué cuota va.
|
| Vive en Haberes aunque `fund_receipts` sea de Ledger, porque la pantalla
| muestra la recepción **junto a las cuotas que financia**. Ledger no puede
| verlas; Haberes ve los dos lados.
*/
Route::middleware(['auth', 'verified'])->prefix('recepciones')->name('recepciones.')->group(function (): void {
    Route::middleware('can:recepciones.ver')->group(function (): void {
        Route::get('/', [FundReceiptController::class, 'index'])->name('index');
    });

    /*
     * El alta a mano no existe — desvío 47.
     *
     * Un crédito sin comprobante se deja pendiente en el extracto hasta que
     * aparece el expediente, que es como trabaja el área; cuando aparece, el
     * ticket cruzado y «registrar y asignar» lo resuelven en un clic. Meter
     * esa plata a los libros sin dueño era el paso que sobraba, y el que
     * permitía asentar dos veces el mismo dinero.
     *
     * `recepciones.registrar` sigue vivo: lo exige el atajo, que registra la
     * recepción por dentro.
     */

    Route::middleware('can:recepciones.ver')->group(function (): void {
        Route::get('{receipt}', [FundReceiptController::class, 'show'])
            ->whereNumber('receipt')
            ->name('show');
    });

    Route::middleware('can:recepciones.asignar')->group(function (): void {
        Route::post('{receipt}/asignaciones', [FundReceiptController::class, 'allocate'])
            ->whereNumber('receipt')
            ->name('allocate');
    });

    /*
     * Deshacer la recepción entera: el dinero sale de los libros.
     *
     * Mismo permiso que devolver al pozo lo ya imputado, y es la misma
     * responsabilidad un escalón más arriba.
     */
    Route::middleware('can:recepciones.revertir')->group(function (): void {
        Route::post('{receipt}/revertir', [FundReceiptController::class, 'reverse'])
            ->whereNumber('receipt')
            ->name('reverse');
    });
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    /*
     * `/haberes` era la lista de expedientes y ahora es la puerta de la
     * caja. Redirige en vez de quedar muerta: el área tiene la dirección
     * vieja anotada y en los favoritos.
     *
     * Se registra solo en GET y no con `Route::redirect`, que responde a
     * todos los verbos: eso incluía DELETE, y en este módulo la baja se
     * hace por estado. Hay un test que lo vigila.
     */
    Route::get('haberes', RedirectController::class)
        ->defaults('destination', '/haberes/expedientes')
        ->defaults('status', 302);

    Route::middleware('can:expedientes.ver')->group(function (): void {
        Route::get('haberes/expedientes', [ExpedienteController::class, 'index'])->name('expedientes.index');
        Route::get('haberes/expedientes/{expediente}', [ExpedienteController::class, 'show'])
            ->name('expedientes.show');
        Route::get('haberes/expedientes/{expediente}/haber/{haber}', [HaberController::class, 'show'])
            ->whereNumber('haber')
            ->name('haberes.haber.show');
    });

    Route::middleware('can:expedientes.crear')->group(function (): void {
        Route::get('haberes/expedientes/nuevo', [ExpedienteController::class, 'create'])->name('expedientes.create');
        Route::post('haberes/expedientes', [ExpedienteController::class, 'store'])->name('expedientes.store');
        Route::get('haberes/expedientes/{expediente}/haberes/nuevo', [HaberController::class, 'create'])
            ->name('haberes.haber.create');
        Route::post('haberes/expedientes/{expediente}/haberes', [HaberController::class, 'store'])
            ->name('haberes.haber.store');
    });

    /*
     * Anular pesa más que cargar: deshace el expediente entero y arrastra
     * sus haberes. No lo alcanza quien carga todos los días.
     */
    Route::middleware('can:expedientes.anular')->group(function (): void {
        Route::patch('haberes/expedientes/{expediente}/anular', [ExpedienteController::class, 'cancel'])
            ->name('expedientes.cancel');
        // Mismo permiso: la baja y su reverso son la misma
        // responsabilidad, y quien puede una tiene que poder la otra.
        Route::patch('haberes/expedientes/{expediente}/reactivar', [ExpedienteController::class, 'reactivate'])
            ->name('expedientes.reactivate');

        /*
         * Un haber suelto: mismo permiso, porque es la misma
         * responsabilidad en una escala menor.
         *
         * Cuelgan del expediente y no de `/haberes/haber/{haber}`, que es
         * donde estaban. El haber se identifica por su ordinal, que solo es
         * único adentro del expediente: sin el expediente en la dirección,
         * `route(..., $haber)` armaría un `/haberes/haber/1/anular` que
         * resuelve otro haber sin avisar.
         */
        Route::patch('haberes/expedientes/{expediente}/haber/{haber}/anular', [HaberController::class, 'cancel'])
            ->whereNumber('haber')
            ->name('haberes.haber.cancel');
        Route::patch('haberes/expedientes/{expediente}/haber/{haber}/reactivar', [HaberController::class, 'reactivate'])
            ->whereNumber('haber')
            ->name('haberes.haber.reactivate');
    });

    /*
     * Anular un cobro por mostrador: el dinero nunca entro.
     *
     * Va con `recibos.anular` —el otro permiso que estaba declarado sin
     * uso— porque el efecto visible es dejar sin respaldo un comprobante
     * ya entregado.
     */
    Route::middleware('can:recibos.anular')->group(function (): void {
        Route::post('haberes/cuotas/{installment}/anular-cobro', [InstallmentController::class, 'voidCollection'])
            ->whereNumber('installment')
            ->name('haberes.installments.void-collection');
    });

    /*
     * Devolver al pozo de no identificados plata imputada a una cuota.
     *
     * Va con `recepciones.revertir`, que estaba declarado sin uso desde la
     * tanda del motor: deshace un asiento y devuelve dinero a la cola.
     */
    Route::middleware('can:recepciones.revertir')->group(function (): void {
        Route::post('haberes/cuotas/{installment}/desasignar', [InstallmentController::class, 'unallocate'])
            ->whereNumber('installment')
            ->name('haberes.installments.unallocate');
    });

    /*
     * Del ticket cruzado a la cuota financiada, en un clic.
     *
     * Exige registrar y asignar porque hace ambos actos. Hoy los roles que
     * pueden uno también pueden el otro, pero conservar las dos guardas
     * evita que una futura separación de permisos abra el atajo de más.
     */
    Route::middleware([
        'can:recepciones.registrar',
        'can:recepciones.asignar',
    ])->group(function (): void {
        Route::post(
            'haberes/cuotas/{installment}/registrar-y-asignar',
            [InstallmentController::class, 'receiveAndAllocate'],
        )
            ->whereNumber('installment')
            ->name('haberes.installments.receive-and-allocate');
    });

    /*
     * El historial de una cuota. Va con `expedientes.ver`: consultar quien
     * cambio que es lectura, y esconderselo a quien puede ver la cuota no
     * protege nada —el dato ya esta en la pantalla, lo que falta es como
     * llego a estar asi—.
     */
    Route::middleware('can:expedientes.ver')->group(function (): void {
        Route::get('haberes/cuotas/{installment}/historial', InstallmentHistoryController::class)
            ->whereNumber('installment')
            ->name('haberes.installments.history');

        /*
         * Y el del expediente y el del haber, por el mismo motivo y con el
         * mismo permiso. Son tres niveles de la misma pregunta: el haber
         * cuenta si alguien tocó el derecho reconocido, la cuota cuenta
         * qué pasó con su dinero.
         */
        Route::get('haberes/expedientes/{expediente}/historial', ExpedienteHistoryController::class)
            ->name('expedientes.history');

        /*
         * El haber va anidado y no suelto: su clave de ruta es el ordinal
         * dentro del expediente —`haber_number`—, que no es único en toda
         * la tabla. Sin el expediente adelante, «el haber 1» no señala a
         * ninguno en particular.
         */
        Route::get('haberes/expedientes/{expediente}/haber/{haber}/historial', HaberHistoryController::class)
            ->whereNumber('haber')
            ->name('haberes.haber.history');
    });

    /*
     | El comprobante que trajo el expediente, cargado desde su cuota.
     |
     | Vive acá y no bajo `/depositos` por lo mismo que el traslado: un
     | depósito es de una cuota, siempre. Mientras la dirección colgaba del
     | expediente, a quién apuntaba el papel era un campo del formulario
     | —con su select de beneficiario, su select de cuota y la posibilidad
     | de contradecir a la dirección por la que se había entrado—.
     |
     | Puesta la cuota en el camino, el importe, el beneficiario y el tipo
     | dejan de preguntarse: los dice ella. Lo que queda del formulario es
     | lo que dice el papel.
     */
    Route::middleware('can:depositos.registrar')->group(function (): void {
        Route::get('haberes/cuotas/{installment}/comprobante', [DepositTicketController::class, 'create'])
            ->whereNumber('installment')
            ->name('haberes.installments.ticket.create');

        Route::post('haberes/cuotas/{installment}/comprobante', [DepositTicketController::class, 'store'])
            ->whereNumber('installment')
            ->name('haberes.installments.ticket');
    });

    /*
     * El efectivo que nadie retiro, camino al banco (§9.5).
     *
     * Son dos permisos porque son dos actos: depositar saca el efectivo de
     * la caja; confirmar lo da por acreditado contra el extracto.
     */
    Route::middleware('can:caja.trasladar')->group(function (): void {
        Route::get('haberes/cuotas/{installment}/traslado', [CashTransferController::class, 'create'])
            ->whereNumber('installment')
            ->name('haberes.installments.transfer.create');

        Route::post('haberes/cuotas/{installment}/traslado', [CashTransferController::class, 'store'])
            ->whereNumber('installment')
            ->name('haberes.installments.transfer');
    });

    Route::middleware('can:caja.confirmar-traslado')->group(function (): void {
        Route::get('traslados/{transfer}/candidatos', [CashTransferController::class, 'candidates'])
            ->whereNumber('transfer')
            ->name('traslados.candidates');

        Route::post('traslados/{transfer}/acreditar', [CashTransferController::class, 'confirm'])
            ->whereNumber('transfer')
            ->name('traslados.confirm');

        /*
         * Deshacer un traslado que no ocurrio. Va con el mismo permiso que
         * confirmarlo: las dos son decisiones sobre el mismo hecho.
         */
        Route::post('traslados/{transfer}/cancelar', [CashTransferController::class, 'cancel'])
            ->whereNumber('transfer')
            ->name('traslados.cancel');
    });

    /*
     * El comprobante impreso. Va con `recibos.ver` y no con `emitir`:
     * consultarlo es lectura, y quien solo consulta tiene que poder
     * mostrarlo o reimprimirlo.
     */
    Route::middleware('can:recibos.ver')->group(function (): void {
        Route::get('recibos/{receipt}/imprimir', [HaberController::class, 'printReceipt'])
            ->whereNumber('receipt')
            ->name('recibos.print');

        /*
         * El mismo papel en HTML, para mirarlo dentro del visor sin
         * levantar el lector de PDF del navegador.
         */
        Route::get('recibos/{receipt}/ver', [HaberController::class, 'viewReceipt'])
            ->whereNumber('receipt')
            ->name('recibos.view');

        /*
         * De quién es el comprobante, para el panel lateral. Va con el
         * mismo permiso que mirarlo: decir a qué haber pertenece un recibo
         * no expone nada que su propio papel no diga.
         */
        Route::get('recibos/{receipt}/panel', ReceiptPanelController::class)
            ->whereNumber('receipt')
            ->name('recibos.panel');
    });

    /*
     * El recibo de ingreso se emite desde la cuota, que es donde se ve
     * que quedo completa. Va con su propio permiso: emitir un comprobante
     * es entregar un papel, no editar un dato del expediente.
     */
    Route::middleware('can:recibos.emitir')->group(function (): void {
        /*
         * La vista previa va antes de la emisión y con el mismo permiso:
         * es el anticipo de un acto que solo puede hacer quien emite.
         */
        Route::get('haberes/cuotas/{installment}/recibo/previsualizar', [HaberController::class, 'previewReceipt'])
            ->whereNumber('installment')
            ->name('haberes.installments.receipt.preview');
        Route::post('haberes/cuotas/{installment}/recibo', [HaberController::class, 'issueReceipt'])
            ->whereNumber('installment')
            ->name('haberes.installments.receipt');
    });

    /*
     | El egreso al beneficiario.
     |
     | Se paga desde la cuota, que es donde se ve que está financiada, que
     | tiene su recibo de ingreso y que el dinero sigue en la caja. Entregar
     | y hacer firmar el recibo son un solo acto, como el cobro por
     | mostrador en el otro extremo del circuito.
     |
     | El circuito bancario son tres actos y no uno (§2.3): el organismo
     | informa, el débito aparece en el extracto, y el contador coteja los
     | dos contra la Orden. Los dos primeros son carga de datos y van con
     | `egresos.registrar`; el tercero mueve el libro y lleva permiso
     | propio.
     */
    Route::middleware('can:egresos.registrar')->group(function (): void {
        /*
         * Las dos colas del egreso, y su planilla. El área las llama
         * «Planillas», que es lo que viene a buscar acá: la hoja para
         * trabajar la fila del mostrador o la del banco.
         *
         * Va con `registrar` y no con `validar`: mirar qué está esperando
         * no es validarlo, y quien carga el informe del organismo necesita
         * la lista tanto como quien después la coteja. El rol de consulta
         * queda afuera a propósito —una cola de trabajo es de quien la
         * trabaja—.
         *
         * El permiso sigue llamándose `egresos.registrar`: es el acto de
         * dominio, no la pantalla, y renombrarlo movería una fila de la
         * tabla de permisos que el área ya tiene configurada.
         */
        Route::get('haberes/planillas', [PayoutQueueController::class, 'index'])
            ->name('planillas.index');
        /*
         * `print` y no `planilla`: dentro del grupo quedaría
         * «planillas.planilla». Es el mismo sufijo que ya usan
         * `recibos.print`, `ordenes.print` y `pases.print`.
         */
        Route::get('haberes/planillas/imprimir', [PayoutQueueController::class, 'sheet'])
            ->name('planillas.print');

        /*
         * La vista previa va antes del pago y con el mismo permiso: es el
         * anticipo de un acto que solo puede hacer quien entrega.
         */
        Route::get('haberes/cuotas/{installment}/egreso/previsualizar', [DisbursementController::class, 'preview'])
            ->whereNumber('installment')
            ->name('haberes.installments.disbursement.preview');

        /* Mostrador: entregar y hacer firmar, en un solo acto. */
        Route::post('haberes/cuotas/{installment}/egreso', [DisbursementController::class, 'store'])
            ->whereNumber('installment')
            ->name('haberes.installments.disbursement');

        /* Transferencia: el aviso del organismo (§2.3.1). */
        Route::post('haberes/cuotas/{installment}/egreso/informe', [DisbursementController::class, 'report'])
            ->whereNumber('installment')
            ->name('haberes.installments.disbursement.report');

        /*
         * El débito del extracto (§2.3.2). La búsqueda propone y la
         * vinculación confirma: son dos rutas porque son dos actos, y el
         * segundo es de una persona.
         */
        Route::get('haberes/cuotas/{installment}/egreso/debitos', [DisbursementController::class, 'debitCandidates'])
            ->whereNumber('installment')
            ->name('haberes.installments.disbursement.debits');

        Route::post('haberes/cuotas/{installment}/egreso/debito', [DisbursementController::class, 'linkDebit'])
            ->whereNumber('installment')
            ->name('haberes.installments.disbursement.link-debit');

        Route::post('haberes/cuotas/{installment}/egreso/debito/deshacer', [DisbursementController::class, 'unlinkDebit'])
            ->whereNumber('installment')
            ->name('haberes.installments.disbursement.unlink-debit');

        /*
         * El papel del egreso ya confirmado. Va con `egresos.registrar` y
         * no con `egresos.validar`: emitir el comprobante de un pago que
         * el contador ya dio por hecho es trabajo de mostrador.
         */
        Route::post('haberes/cuotas/{installment}/egreso/recibo', [DisbursementController::class, 'issueReceipt'])
            ->whereNumber('installment')
            ->name('haberes.installments.disbursement.receipt');
    });

    /*
     | La validación del contador (§2.3.4): el acto que convierte dos
     | papeles en un pago. Postea el asiento, deja la cuota pagada y
     | habilita el recibo, así que no lo alcanza quien solo carga datos.
     */
    Route::middleware('can:egresos.validar')->group(function (): void {
        Route::post('haberes/cuotas/{installment}/egreso/validar', [DisbursementController::class, 'confirm'])
            ->whereNumber('installment')
            ->name('haberes.installments.disbursement.validate');
    });

    /*
     | Órdenes de Pago y Pases.
     |
     | Se emiten desde la cuota, que es donde se ve que está financiada,
     | que tiene su recibo y que el dinero llegó a la cuenta del organismo.
     | Los dos documentos nacen juntos y viajan juntos (§9.7).
     */
    Route::middleware('can:ordenes.ver')->group(function (): void {
        Route::get('ordenes/{order}/imprimir', [PaymentOrderController::class, 'print'])
            ->whereNumber('order')
            ->name('ordenes.print');
        Route::get('ordenes/{order}/ver', [PaymentOrderController::class, 'view'])
            ->whereNumber('order')
            ->name('ordenes.view');

        Route::get('pases/{pase}/imprimir', [PaymentOrderController::class, 'printPase'])
            ->whereNumber('pase')
            ->name('pases.print');
        Route::get('pases/{pase}/ver', [PaymentOrderController::class, 'viewPase'])
            ->whereNumber('pase')
            ->name('pases.view');
    });

    Route::middleware('can:ordenes.emitir')->group(function (): void {
        /*
         * Completar el maestro va con el mismo permiso que emitir: es el
         * mismo acto de preparar el papel, y quien puede lo uno tiene que
         * poder lo otro —si no, el domicilio que falta frena la Orden y
         * nadie a mano puede destrabarla—.
         */
        Route::patch('haberes/cuotas/{installment}/orden/datos', [PaymentOrderController::class, 'complete'])
            ->whereNumber('installment')
            ->name('haberes.installments.order.complete');

        /*
         * La pantalla que arma los dos documentos. Es pantalla y no modal
         * por lo mismo que el traslado del efectivo: acá se completan datos
         * de dos personas, se verifica un CBU y se leen dos hojas enteras
         * antes de firmar.
         */
        Route::get('haberes/cuotas/{installment}/orden/nueva', [PaymentOrderController::class, 'create'])
            ->whereNumber('installment')
            ->name('haberes.installments.order.create');

        /*
         * La vista previa va antes de la emisión y con el mismo permiso:
         * es el anticipo de un acto que solo puede hacer quien emite.
         */
        Route::get('haberes/cuotas/{installment}/orden/previsualizar', [PaymentOrderController::class, 'preview'])
            ->whereNumber('installment')
            ->name('haberes.installments.order.preview');

        /*
         * La misma hoja en PDF, que es la que se manda al papel. Va por su
         * propia ruta y no por un parámetro de la anterior porque son dos
         * respuestas distintas: una se mira dentro de un iframe y la otra
         * la abre el lector del navegador.
         */
        Route::get('haberes/cuotas/{installment}/orden/previsualizar/imprimir', [PaymentOrderController::class, 'previewPrint'])
            ->whereNumber('installment')
            ->name('haberes.installments.order.preview.print');

        /*
         * La nota va con su propia previa y no dentro de la anterior: son
         * dos hojas distintas, y el visor las muestra una al lado de la
         * otra.
         */
        Route::get('haberes/cuotas/{installment}/pase/previsualizar', [PaymentOrderController::class, 'previewPase'])
            ->whereNumber('installment')
            ->name('haberes.installments.pase.preview');

        Route::get('haberes/cuotas/{installment}/pase/previsualizar/imprimir', [PaymentOrderController::class, 'previewPasePrint'])
            ->whereNumber('installment')
            ->name('haberes.installments.pase.preview.print');

        Route::post('haberes/cuotas/{installment}/orden', [PaymentOrderController::class, 'store'])
            ->whereNumber('installment')
            ->name('haberes.installments.order');

        /*
         * Corregir lo accesorio del documento —la observación, la foja del
         * CBU, el destinatario de la nota—. Va con el mismo permiso que
         * emitir porque es el mismo acto de preparar el papel, y es el
         * camino normal cuando algo está mal: el área no anula, aclara.
         */
        Route::patch('ordenes/{order}', [PaymentOrderController::class, 'update'])
            ->whereNumber('order')
            ->name('ordenes.update');
    });

    /*
     * Anular pesa distinto: deja sin efecto un documento que el organismo
     * puede tener en la mano, y arrastra su Pase.
     */
    Route::middleware('can:ordenes.anular')->group(function (): void {
        Route::post('ordenes/{order}/anular', [PaymentOrderController::class, 'void'])
            ->whereNumber('order')
            ->name('ordenes.void');
    });

    /*
     * Verificar un CBU es la puerta de la Orden: sin una cuenta verificada
     * el organismo no transfiere. Es trabajo de cotejo contra el
     * expediente, no de carga.
     */
    Route::middleware('can:personas.verificar-cbu')->group(function (): void {
        Route::post('personas/{person}/cuentas', [PersonBankAccountController::class, 'store'])
            ->whereNumber('person')
            ->name('personas.cuentas.store');
        Route::post('personas/cuentas/{account}/verificar', [PersonBankAccountController::class, 'verify'])
            ->whereNumber('account')
            ->name('personas.cuentas.verify');
        Route::post('personas/cuentas/{account}/rechazar', [PersonBankAccountController::class, 'reject'])
            ->whereNumber('account')
            ->name('personas.cuentas.reject');
        Route::post('personas/cuentas/{account}/dar-de-baja', [PersonBankAccountController::class, 'deactivate'])
            ->whereNumber('account')
            ->name('personas.cuentas.deactivate');
    });

    /*
     * Forzar va en su propio grupo y no arriba con los demás: su permiso es
     * el único que `Gate::before` no le concede al administrador, y meterlo
     * en el mismo `can:personas.verificar-cbu` se lo regalaría al contador.
     */
    Route::middleware('can:dev.forzar-cbu')->group(function (): void {
        Route::post('personas/cuentas/{account}/forzar', [PersonBankAccountController::class, 'force'])
            ->whereNumber('account')
            ->name('personas.cuentas.force');
    });

    Route::middleware('can:expedientes.editar')->group(function (): void {
        /*
         * Destrabar la edición de una cuota en circulación. Va con el
         * mismo permiso que editarla: no es una atribución nueva, es el
         * registro del caso que la corrección exige.
         */
        Route::post('haberes/cuotas/{installment}/habilitar-edicion', [InstallmentController::class, 'unlockEdit'])
            ->whereNumber('installment')
            ->name('haberes.installments.unlock-edit');

        Route::get('haberes/expedientes/{expediente}/editar', [ExpedienteController::class, 'edit'])
            ->name('expedientes.edit');
        Route::patch('haberes/expedientes/{expediente}', [ExpedienteController::class, 'update'])
            ->name('expedientes.update');
        /*
         * Cuelgan del expediente por lo mismo que la baja del haber: el
         * haber se identifica por su ordinal, que solo es único adentro del
         * expediente. Sin el expediente en la dirección, `{haber}` no se
         * puede resolver.
         */
        Route::post('haberes/expedientes/{expediente}/haber/{haber}/cuotas', [InstallmentController::class, 'store'])
            ->whereNumber('haber')
            ->name('haberes.installments.store');
        Route::patch('haberes/expedientes/{expediente}/haber/{haber}/cuotas/{installment}', [InstallmentController::class, 'update'])
            ->whereNumber(['haber', 'installment'])
            ->name('haberes.installments.update');
    });
});
