<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Models\User;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Haberes\Data\IssuePaymentOrderData;
use App\Modules\Haberes\Enums\PaseStatus;
use App\Modules\Haberes\Enums\PaymentOrderStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Haberes\Models\Haber;
use App\Modules\Haberes\Models\Pase;
use App\Modules\Haberes\Models\PaymentOrder;
use App\Modules\Haberes\Models\PaymentOrderFundingSource;
use App\Modules\Haberes\Support\PaymentOrderEligibility;
use App\Modules\Haberes\Support\PaymentOrderObservation;
use App\Modules\Haberes\Support\PaymentOrderReadiness;
use App\Modules\Haberes\Support\PaymentOrderSources;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Actions\TakeNextDocumentNumber;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Models\DocumentSeries;
use App\Modules\Shared\Models\PersonBankAccount;
use App\Support\BusinessDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Emite la Orden de Pago de una cuota, junto con su nota de Pase.
 *
 * **Los dos documentos nacen en el mismo acto** porque en la realidad
 * viajan juntos: el §9.7 del DER lo dice y el área lo confirmó. El
 * expediente no va al organismo superior; van la Orden y el Pase, y nada
 * más. Emitir uno sin el otro produciría media remisión.
 *
 * ── Qué se deriva y qué se elige ───────────────────────────────────────
 *
 * El importe, la tabla de depósitos, la cuenta del organismo y todos los
 * datos de las partes **los deriva este Action de la cuota**. Del
 * formulario llega únicamente lo que es una decisión: el tipo de Orden,
 * cuál cuenta verificada se usa, la foja del CBU, qué número de recibo se
 * imprime, las observaciones y el destino del Pase.
 *
 * Es la misma regla que rige el recibo de ingreso (Correcciones §33,
 * desvío 4), y acá pesa más: un importe propuesto por el navegador en un
 * documento que le pide a otro organismo que transfiera dinero es
 * exactamente la clase de dato que no puede venir de afuera.
 *
 * ── Qué congela ────────────────────────────────────────────────────────
 *
 * Todo lo que el papel dice, en columnas `_snapshot`. Cuando el organismo
 * devuelva el expediente porque la cuenta informada era un CVU, la
 * corrección se documenta como evidencia y **la fotografía de la Orden
 * original no se toca** (§2.2.10). Lo impide además un trigger.
 */
