<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Banking\Enums\CashTransferStatus;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Haberes\Actions\AddHaber;
use App\Modules\Haberes\Actions\CancelHaber;
use App\Modules\Haberes\Actions\CollectAndIssueReceipt;
use App\Modules\Haberes\Actions\IssueIncomeReceipt;
use App\Modules\Haberes\Actions\ReactivateHaber;
use App\Modules\Haberes\Data\ExpedienteListItemData;
use App\Modules\Haberes\Data\HaberExtraData;
use App\Modules\Haberes\Data\HaberListItemData;
use App\Modules\Haberes\Data\InstallmentAllocationData;
use App\Modules\Haberes\Data\InstallmentDisbursementData;
use App\Modules\Haberes\Data\InstallmentOrderStateData;
use App\Modules\Haberes\Data\InstallmentReceiptData;
use App\Modules\Haberes\Data\InstallmentTransferData;
use App\Modules\Haberes\Enums\AllocationKind;
use App\Modules\Haberes\Enums\ExpectedMedium;
use App\Modules\Haberes\Http\Requests\CancelHaberRequest;
use App\Modules\Haberes\Http\Requests\StoreHaberRequest;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\CashToBankTransferItem;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Haberes\Models\Haber;
use App\Modules\Haberes\Models\HaberManagementLabel;
use App\Modules\Haberes\Support\DepositTicketLinks;
use App\Modules\Haberes\Support\DisbursementEligibility;
use App\Modules\Haberes\Support\InstallmentBatch;
use App\Modules\Haberes\Support\InstallmentFunding;
use App\Modules\Haberes\Support\InstallmentStages;
use App\Modules\Haberes\Support\PaymentOrderEligibility;
use App\Modules\Haberes\Support\PaymentOrderSources;
use App\Modules\Haberes\Support\ReceiptFormData;
use App\Modules\Shared\Enums\ReceiptStatus;
use App\Modules\Shared\Enums\ReceiptType;
use App\Modules\Shared\Models\AuditEvent;
use App\Modules\Shared\Models\Person;
use App\Modules\Shared\Models\Receipt;
use App\Modules\Shared\Pdf\IncomeReceiptPdf;
use App\Modules\Shared\Pdf\ReceiptPdfFactory;
use App\Modules\Shared\Support\LastChanges;
use App\Modules\Shared\Support\TalonarioSequence;
use App\Support\Money\Decimal;
use App\Support\Ui\Toast;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Haberes de un expediente.
 *
 * Un haber es el derecho total reconocido a un beneficiario dentro del
 * expediente. Sus cuotas se cargan con el importe que ya se conoce; las
 * que faltan llegan con los tickets siguientes.
 */
final class HaberController extends Controller
{
    /**
     * Alta de un haber, en su propia pantalla.
     *
     * Estaba dentro del detalle, debajo de los haberes ya cargados, y ahí
     * el formulario se leía como uno más de la lista. Tampoco alcanzaba con
     * separarlo visualmente: con cinco haberes desplegados arriba, cada uno
     * con sus cuotas, el formulario quedaba a dos pantallas de distancia.
     *
     * Lo que no se pierde al mudarlo es el contexto: arriba va el
     * expediente con lo que ya reconoce y a quiénes, porque es contra eso
     * que se controla el importe nuevo y que se evita cargar dos veces al
     * mismo beneficiario.
     */
    public function create(Expediente $expediente): Response
    {
        $expediente->load([
            'employer:id,name',
            'haberes' => fn ($haberes) => $haberes->orderBy('id'),
            'haberes.beneficiary:id,name,document',
            // Quién cargó cada haber: la tarjeta lo muestra al lado de sus
            // importes. Va acá y no en el DTO porque leerlo suelto, con el
            // modo estricto encendido, sería una consulta por haber.
            'haberes.creator:id,name',
            'haberes.installments' => fn ($cuotas) => $cuotas->orderBy('installment_number'),
            'haberes.installments.managementLabel:id,code,blocks_payment',
        ]);

        return Inertia::render('haberes/haber-create', [
            'expediente' => ExpedienteListItemData::fromModel($expediente),
            'beneficiarios' => Person::query()
                ->active()
                ->forRole('beneficiary')
                ->orderBy('name')
                ->limit(20)
                ->get()
                ->map(fn (Person $person): array => $person->toOption())
                ->values()
                ->all(),
            'etiquetas' => HaberManagementLabel::query()
                ->active()
                ->orderBy('sort_order')
                ->get(['id', 'code', 'description']),
        ]);
    }

