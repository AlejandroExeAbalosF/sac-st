<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Haberes\Actions\CompletePaymentOrderData;
use App\Modules\Haberes\Actions\EditPaymentOrderDetails;
use App\Modules\Haberes\Actions\IssuePaymentOrder;
use App\Modules\Haberes\Actions\VoidPaymentOrder;
use App\Modules\Haberes\Data\InstallmentOrderStateData;
use App\Modules\Haberes\Data\IssuePaymentOrderData;
use App\Modules\Haberes\Data\PaymentOrderContextData;
use App\Modules\Haberes\Http\Requests\CompletePaymentOrderDataRequest;
use App\Modules\Haberes\Http\Requests\EditPaymentOrderDetailsRequest;
use App\Modules\Haberes\Http\Requests\IssuePaymentOrderRequest;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Pase;
use App\Modules\Haberes\Models\PaymentOrder;
use App\Modules\Haberes\Pdf\PasePdf;
use App\Modules\Haberes\Pdf\PaymentOrderPdf;
use App\Modules\Haberes\Support\InstallmentFunding;
use App\Modules\Haberes\Support\PaymentOrderEligibility;
use App\Modules\Haberes\Support\PaymentOrderObservation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * La Orden de Pago y su Pase, desde la cuota que los origina.
 *
 * Se entra desde la cuota y no desde una pantalla propia de Órdenes
 * porque es ahí donde se ve que está financiada, que tiene su recibo y que
 * el dinero llegó a la cuenta del organismo: las tres condiciones que
 * habilitan el papel.
 */
final class PaymentOrderController extends Controller
{
    /**
     * La pantalla que arma la Orden y su Pase.
     *
     * **Es una pantalla y no un diálogo**, por la misma razón que el
     * traslado del efectivo: acá se completan datos de dos personas, se
     * verifica un CBU y se leen dos hojas enteras antes de firmar. Eso no
     * entra en un modal sin apretarlo hasta volverlo incómodo, y lo que se
     * apretaba primero era justamente la previa del papel.
     */
    public function create(
        BeneficiaryInstallment $installment,
        PaymentOrderEligibility $habilitacion,
    ): Response {
        $installment->loadMissing(['haber.beneficiary', 'haber.expediente.employer']);

        $estado = $habilitacion->for($installment);

        /*
         * Se corta antes de dibujar un formulario que no puede enviarse:
         * una cuota que se paga por mostrador no lleva Orden, y una que ya
         * la tiene no puede emitir otra.
         */
        abort_unless($estado->applies, 404);
        abort_if($estado->activeOrder !== null, 404);

        $haber = $installment->haber;
        $expediente = $haber->expediente;

        return Inertia::render('haberes/ordenes/create', [
            'cuota' => [
                'id' => $installment->id,
                'number' => $installment->installment_number,
                'concept' => $installment->description ?? $haber->concept,
                'amount' => $estado->incomeReceipt->amount
                    ?? app(InstallmentFunding::class)->allocated($installment),
            ],
            'haber' => [
                'id' => $haber->id,
                'beneficiaryName' => $haber->beneficiary->name,
            ],
            'expediente' => [
                'id' => $expediente->id,
                'displayNumber' => $expediente->display_number,
            ],
            'estado' => InstallmentOrderStateData::fromReadiness(
                $installment,
                $estado,
                $estado->organismBankAccountId === null
                    ? null
                    : BankAccount::query()
                        ->whereKey($estado->organismBankAccountId)
                        ->value('label'),
            ),
            'contexto' => PaymentOrderContextData::fromHaber(
                $haber,
                $expediente,
                IssuePaymentOrderData::DEFAULT_DESTINATION,
            ),
            'puedeVerificarCbu' => request()->user()?->can('personas.verificar-cbu') ?? false,
            /*
             * Va aparte y no derivado del anterior: forzar es la única
             * capacidad que el comodín del administrador no cubre, así que
             * deducirla de `puedeVerificarCbu` se la mostraría al contador.
             */
            'puedeForzarCbu' => request()->user()?->can('dev.forzar-cbu') ?? false,
        ]);
    }

    /**
     * Completa los datos del maestro que el formulario imprime.
     *
     * Va separado de emitir a propósito: el domicilio que el operador
     * acaba de tipear es cierto aunque la Orden después se rechace, y
     * meterlo en la misma transacción lo obligaría a escribirlo de nuevo.
     */
    public function complete(
        CompletePaymentOrderDataRequest $request,
        BeneficiaryInstallment $installment,
        CompletePaymentOrderData $completar,
    ): RedirectResponse {
        $completar->handle($installment, $request->validated(), $request->user()?->id);

        return back()->with('status', 'Datos completados.');
    }