final class IssuePaymentOrder
{
    public function __construct(
        private readonly TakeNextDocumentNumber $numerar,
        private readonly PaymentOrderEligibility $habilitacion,
        private readonly PaymentOrderSources $origen,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(
        BeneficiaryInstallment $installment,
        IssuePaymentOrderData $data,
        ?int $actorId = null,
    ): PaymentOrder {
        return DB::transaction(function () use (
            $installment, $data, $actorId
        ): PaymentOrder {
            $haberId = $installment->haber_id;
            $expedienteId = (int) Haber::query()->whereKey($haberId)->valueOrFail('expediente_id');

            $expedienteBloqueado = Expediente::query()->lockForUpdate()->findOrFail($expedienteId);
            $haberBloqueado = Haber::query()
                ->where('expediente_id', $expedienteBloqueado->id)
                ->lockForUpdate()
                ->findOrFail($haberId);
            $cuotaBloqueada = BeneficiaryInstallment::query()
                ->where('haber_id', $haberBloqueado->id)
                ->lockForUpdate()
                ->findOrFail($installment->id);

            // La elegibilidad se vuelve a calcular bajo los mismos locks
            // que usan asignar y liberar fondos. La Orden no puede congelar
            // una foto que otra operación está cambiando al mismo tiempo.
            $estado = $this->habilitacion->for($cuotaBloqueada);
            $this->assertIssuable($estado);

            $cuenta = $this->cuentaElegida($cuotaBloqueada, $data, $estado);
            $firmante = $data->treasurerId === null ? null : User::query()->find($data->treasurerId);

            $serie = DocumentType::PaymentOrder->seriesCode();
            $numero = $this->numerar->handle($serie);

            $orden = PaymentOrder::query()->create([
                ...$this->componer($cuotaBloqueada, $data, $estado, $cuenta, $firmante),
                'document_series_id' => DocumentSeries::query()->where('code', $serie)->value('id'),
                'number' => $numero['number'],
                'formatted_number' => $numero['formatted'],
                'status' => PaymentOrderStatus::Draft,
                'created_by' => $actorId,
                'replaces_order_id' => $this->ultimaAnulada($cuotaBloqueada)?->id,
            ]);

            $this->congelarFuentes($orden, $estado);

            $pase = Pase::query()->create([
                ...$this->componerPase($data, $orden),
                'payment_order_id' => $orden->id,
                'generated_by' => $actorId,
            ]);

            $this->auditar->handle('orden-de-pago.emitida', $orden, after: [
                'formatted_number' => $orden->formatted_number,
                'beneficiary_installment_id' => $cuotaBloqueada->id,
                'amount' => $orden->amount,
                'beneficiary_cbu_snapshot' => $orden->beneficiary_cbu_snapshot,
                'pase_id' => $pase->id,
                'pase_destination' => $pase->destination,
            ], actorId: $actorId);

            return $orden;
        });
    }

    /**
     * La Orden sin emitir, para que la pantalla la muestre antes.
     *
     * **Es el mismo armado que la emisión**, y por eso vive acá: una vista
     * previa que compone los datos por su cuenta termina mostrando algo
     * distinto de lo que se imprime, y eso es peor que no tener vista
     * previa.
     *
     * Lo único que le falta es el número definitivo, que se toma al emitir
     * bajo lock y por lo tanto todavía no existe.
     */
    public function preview(
        BeneficiaryInstallment $installment,
        IssuePaymentOrderData $data,
    ): PaymentOrder {
        $estado = $this->habilitacion->for($installment);
        $cuenta = $estado->incomeReceipt === null
            ? null
            : $this->cuentaPropuesta($installment, $data);
        $firmante = $data->treasurerId === null ? null : User::query()->find($data->treasurerId);

        $orden = new PaymentOrder($this->componer($installment, $data, $estado, $cuenta, $firmante));
        $orden->status = PaymentOrderStatus::Draft;

        $serie = DocumentSeries::query()
            ->where('code', DocumentType::PaymentOrder->seriesCode())
            ->first();

        /*
         * El número que le tocaría, como anticipo y no como promesa: entre
         * mirar y confirmar puede emitirse otra Orden, y el correlativo
         * real se toma bajo lock recién en ese momento.
         *
         * Va también el pelado, que es el que el formulario imprime
         * grande: sin él el borrador salía con el renglón del número en
         * blanco, que es justo lo que se quiere mirar antes de firmar.
         */
        $orden->number = $serie->next_number ?? 0;
        $orden->formatted_number = $serie === null ? '—' : $serie->formatNumber($serie->next_number);
        $orden->setRelation('organismBankAccount', $estado->organismBankAccountId === null
            ? null
            : BankAccount::query()->find($estado->organismBankAccountId));

        return $orden;
    }

    /**
     * La nota de Pase sin emitir, para mirarla junto a su Orden.
     *
     * Los dos documentos viajan juntos, así que también se revisan juntos:
     * quien firma tiene que poder leer la nota antes de que salga, no
     * enterarse de lo que decía después de emitida.
     *
     * Se apoya en `preview()` y en el mismo `componerPase()` que usa la
     * emisión, por el motivo de siempre: dos armados distintos terminan
     * mostrando algo que no es lo que se imprime.
     */
    public function previewPase(
        BeneficiaryInstallment $installment,
        IssuePaymentOrderData $data,
    ): Pase {
        $orden = $this->preview($installment, $data);

        $pase = new Pase($this->componerPase($data, $orden));
        $pase->setRelation('paymentOrder', $orden);

        return $pase;
    }

    /**
     * Lo que la nota dice.
     *
     * Corto, porque casi todo lo que el Pase imprime lo lee de su Orden:
     * el beneficiario, el importe, la referencia del expediente y la foja
     * del CBU ya están congelados ahí. Lo propio de la nota es a quién va
     * dirigida y cuándo se emitió.
     *
     * @return array<string, mixed>
     */
    private function componerPase(IssuePaymentOrderData $data, PaymentOrder $orden): array
    {
        return [
            'destination' => $data->paseDestination,
            /*
             * La misma fecha que la Orden. Se generan juntas y el papel de
             * las dos lleva el día en que salieron.
             */
            'issue_date' => $orden->order_date,
            'status' => PaseStatus::Generated,
        ];
    }

    /**
     * Lo que va a decir el papel.
     *
     * Una sola definición para la emisión y para la vista previa: es lo
     * que garantiza que lo que se mira sea lo que se imprime.
     *
     * @return array<string, mixed>
     */
    private function componer(
        BeneficiaryInstallment $installment,
        IssuePaymentOrderData $data,
        PaymentOrderReadiness $estado,
        ?PersonBankAccount $cuenta,
        ?User $firmante,
    ): array {
        $haber = $installment->haber;
        $expediente = $haber->expediente;
        $beneficiario = $haber->beneficiary;
        $empleador = $expediente->employer;
        $recibo = $estado->incomeReceipt;
        $cheque = $this->chequeDeLaCuota($installment);

        return [
            'order_date' => BusinessDate::today()->toDateString(),
            'beneficiary_installment_id' => $installment->id,
            /*
             * El importe es lo que la cuota tiene financiado y por lo tanto
             * lo que el recibo de ingreso ya dice. Que salgan del mismo
             * lugar es lo que hace que los dos papeles cierren entre sí.
             */
            'amount' => $recibo->amount ?? $this->origen->total($estado->rows),

            'beneficiary_bank_account_id' => $cuenta?->id,
            'beneficiary_person_id' => $cuenta === null ? null : $beneficiario->id,
            'beneficiary_cbu_snapshot' => $cuenta?->cbu,
            'beneficiary_bank_name_snapshot' => $cuenta?->bank_name,
            'beneficiary_account_number_snapshot' => $cuenta?->account_number,
            'beneficiary_name_snapshot' => $beneficiario->name,
            'beneficiary_document_snapshot' => $beneficiario->document,
            'beneficiary_address_snapshot' => $beneficiario->address,
            'beneficiary_phone_snapshot' => $beneficiario->phone,
            'cbu_folio_snapshot' => $data->cbuFolio,

            'employer_name_snapshot' => $empleador?->name,
            'employer_tax_identifier_snapshot' => $empleador?->document,
            'employer_address_snapshot' => $empleador?->address,
            'employer_phone_snapshot' => $empleador?->phone,
            'expediente_number_snapshot' => $expediente->display_number,
            'expediente_canonical_snapshot' => $expediente->canonical_number,
            'expediente_subject_snapshot' => $expediente->subject,
            /*
             * «FECHA INI.»: desde cuándo el organismo tiene estos fondos en
             * custodia. Es la fecha en que entró el expediente, que es
             * cuando el área toma contacto con el caso.
             */
            'custody_start_date_snapshot' => $expediente->received_date,

            'organism_bank_account_id' => $estado->organismBankAccountId,

            'income_receipt_id' => $recibo?->id,
            'income_receipt_number_source' => $data->incomeReceiptNumberSource,
            'income_receipt_number_snapshot' => $recibo === null
                ? '—'
                : $data->incomeReceiptNumberSource->numberOf($recibo),

            'cheque_number_snapshot' => $cheque?->cheque_number,
            'cheque_bank_snapshot' => $cheque?->cheque_bank,

            'treasurer_id' => $firmante?->id,
            'treasurer_name_snapshot' => $firmante?->name,
            'treasurer_title_snapshot' => $firmante?->position,

            /*
             * El OBS no llega del formulario: es la foja, redactada. El
             * área confirmó que ahí no se escribe nada más (D-003), y
             * escribirla dos veces era la forma segura de que alguna vez
             * no coincidieran.
             */
            'notes' => PaymentOrderObservation::forFolio($data->cbuFolio),
        ];
    }

    /**
     * Copia la tabla de depósitos a su fotografía.
     *
     * Es lo único que `payment_order_funding_sources` congela: el resto de
     * la Orden ya vive en sus propias columnas. Un renglón por asignación
     * vigente, que son varias cuando el depósito llegó fraccionado.
     */
    private function congelarFuentes(PaymentOrder $orden, PaymentOrderReadiness $estado): void
    {
        foreach ($estado->rows as $renglon) {
            PaymentOrderFundingSource::query()->create($renglon->toAttributes($orden->id));
        }
    }

    /**
     * El cheque que financió la cuota, si el valor en custodia es uno.
     *
     * El formulario tiene renglón propio para su número y su banco, y el
     * §2.5.3 explica por qué: el cheque se distingue para la impresión del
     * comprobante aunque siga el circuito del efectivo.
     */
    private function chequeDeLaCuota(BeneficiaryInstallment $installment): ?FundReceipt
    {
        $asignacion = FundingAllocation::query()
            ->live()
            ->where('beneficiary_installment_id', $installment->id)
            ->with('fundReceipt')
            ->orderBy('id')
            ->first();

        $recepcion = $asignacion?->fundReceipt;

        return $recepcion?->medium === PaymentMedium::Cheque ? $recepcion : null;
    }

    /**
     * La cuenta del beneficiario a la que se va a pedir transferir.
     *
     * @throws ValidationException
     */
    private function cuentaElegida(
        BeneficiaryInstallment $installment,
        IssuePaymentOrderData $data,
        PaymentOrderReadiness $estado,
    ): PersonBankAccount {
        $cuenta = $this->cuentaPropuesta($installment, $data);

        if ($cuenta === null) {
            throw ValidationException::withMessages([
                'beneficiaryBankAccountId' => 'El beneficiario no tiene ninguna cuenta verificada. '
                    .'El sistema opera únicamente con CBU, y la cuenta tiene que estar verificada '
                    .'antes de pedirle al organismo que transfiera.',
            ]);
        }

        if ($estado->organismBankAccountId === null) {
            throw ValidationException::withMessages([
                'installmentId' => 'No se pudo determinar en qué cuenta del organismo está este dinero. '
                    .'Sin eso el formulario no tiene qué casilla marcar.',
            ]);
        }

        return $cuenta;
    }

    /**
     * La cuenta pedida, o la primera verificada si no se pidió ninguna.
     *
     * @throws ValidationException
     */
    private function cuentaPropuesta(
        BeneficiaryInstallment $installment,
        IssuePaymentOrderData $data,
    ): ?PersonBankAccount {
        $beneficiario = $installment->haber->beneficiary;
        $verificadas = $this->habilitacion->verifiedAccounts($beneficiario);

        if ($data->beneficiaryBankAccountId === null) {
            return $verificadas->first();
        }

        $elegida = $verificadas->firstWhere('id', $data->beneficiaryBankAccountId);

        if ($elegida === null) {
            throw ValidationException::withMessages([
                'beneficiaryBankAccountId' => 'Esa cuenta no pertenece al beneficiario o no está verificada.',
            ]);
        }

        return $elegida;
    }

    /**
     * La última Orden anulada de esta cuota, si la hubo.
     *
     * Se resuelve acá y no se le pregunta al operador: la que se reemplaza
     * es siempre la última anulada, y pedírselo sería pedirle que repita
     * un dato que el sistema ya sabe.
     *
     * **El número no se hereda.** La anulada se queda con el suyo —un
     * documento que existió consume su número— y ésta toma el siguiente
     * libre de la serie.
     */
    private function ultimaAnulada(BeneficiaryInstallment $installment): ?PaymentOrder
    {
        return PaymentOrder::query()
            ->where('beneficiary_installment_id', $installment->id)
            ->where('status', PaymentOrderStatus::Voided)
            ->whereDoesntHave('replacedBy')
            ->latest('id')
            ->first();
    }

    /**
     * @throws ValidationException
     */
    private function assertIssuable(PaymentOrderReadiness $estado): void
    {
        if (! $estado->applies) {
            throw ValidationException::withMessages([
                'installmentId' => 'Esta cuota se paga por mostrador: el dinero está en la caja y se '
                    .'entrega en mano. La Orden de Pago corresponde cuando el pago sale por '
                    .'transferencia del organismo.',
            ]);
        }

        if ($estado->activeOrder !== null) {
            throw ValidationException::withMessages([
                'installmentId' => "La cuota ya tiene la Orden {$estado->activeOrder->formatted_number}. "
                    .'Para emitir otra hay que anular esa primero.',
            ]);
        }

        if ($estado->blockedReason !== null) {
            throw ValidationException::withMessages([
                'installmentId' => $estado->blockedReason,
            ]);
        }

        $faltan = $estado->requiredMissing();

        if ($faltan !== []) {
            throw ValidationException::withMessages([
                'installmentId' => 'Faltan datos que el formulario imprime: '
                    .implode(', ', array_map(
                        fn ($campo): string => mb_strtolower($campo->label),
                        $faltan,
                    )).'.',
            ]);
        }
    }
}