    public function store(
        StoreHaberRequest $request,
        Expediente $expediente,
        AddHaber $agregar,
    ): RedirectResponse {
        try {
            $haber = $agregar->handle(
                $expediente,
                $request->validated(),
                $request->user()?->id,
            );
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), 'haberes_expediente_id_beneficiary_id_unique')) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'beneficiaryId' => 'Ese beneficiario acaba de ser agregado por otro operador. Revisá los haberes del expediente.',
            ]);
        }

        $cuotas = count($request->array('installments'));
        $aviso = $cuotas === 1
            ? "Haber de {$haber->beneficiary->name} agregado, con su primera cuota."
            : "Haber de {$haber->beneficiary->name} agregado, con {$cuotas} cuotas.";

        /*
         * Un acto puede reconocer varios haberes —el expediente de Bulacio
         * tiene cinco— y volver al detalle entre uno y otro obliga a
         * arrancar de nuevo cada vez.
         */
        if ($request->boolean('andAnother')) {
            return to_route('haberes.haber.create', $expediente->id)->with('status', $aviso);
        }

        /*
         * Si el haber espera transferencia, el destino es su propia
         * pantalla y no la del expediente.
         *
         * El comprobante del depósito llega **dentro del expediente**, así
         * que quien acaba de cargar el haber suele tener el papel en la
         * mano. En el detalle del haber cada cuota ofrece «Cargar
         * comprobante»; en el del expediente habría que buscar el haber
         * primero. Cuando no hay transferencia prevista no hay nada que
         * cargar y el expediente sigue siendo el mejor lugar para seguir.
         */
        if ($this->esperaTransferencia($haber)) {
            Toast::success(
                $aviso,
                'Si el expediente trajo el comprobante del depósito, cargalo desde la cuota.',
            );

            return to_route('haberes.haber.show', [$expediente, $haber]);
        }

        return to_route('expedientes.show', $expediente)->with('status', $aviso);
    }

    /**
     * Cobra y emite el recibo de la cuota.
     *
     * Un solo acto porque en el mostrador es uno solo: el dinero llego
     * con el expediente y el recibo es lo que lo asienta. Cuando la cuota
     * ya esta financiada —el circuito bancario— no hay nada que cobrar y
     * solo se emite.
     */
    public function issueReceipt(
        Request $request,
        BeneficiaryInstallment $installment,
        CollectAndIssueReceipt $cobrarYEmitir,
    ): RedirectResponse {
        $validado = $request->validate([
            /*
             * El numero del papel, cuando el recibo se escribio a mano.
             * Opcional: si no viene, el comprobante lo genera el sistema
             * y el unico numero es el suyo.
             */
            /*
             * 20 y no los 40 de la columna: encabeza el papel a 10,5 pt
             * desde los 155 mm, y el marco termina a los 203. Mas largo se
             * sale del recibo. Un numero de talonario real tiene ocho
             * digitos.
             */
            'talonarioNumber' => ['nullable', 'string', 'max:20'],
            /*
             * Cual de los dos numeros encabeza el papel. Se guarda con el
             * recibo: la reimpresion tiene que salir igual que el
             * original.
             */
            'printsTalonarioNumber' => ['nullable', 'boolean'],
            /*
             * Quien firma. Opcional: si no se elige, el pie queda en
             * blanco para firmar y aclarar a mano, como el papel.
             */
            'signedById' => ['nullable', 'integer', 'exists:users,id'],

            /*
             * Lo unico del cobro que la cuota no sabe. El importe y el
             * medio no viajan: los deriva el Action de la propia cuota,
             * porque un importe propuesto por el navegador es una forma
             * de asentar plata que no entro.
             */
            'receivedDate' => [
                'nullable',
                'date',
                'before_or_equal:today',
                /*
                 * Y no antes del alta de la cuota. El sistema no sabe nada
                 * de ese dinero antes de que el expediente llegara: una
                 * fecha anterior seria afirmar un cobro que el expediente
                 * no respalda, y quedaria asentada en el libro.
                 */
                'after_or_equal:'.$installment->created_at->toDateString(),
            ],
            'notes' => ['nullable', 'string', 'max:500'],
            'chequeNumber' => ['nullable', 'string', 'max:50'],
            'chequeBank' => ['nullable', 'string', 'max:120'],
            'chequeIssueDate' => ['nullable', 'date'],
            'idempotencyKey' => ['required', 'string', 'max:120'],
        ], [
            'receivedDate.before_or_equal' => 'La fecha del pago no puede ser futura.',
            'receivedDate.after_or_equal' => 'La fecha del pago no puede ser anterior al alta de la cuota ('
                .$installment->created_at->format('d/m/Y').'), que es cuando llego el expediente.',
        ]);

        $cobra = $cobrarYEmitir->cobraAlEmitir($installment);
        $conCheque = $cobra && $installment->expected_medium === ExpectedMedium::Cheque;

        if ($conCheque && ($validado['chequeNumber'] ?? '') === '') {
            throw ValidationException::withMessages([
                'chequeNumber' => 'Un cheque sin número no se puede inventariar ni buscar después.',
            ]);
        }

        /*
         * Cuando ademas cobra, el acto asienta dinero: eso es una
         * recepcion, y lleva su permiso. La ruta pide `recibos.emitir`
         * porque siempre emite; esto agrega el otro solo cuando
         * corresponde.
         */
        if ($cobra) {
            abort_unless($request->user()?->can('recepciones.registrar') ?? false, 403);
        }

        $recibo = $cobrarYEmitir->handle(
            installment: $installment,
            idempotencyKey: (string) $validado['idempotencyKey'],
            actorId: $request->user()?->id,
            talonarioNumber: $validado['talonarioNumber'] ?? null,
            signedById: isset($validado['signedById']) ? (int) $validado['signedById'] : null,
            printsTalonarioNumber: (bool) ($validado['printsTalonarioNumber'] ?? false),
            receivedDate: isset($validado['receivedDate'])
                ? CarbonImmutable::parse((string) $validado['receivedDate'])
                : null,
            cheque: $conCheque ? [
                'number' => (string) $validado['chequeNumber'],
                'bank' => $validado['chequeBank'] ?? null,
                'issueDate' => $validado['chequeIssueDate'] ?? null,
            ] : null,
            notes: $validado['notes'] ?? null,
        );

        return back()->with('status', "Recibo de ingreso {$recibo->formatted_number} emitido.");
    }

    /**
     * El comprobante antes de emitirlo.
     *
     * Devuelve el mismo HTML que después se imprime, armado por el propio
     * Action: ver una maqueta parecida no serviría de nada —lo que hay que
     * poder controlar es lo que va a salir en el papel—.
     *
     * El número que muestra es el que *le tocaría*. El definitivo se toma
     * bajo lock al confirmar.
     */
    public function previewReceipt(
        Request $request,
        BeneficiaryInstallment $installment,
        IssueIncomeReceipt $emitir,
        IncomeReceiptPdf $pdf,
        ReceiptFormData $formulario,
    ): SymfonyResponse {
        $borrador = $emitir->preview(
            installment: $installment,
            talonarioNumber: $request->string('talonarioNumber')->toString() ?: null,
            signedById: $request->integer('signedById') ?: null,
            printsTalonarioNumber: $request->boolean('printsTalonarioNumber'),
        );

        return response($pdf->preview(
            $borrador,
            conFondo: ! $request->boolean('talonario'),
            extra: $formulario->forInstallment($installment),
        )->render());
    }

    /**
     * El recibo impreso.
     *
     * `?talonario=1` sale sin fondo, para pasar la hoja preimpresa por la
     * impresora; sin ese parametro dibuja el formulario completo sobre
     * papel en blanco. Es la misma plantilla con el fondo apagado.
     */
    public function printReceipt(
        Request $request,
        Receipt $receipt,
        ReceiptPdfFactory $plantillas,
        ReceiptFormData $formulario,
    ): SymfonyResponse {
        $pdf = $plantillas->for($receipt);

        $documento = $pdf->render(
            $receipt,
            conFondo: ! $request->boolean('talonario'),
            extra: $formulario->forReceipt($receipt),
            encabezaTalonario: $this->encabezadoPedido($request, $receipt),
        );

        // En linea y no como descarga: lo normal es mirarlo y mandarlo a
        // imprimir, no guardarlo en Descargas.
        return $documento->stream($pdf->filename($receipt));
    }

    /**
     * El comprobante ya emitido, en HTML.
     *
     * Es el mismo papel que imprime `printReceipt`, servido como pagina
     * para que el visor lo muestre enmarcado. En PDF el navegador
     * levantaria su propio lector adentro del dialogo, que pesa mas y se
     * ve distinto en cada maquina.
     */
    public function viewReceipt(
        Request $request,
        Receipt $receipt,
        ReceiptPdfFactory $plantillas,
        ReceiptFormData $formulario,
    ): SymfonyResponse {
        return response($plantillas->for($receipt)->preview(
            $receipt,
            conFondo: ! $request->boolean('talonario'),
            extra: $formulario->forReceipt($receipt),
            encabezaTalonario: $this->encabezadoPedido($request, $receipt),
        )->render());
    }

    /**
     * Que numero encabeza esta impresion.
     *
     * Sin parametro manda lo que se eligio al emitir, que es para lo que
     * se guarda. Con parametro, esta copia sale con el otro: los dos
     * numeros estan en el mismo registro, asi que ninguna de las dos
     * versiones deja de ser rastreable.
     */
    private function encabezadoPedido(Request $request, Receipt $receipt): bool
    {
        return $request->has('printsTalonarioNumber')
            ? $request->boolean('printsTalonarioNumber')
            : $receipt->prints_talonario_number;
    }

    /**
     * El recibo de ingreso vigente de cada cuota.
     *
     * Solo los emitidos: un recibo anulado no ocupa el lugar del que
     * todavía se puede emitir.
     *
     * @param  list<int>  $cuotaIds
     * @return array<int, Receipt>
     */
    private function recibosVigentes(array $cuotaIds): array
    {
        if ($cuotaIds === []) {
            return [];
        }

        return app(InstallmentBatch::class)->incomeReceipts($cuotaIds);
    }

    /**
     * Los recibos anulados de un grupo de cuotas, por cuota.
     *
     * Del mas nuevo al mas viejo: la cadena de reemplazos se lee del
     * vigente hacia atras, y esa es la direccion en que alguien pregunta
     * «¿por que este recibo tiene este numero?».
     *
     * @param  list<int>  $cuotaIds
     * @return array<int, list<InstallmentReceiptData>>
     */
    private function recibosAnulados(array $cuotaIds): array
    {
        if ($cuotaIds === []) {
            return [];
        }

        return Receipt::query()
            ->with(InstallmentReceiptData::RELATIONS)
            ->where('receipt_type', ReceiptType::Income)
            ->whereIn('status', [ReceiptStatus::Voided, ReceiptStatus::Replaced])
            ->whereIn('beneficiary_installment_id', $cuotaIds)
            ->orderByDesc('id')
            ->get()
            ->groupBy('beneficiary_installment_id')
            ->map(function ($recibos): array {
                /** @var list<InstallmentReceiptData> $lista */
                $lista = $recibos
                    ->map(fn (Receipt $r): InstallmentReceiptData => InstallmentReceiptData::fromModel($r))
                    ->values()
                    ->all();

                return $lista;
            })
            ->all();
    }

    /**
     * Las imputaciones vigentes de un grupo de cuotas, por cuota.
     *
     * En lote como los recibos y los traslados: preguntarlo cuota por
     * cuota seria una consulta por fila en un plan que puede tener sesenta.
     *
     * El importe que viaja es el que **queda en pie** —lo original menos
     * lo ya devuelto—, porque es lo unico que se puede volver a devolver.
     *
     * @param  list<int>  $cuotaIds
     * @return array<int, list<InstallmentAllocationData>>
     */
    private function asignacionesVigentes(array $cuotaIds): array
    {
        if ($cuotaIds === []) {
            return [];
        }

        $devuelto = FundingAllocation::query()
            ->where('allocation_kind', AllocationKind::Reversal)
            ->whereNotNull('reversal_of_id')
            ->groupBy('reversal_of_id')
            ->selectRaw('reversal_of_id, SUM(amount) as total')
            ->pluck('total', 'reversal_of_id');

        return FundingAllocation::query()
            ->live()
            ->with('fundReceipt:id,received_date')
            ->whereIn('beneficiary_installment_id', $cuotaIds)
            ->orderBy('id')
            ->get()
            ->map(fn (FundingAllocation $a): array => [
                'cuota' => (int) $a->beneficiary_installment_id,
                'data' => new InstallmentAllocationData(
                    id: $a->id,
                    amount: Decimal::sub($a->amount, Decimal::scale((string) ($devuelto[$a->id] ?? '0'))),
                    receivedDate: $a->fundReceipt->received_date->format('Y-m-d'),
                ),
            ])
            ->filter(fn (array $fila): bool => ! Decimal::equals($fila['data']->amount, '0'))
            ->groupBy('cuota')
            ->map(function ($filas): array {
                /** @var list<InstallmentAllocationData> $lista */
                $lista = $filas->pluck('data')->values()->all();

                return $lista;
            })
            ->all();
    }

    /**
     * Los depósitos que se cargaron y se dieron de baja, por cuota.
     *
     * Se muestran por lo mismo que los recibos anulados: la tarjeta trae
     * solo el traslado vigente, así que sin esto un depósito cargado y
     * después cancelado desaparecía de la pantalla y nadie podía saber que
     * existió.
     *
     * @param  list<int>  $cuotaIds
     * @return array<int, list<InstallmentTransferData>>
     */
    private function trasladosCancelados(array $cuotaIds): array
    {
        if ($cuotaIds === []) {
            return [];
        }

        /*
         * Todas las imputaciones, no solo las vigentes: lo que se deshizo
         * es justamente lo que hay que poder mostrar.
         */
        $items = CashToBankTransferItem::query()
            ->with(['transfer', 'fundingAllocation:id,beneficiary_installment_id'])
            ->whereRelation('transfer', 'status', CashTransferStatus::Cancelled->value)
            ->whereIn(
                'funding_allocation_id',
                FundingAllocation::query()
                    ->whereIn('beneficiary_installment_id', $cuotaIds)
                    ->select('id'),
            )
            ->get();

        $bajas = $this->bajasDeTraslado(
            array_values($items->map(fn (CashToBankTransferItem $i): int => (int) $i->cash_to_bank_transfer_id)->unique()->all()),
        );

        /** @var array<int, list<InstallmentTransferData>> $porCuota */
        $porCuota = $items
            ->sortByDesc(fn (CashToBankTransferItem $i): int => (int) $i->cash_to_bank_transfer_id)
            ->groupBy(fn (CashToBankTransferItem $i): int => (int) $i->fundingAllocation->beneficiary_installment_id)
            ->map(function ($grupo) use ($bajas): array {
                /** @var list<InstallmentTransferData> $lista */
                $lista = $grupo
                    ->map(fn (CashToBankTransferItem $i): InstallmentTransferData => InstallmentTransferData::fromModel(
                        $i->transfer,
                        $bajas[(int) $i->cash_to_bank_transfer_id] ?? null,
                    ))
                    ->values()
                    ->all();

                return $lista;
            })
            ->all();

        return $porCuota;
    }

    /**
     * Quién dio de baja cada traslado y cuándo.
     *
     * La tabla no lo guarda: el traslado solo pasa a `cancelled` con su
     * motivo. Los dos datos viven en el evento de auditoría, y se traen en
     * una consulta y no una por traslado.
     *
     * @param  list<int>  $trasladoIds
     * @return array<int, AuditEvent>
     */
    private function bajasDeTraslado(array $trasladoIds): array
    {
        if ($trasladoIds === []) {
            return [];
        }

        /** @var array<int, AuditEvent> $bajas */
        $bajas = AuditEvent::query()
            ->with('user:id,name')
            ->where('action', 'traslado.cancelado')
            ->where('subject_type', 'CashToBankTransfer')
            ->whereIn('subject_id', $trasladoIds)
            ->get()
            ->keyBy(fn (AuditEvent $e): int => (int) $e->subject_id)
            ->all();

        return $bajas;
    }

    /**
     * Los traslados al banco de un grupo de cuotas, indexados por cuota.
     *
     * La consulta vive en `PaymentOrderSources`, que es quien contesta
     * dónde está el dinero de una cuota. Acá estuvo escrita por segunda vez
     * y con un filtro distinto del que usaba el canal: coincidían de
     * casualidad, y la tarjeta podía terminar diciendo algo que la Orden de
     * Pago no.
     *
     * @param  list<int>  $cuotaIds
     * @return array<int, CashToBankTransfer>
     */
    private function trasladosVigentes(array $cuotaIds): array
    {
        return app(PaymentOrderSources::class)->transfersFor($cuotaIds);
    }

    /**
     * Si alguna cuota del haber se espera por transferencia.
     *
     * Es la condición para que exista un comprobante bancario que cargar:
     * lo que entra en efectivo o cheque llega por mostrador y no genera
     * ticket. Una cuota sin medio declarado tampoco cuenta — el medio
     * previsto es opcional, y suponerlo sería inventar el circuito.
     */
    private function esperaTransferencia(Haber $haber): bool
    {
        return $haber->installments()
            ->where('expected_medium', ExpectedMedium::Bank->value)
            ->exists();
    }

    /**
     * Detalle de un haber, con su plan de cuotas.
     *
     * El haber vivía dentro del expediente como una fila desplegable, y ahí
     * entraba mientras la cuota fuera un importe. Ahora la cuota tiene
     * concepto, etiqueta, medio previsto, observaciones y estado propio, y
     * el plan puede llegar a sesenta: eso no cabe en un acordeón dentro de
     * una lista de cinco beneficiarios.
     *
     * El expediente sigue arriba porque el haber no se entiende suelto: el
     * número, el empleador y el total declarado son el marco contra el que
     * se lee lo que este beneficiario tiene reconocido.
     */
    public function show(Request $request, Expediente $expediente, Haber $haber): Response|RedirectResponse
    {
        /*
         * La dirección se acomoda a la forma con número, igual que la del
         * expediente: los enlaces de adentro se arman con el id porque los
         * helpers tipados del front no conocen la clave de ruta del modelo.
         *
         * El haber sigue por id y no por el nombre del beneficiario. Un
         * nombre en la barra de direcciones se copia a un correo, a un
         * ticket o a un chat, y queda en el registro de cada servidor por
         * el que pasa: es dato personal saliendo del sistema sin que nadie
         * lo haya decidido. El número del expediente es el identificador de
         * un trámite; el nombre de quien cobra, no.
         */
        $clave = $request->route()?->originalParameters()['expediente'] ?? null;

        if (is_string($clave) && $clave !== $expediente->getRouteKey()) {
            return to_route('haberes.haber.show', [$expediente, $haber]);
        }

        /*
         * El domicilio y el teléfono de las dos partes viajan acá aunque
         * la ficha del haber no los muestre: los pide el formulario de la
         * Orden de Pago, y su modal necesita saber cuáles faltan. Sin
         * pedirlos en el `select`, `preventAccessingMissingAttributes` los
         * convierte en un 500 en vez de en un nulo.
         */
        $haber->load([
            'beneficiary:id,name,document,type,address,phone',
            'creator:id,name',
            'installments' => fn ($cuotas) => $cuotas->orderBy('installment_number'),
            'installments.managementLabel:id,code,blocks_payment',
            // El comprobante que el expediente trajo para cada cuota: la
            // tarjeta lo usa para ofrecer cargarlo o mostrar el que ya está.
            'installments.depositTickets.account:id,label',
        ]);
        $expediente->load('employer:id,name,document,address,phone');

        /*
         * El expediente ya cargado se le presta al haber: es el mismo, y
         * sin esto `$haber->expediente` saldría a buscarlo de nuevo —o
         * reventaría, con el modo estricto encendido—.
         */
        $haber->setRelation('expediente', $expediente);

        /*
         * La financiación y el recibo de cada cuota, en dos consultas.
         *
         * Sin esto la tarjeta no puede decidir nada: ofrecer el recibo
         * depende de que la cuota esté completa, y eso se calcula (§5.1).
         * Van en lote porque un plan puede tener sesenta cuotas.
         */
        /** @var list<int> $cuotaIds */
        $cuotaIds = $haber->installments->map(fn (BeneficiaryInstallment $c): int => $c->id)->all();

        return Inertia::render('haberes/haber-show', [
            'haber' => HaberListItemData::fromModel(
                haber: $haber,
                funded: app(InstallmentFunding::class)->allocatedForMany($cuotaIds),
                receipts: $this->recibosVigentes($cuotaIds),
                transfers: $this->trasladosVigentes($cuotaIds),
                allocations: $this->asignacionesVigentes($cuotaIds),
                voidedReceipts: $this->recibosAnulados($cuotaIds),
                cancelledTransfers: $this->trasladosCancelados($cuotaIds),
                mediums: app(InstallmentFunding::class)->mediumForMany($cuotaIds),
                ticketReceipts: app(DepositTicketLinks::class)->receipts($cuotaIds),
                ticketAttachments: app(DepositTicketLinks::class)->attachments($cuotaIds),
                stages: app(InstallmentStages::class)->forMany($haber->installments),
                lastChange: app(LastChanges::class)->for(
                    'Haber',
                    [$haber->id],
                    ignoring: ['haber.reconocido'],
                )[$haber->id] ?? null,
            ),
            'expediente' => [
                'id' => $expediente->id,
                'displayNumber' => $expediente->display_number,
                'canonicalNumber' => $expediente->canonical_number,
                'subject' => $expediente->subject,
                'employerName' => $expediente->employer?->name,
                'declaredTotalAmount' => $expediente->declared_total_amount,
                'status' => $expediente->status,
            ],
            'beneficiario' => [
                'name' => $haber->beneficiary->name,
                'document' => $haber->beneficiary->document,
            ],
            'etiquetas' => HaberManagementLabel::query()
                ->active()
                ->orderBy('sort_order')
                ->get(['id', 'code', 'description']),
            // Para el modal del comprobante, que deja corregir la cuenta.
            'cuentas' => BankAccount::query()
                ->where('is_active', true)
                ->orderBy('label')
                ->get(['id', 'label']),
            'extras' => HaberExtraData::fromModel($haber),
            'canEdit' => request()->user()?->can('expedientes.editar') ?? false,
            /*
             * Cargar y corregir el comprobante son el mismo acto —copiar
             * bien un dato de un papel— y llevan el mismo permiso.
             */
            'canManageTickets' => request()->user()?->can('depositos.registrar') ?? false,
            'canIssueReceipt' => request()->user()?->can('recibos.emitir') ?? false,
            'canRegisterPayment' => request()->user()->can('recepciones.registrar')
                && request()->user()->can('recepciones.asignar'),
            /*
             * Llevar al banco el efectivo que nadie retiro, y confirmarlo
             * contra el extracto: dos actos, dos permisos.
             */
            'canTransferCash' => request()->user()?->can('caja.trasladar') ?? false,
            'canConfirmTransfer' => request()->user()?->can('caja.confirmar-traslado') ?? false,
            /*
             * Devolver plata imputada deshace un asiento: va con el
             * permiso de revertir, no con el de asignar.
             */
            'canUnallocate' => request()->user()?->can('recepciones.revertir') ?? false,
            /*
             * Anular el cobro deja sin respaldo un comprobante ya
             * entregado: va con el permiso de anular recibos.
             */
            'canVoidReceipt' => request()->user()?->can('recibos.anular') ?? false,
            /*
             * Quienes pueden figurar al pie del comprobante. El cargo va
             * al lado del nombre porque es lo que distingue a la persona
             * en el papel: «Asesor Contable», no solo el apellido.
             */
            'firmantes' => User::query()
                ->where('is_active', true)
                ->whereNotNull('position')
                ->orderBy('name')
                ->get(['id', 'name', 'position']),
            /*
             * El último número de talonario cargado en cada serie.
             *
             * No es una secuencia que el sistema controle —el número sale
             * de un lote de papel ajeno, es opcional y no se espera
             * correlativo—. Va para que la pantalla pueda avisar cuando el
             * que se tipea no sigue al anterior, que es como se caza un
             * `72912` escrito donde iba `72192`. El aviso no bloquea nada.
             */
            'ultimoTalonario' => app(TalonarioSequence::class)->all(),
            'canCancel' => request()->user()?->can('expedientes.anular') ?? false,
            /*
             * Órdenes de Pago: qué le corresponde a cada cuota, y los
             * datos que el modal comparte para todo el haber.
             */
            'ordenes' => $this->estadoDeOrdenes($haber, $cuotaIds),
            'canViewOrder' => request()->user()?->can('ordenes.ver') ?? false,
            'canIssueOrder' => request()->user()?->can('ordenes.emitir') ?? false,
            'canVoidOrder' => request()->user()?->can('ordenes.anular') ?? false,
            'canVerifyCbu' => request()->user()?->can('personas.verificar-cbu') ?? false,
            /*
             * Egresos: si a cada cuota se le puede pagar al beneficiario,
             * con que, y que quedo registrado si ya se pago.
             */
            'egresos' => $this->estadoDeEgresos($haber, $cuotaIds),
            'canPayBeneficiary' => request()->user()?->can('egresos.registrar') ?? false,
            /*
             * Validar el pago del organismo es del contador: postea el
             * asiento y deja la cuota pagada. Permiso propio.
             */
            'canValidateDisbursement' => request()->user()?->can('egresos.validar') ?? false,
        ]);
    }

    /**
     * El estado de la Orden de Pago de cada cuota, indexado por cuota.
     *
     * **El medio va en lote** porque `PaymentOrderEligibility` lo necesita
     * para lo primero que decide —si la cuota se paga por mostrador— y
     * preguntárselo a cada una sería una consulta por fila en un plan que
     * puede tener sesenta.
     *
     * Lo que sigue costando por cuota es la tabla de depósitos, y solo
     * para las que van por transferencia: son las únicas donde ese cuadro
     * se imprime. Una cuota de mostrador sale de la evaluación antes de
     * tocar sus asignaciones.
     *
     * @param  list<int>  $cuotaIds
     * @return array<int, InstallmentOrderStateData>
     */
    private function estadoDeOrdenes(Haber $haber, array $cuotaIds): array
    {
        if ($cuotaIds === []) {
            return [];
        }

        $habilitacion = app(PaymentOrderEligibility::class);
        $medios = app(InstallmentFunding::class)->mediumForMany($cuotaIds);
        $cuentas = BankAccount::query()->pluck('label', 'id');
        $estados = [];

        foreach ($haber->installments as $cuota) {
            /*
             * El haber ya está cargado con su beneficiario y su
             * expediente: prestárselo a la cuota evita que el
             * `loadMissing` de la habilitación salga a buscarlo de nuevo,
             * una vez por cuota del plan.
             */
            $cuota->setRelation('haber', $haber);

            $estado = $habilitacion->for($cuota, $medios[$cuota->id] ?? null);

            $estados[$cuota->id] = InstallmentOrderStateData::fromReadiness(
                $cuota,
                $estado,
                $estado->organismBankAccountId === null
                    ? null
                    : $cuentas->get($estado->organismBankAccountId),
            );
        }

        return $estados;
    }

    /**
     * El estado del egreso de cada cuota, indexado por cuota.
     *
     * Mismo lote de medios que las Ordenes y por el mismo motivo: es lo
     * primero que `DisbursementEligibility` necesita —si la cuota se paga
     * por mostrador o sale por transferencia— y preguntarselo a cada una
     * seria una consulta por fila en un plan que puede tener sesenta.
     *
     * @param  list<int>  $cuotaIds
     * @return array<int, InstallmentDisbursementData>
     */
    private function estadoDeEgresos(Haber $haber, array $cuotaIds): array
    {
        if ($cuotaIds === []) {
            return [];
        }

        $habilitacion = app(DisbursementEligibility::class);
        $medios = app(InstallmentFunding::class)->mediumForMany($cuotaIds);
        $estados = [];

        foreach ($haber->installments as $cuota) {
            // El haber ya viene cargado con su beneficiario: prestarselo
            // evita una consulta por cuota del plan.
            $cuota->setRelation('haber', $haber);

            $estados[$cuota->id] = InstallmentDisbursementData::fromReadiness(
                $habilitacion->for($cuota, $medios[$cuota->id] ?? null),
            );
        }

        return $estados;
    }

    /**
     * Anula un haber sin tocar el resto del expediente.
     *
     * Hasta acá la única baja era la del expediente entero, y para un
     * haber cargado de más eso obligaba a anular todo y volver a cargar lo
     * que sí estaba bien. Un haber anulado deja de contar en lo
     * reconocido, que es lo que hace que el expediente vuelva a cuadrar.
     */
    public function cancel(
        CancelHaberRequest $request,
        Expediente $expediente,
        Haber $haber,
        CancelHaber $anular,
    ): RedirectResponse {
        $anular->handle($haber, (string) $request->validated('reason'));

        return back()->with(
            'status',
            "Haber de {$haber->beneficiary->name} anulado. Deja de contar en lo reconocido.",
        );
    }

    public function reactivate(
        CancelHaberRequest $request,
        Expediente $expediente,
        Haber $haber,
        ReactivateHaber $reactivar,
    ): RedirectResponse {
        $reactivar->handle($haber, (string) $request->validated('reason'));

        return back()->with(
            'status',
            "Haber de {$haber->beneficiary->name} reactivado.",
        );
    }
}