    /**
     * Emite la Orden y su nota de Pase, que nacen juntas.
     */
    public function store(
        IssuePaymentOrderRequest $request,
        BeneficiaryInstallment $installment,
        IssuePaymentOrder $emitir,
    ): RedirectResponse {
        $orden = $emitir->handle(
            installment: $installment,
            data: IssuePaymentOrderData::fromRequest($request),
            actorId: $request->user()?->id,
        );

        /*
         * Vuelve al haber y no a esta pantalla: la Orden ya está emitida y
         * el formulario que la armaba no tiene nada más que hacer. Lo que
         * sigue —verla, imprimirla, corregirla— vive en la tarjeta.
         */
        $haber = $installment->haber;

        return to_route('haberes.haber.show', [
            $haber->loadMissing('expediente')->expediente,
            $haber,
        ])->with(
            'status',
            "Orden de Pago {$orden->number} emitida, con su Pase. Ya se pueden imprimir.",
        );
    }

    /**
     * Corrige lo accesorio de la Orden y de su Pase.
     *
     * **Es el camino de la foja aclaratoria**, uno de los dos que el área
     * usa: lo que falta se agrega y los mismos papeles siguen. El otro es
     * anular y rehacer, para cuando el organismo devuelve la Orden. Lo que
     * se toca acá es texto administrativo —la foja del CBU, el destinatario
     * de la nota—; el importe y las partes no se editan nunca.
     */
    public function update(
        EditPaymentOrderDetailsRequest $request,
        PaymentOrder $order,
        EditPaymentOrderDetails $corregir,
    ): RedirectResponse {
        $corregir->handle($order, $request->validated(), $request->user()?->id);

        return back()->with('status', "Orden {$order->number} corregida.");
    }

    /**
     * Anula la Orden y su Pase, con motivo.
     *
     * **Es el camino de la Orden devuelta.** El área lo confirmó: *«la
     * orden de pago devuelta queda anulada; se genera una nueva orden de
     * pago»*. También es la única salida cuando lo que está mal es el
     * dinero o las partes, que la base no deja editar.
     *
     * Queda después de corregir en la pantalla —no antes— porque entre dos
     * caminos válidos el que no destruye nada va primero.
     */
    public function void(
        Request $request,
        PaymentOrder $order,
        VoidPaymentOrder $anular,
    ): RedirectResponse {
        $validado = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:300'],
        ], [
            'reason.required' => 'Hay que decir por qué se anula la Orden.',
            'reason.min' => 'El motivo tiene que explicar algo: un par de palabras no alcanzan.',
        ]);

        $anular->handle($order, (string) $validado['reason'], $request->user()?->id);

        return back()->with(
            'status',
            "Orden {$order->number} anulada junto con su Pase. Su número queda consumido; "
            .'la que la reemplace toma el siguiente libre.',
        );
    }

    /**
     * La Orden todavía sin emitir, para mirarla antes de confirmar.
     *
     * **Es el mismo armado que la emisión.** Lo que se ve acá es lo que va
     * a salir por la impresora, salvo el número: el definitivo se toma
     * bajo lock recién al confirmar.
     */
    public function preview(
        Request $request,
        BeneficiaryInstallment $installment,
        IssuePaymentOrder $emitir,
        PaymentOrderEligibility $habilitacion,
        PaymentOrderPdf $pdf,
    ): SymfonyResponse {
        $borrador = $emitir->preview(
            $installment,
            IssuePaymentOrderData::fromPreviewRequest($request),
        );

        return response($pdf->preview(
            $borrador,
            renglones: $habilitacion->for($installment)->rows,
        )->render());
    }

    /**
     * La nota de Pase todavía sin emitir.
     *
     * Va junto al borrador de la Orden porque los dos documentos viajan
     * juntos: quien firma tiene que poder leer la nota antes de que salga,
     * no enterarse de lo que decía después.
     */
    public function previewPase(
        Request $request,
        BeneficiaryInstallment $installment,
        IssuePaymentOrder $emitir,
        PasePdf $pdf,
    ): SymfonyResponse {
        $borrador = $emitir->previewPase(
            $installment,
            IssuePaymentOrderData::fromPreviewRequest($request),
        );

        return response($pdf->preview($borrador)->render());
    }

    /**
     * El borrador de la Orden, en PDF de verdad.
     *
     * **Existe porque «Imprimir» no imprimía.** El visor ofrece dos
     * enlaces —ver en grande e imprimir— y el borrador no tenía más que
     * una ruta, que devuelve HTML: los dos abrían la misma hoja y ninguno
     * llegaba al lector de PDF del navegador, que es desde donde se manda
     * al papel. Los documentos ya emitidos sí tenían las dos rutas.
     *
     * Se arma igual que la previa en HTML, con los mismos parámetros de
     * la query: lo que se imprime es lo que se está mirando.
     */
    public function previewPrint(
        Request $request,
        BeneficiaryInstallment $installment,
        IssuePaymentOrder $emitir,
        PaymentOrderEligibility $habilitacion,
        PaymentOrderPdf $pdf,
    ): SymfonyResponse {
        $borrador = $emitir->preview(
            $installment,
            IssuePaymentOrderData::fromPreviewRequest($request),
        );

        return $pdf->render($borrador, renglones: $habilitacion->for($installment)->rows)
            ->stream($pdf->filename($borrador, borrador: true));
    }

    /** El borrador de la nota de Pase, en PDF de verdad. */
    public function previewPasePrint(
        Request $request,
        BeneficiaryInstallment $installment,
        IssuePaymentOrder $emitir,
        PasePdf $pdf,
    ): SymfonyResponse {
        $borrador = $emitir->previewPase(
            $installment,
            IssuePaymentOrderData::fromPreviewRequest($request),
        );

        return $pdf->render($borrador)->stream($pdf->filename($borrador, borrador: true));
    }

    /** La Orden emitida, en PDF. */
    public function print(PaymentOrder $order, PaymentOrderPdf $pdf): SymfonyResponse
    {
        // En línea y no como descarga: lo normal es mirarla y mandarla a
        // imprimir, no guardarla en Descargas.
        return $pdf->render($order)->stream($pdf->filename($order));
    }

    /**
     * La misma Orden, en HTML.
     *
     * Es para el visor de la pantalla: en PDF el navegador levantaría su
     * propio lector adentro del diálogo, que pesa más y se ve distinto en
     * cada máquina.
     *
     * **Admite retoques por querystring** para que el diálogo de corregir
     * muestre el efecto de lo que se está escribiendo antes de guardarlo.
     * Solo los admite acá: lo que sale por `print` es siempre lo guardado,
     * y esa diferencia es a propósito —el papel que se imprime no puede
     * decir algo que el registro no dice—.
     */
    public function view(Request $request, PaymentOrder $order, PaymentOrderPdf $pdf): SymfonyResponse
    {
        return response($pdf->preview($this->conRetoques($request, $order))->render());
    }

    /** La nota de Pase, en PDF. */
    public function printPase(Pase $pase, PasePdf $pdf): SymfonyResponse
    {
        return $pdf->render($pase)->stream($pdf->filename($pase));
    }

    /** La misma nota, en HTML, para el visor. Con los mismos retoques. */
    public function viewPase(Request $request, Pase $pase, PasePdf $pdf): SymfonyResponse
    {
        $pase->loadMissing('paymentOrder');

        if ($request->has('paseDestination')) {
            $pase->destination = $this->texto($request, 'paseDestination') ?? $pase->destination;
        }

        if ($request->has('paseNotes')) {
            $pase->notes = $this->texto($request, 'paseNotes');
        }

        /*
         * La foja vive en la Orden pero es la nota la que la redacta, así
         * que el retoque tiene que llegar hasta acá.
         */
        $this->conRetoques($request, $pase->paymentOrder);

        return response($pdf->preview($pase)->render());
    }

    /**
     * La Orden con lo que el formulario todavía no guardó.
     *
     * **Nada de esto se persiste.** Se escriben los atributos en memoria y
     * el modelo se pasa a la plantilla; la petición termina y se descarta.
     * Es la única forma de que la previa muestre el efecto de un cambio
     * antes de confirmarlo, sin escribirlo en un documento que ya salió.
     *
     * Solo alcanza a los renglones administrativos, que son los mismos que
     * `EditPaymentOrderDetails` deja corregir. El importe y las partes no
     * están —ni acá ni allá—.
     */
    private function conRetoques(Request $request, PaymentOrder $order): PaymentOrder
    {
        if ($request->has('cbuFolio')) {
            $order->cbu_folio_snapshot = $this->texto($request, 'cbuFolio');

            // El OBS acompaña a la foja, igual que al guardar: la previa
            // no puede mostrar un renglón que el Action no va a escribir.
            $order->notes = PaymentOrderObservation::forFolio($order->cbu_folio_snapshot);
        }

        return $order;
    }

    /** El parámetro, vacío entendido como ausente. */
    private function texto(Request $request, string $campo): ?string
    {
        $valor = trim($request->string($campo)->toString());

        return $valor === '' ? null : $valor;
    }
}
