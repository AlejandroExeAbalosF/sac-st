<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Haberes\Data\InstallmentReceiptData;
use App\Modules\Haberes\Enums\DisbursementMethod;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Enums\PaymentChannel;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Disbursement;
use App\Modules\Haberes\Models\PaymentOrder;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Shared\Enums\ReceiptStatus;
use App\Modules\Shared\Enums\ReceiptType;
use App\Modules\Shared\Models\Receipt;
use App\Support\Money\Decimal;

/**
 * Si a esta cuota se le puede pagar al beneficiario, y por dónde.
 *
 * **Es la única fuente de la respuesta.** La tarjeta de la cuota decide
 * con esto qué botones mostrar, los diálogos deciden con esto qué
 * advertir, y los Actions deciden con esto si dejan avanzar. Si cada uno
 * razonara por su cuenta, la pantalla terminaría ofreciendo entregar un
 * dinero que la base rechaza —o peor, que no está—.
 *
 * ── Los dos circuitos ──────────────────────────────────────────────────
 *
 * | Canal | Cómo sale | Qué habilita el recibo |
 * |---|---|---|
 * | `counter` | efectivo o el cheque mismo, en mano | la entrega |
 * | `transfer` | el organismo transfiere | informe + débito + validación |
 * | `undetermined` | el depósito no acreditó todavía | nada: no se paga |
 *
 * El canal no lo elige nadie: lo dicta dónde está el dinero, y el §2.4.6
 * lo separa de cómo entró. Una cuota cobrada en efectivo pasa de
 * `counter` a `transfer` sola en cuanto la contadora deposita y el banco
 * acredita.
 *
 * ── Dónde vive la regla del §2.2.7 ─────────────────────────────────────
 *
 * *«Una condición administrativa sobre la cuota bloquea el egreso, nunca
 * el ingreso.»* El empleador deposita igual —en el caso García/CIACSA
 * depositó las dos cuotas—; lo que la Secretaría retiene es **la entrega
 * al trabajador**. La Orden de Pago ya lo comprueba, pero el mostrador no
 * pasa por ninguna Orden: acá la traba alcanza a los dos circuitos, que es
 * lo que la Resolución 3671/16 exige.
 *
 * ── Por qué el recibo de ingreso va antes ──────────────────────────────
 *
 * El DER no lo dice con estas palabras, pero los dos comprobantes son los
 * dos extremos del mismo dinero y el expediente los archiva juntos. Emitir
 * el de egreso sin el de ingreso dejaría el legajo diciendo que el
 * trabajador cobró algo que nadie documentó haber recibido.
 */
final class DisbursementEligibility
{
    public function __construct(
        private readonly InstallmentFunding $financiacion,
        private readonly PaymentOrderSources $origen,
        private readonly TransferStage $etapa,
    ) {}

    /**
     * @param  PaymentMedium|null  $medium  El medio ya resuelto, cuando quien
     *                                      llama lo trae en lote. Un plan puede
     *                                      tener sesenta cuotas y preguntárselo a
     *                                      cada una sería una consulta por fila.
     */
    public function for(BeneficiaryInstallment $installment, ?PaymentMedium $medium = null): DisbursementReadiness
    {
        $installment->loadMissing(['haber.beneficiary', 'managementLabel']);

        $medio = $medium ?? $this->financiacion->medium($installment);
        $canal = $this->origen->channel($installment, $medio);
        $egreso = $this->egresoVivo($installment);
        $reciboIngreso = $this->recibo($installment, ReceiptType::Income);
        $orden = $canal === PaymentChannel::Counter ? null : $this->ordenVigente($installment);

        return new DisbursementReadiness(
            channel: $canal,
            /*
             * El depósito en tránsito es la única situación en la que no
             * hay nada que hacer: el efectivo salió de la caja y la cuenta
             * del organismo todavía no lo tiene (§2.4.7).
             */
            applies: $canal !== PaymentChannel::Undetermined,
            blockedReason: $this->traba($installment, $canal, $reciboIngreso, $orden),
            method: $this->metodo($canal, $medio),
            /*
             * En el mostrador, lo asignado: en efectivo se entrega **todo**
             * lo recibido, incluido el excedente de redondeo (§2.4). Por
             * transferencia, lo que dice la Orden: el excedente bancario
             * queda fuera del recibo, de la Orden y del egreso.
             */
            amount: $orden->amount ?? $this->financiacion->allocated($installment),
            disbursement: $egreso,
            expenseReceipt: $this->recibo($installment, ReceiptType::Expense),
            incomeReceipt: $reciboIngreso,
            paymentOrder: $orden,
            pendingStep: $egreso === null ? null : $this->etapa->missing($egreso),
            voidedExpenseReceipts: $this->recibosAnulados($installment),
        );
    }

    /**
     * Lo que impide avanzar, en orden de precedencia.
     *
     * El orden importa: a quien todavía no cobró la cuota no le sirve
     * enterarse de que además la etiqueta la bloquea. Se dice lo primero
     * que hay que resolver.
     */
    private function traba(
        BeneficiaryInstallment $installment,
        PaymentChannel $canal,
        ?Receipt $reciboIngreso,
        ?PaymentOrder $orden,
    ): ?string {
        if ($installment->workflow_status === InstallmentWorkflowStatus::Cancelled) {
            return 'La cuota está anulada.';
        }

        if ($canal === PaymentChannel::Undetermined) {
            return 'El depósito al banco todavía no está acreditado. Hasta que el extracto lo '
                .'confirme, la cuenta del organismo no tiene ese dinero.';
        }

        if (! $this->financiacion->isFullyFunded($installment)) {
            return sprintf(
                'La cuota todavía no está completa: le faltan $ %s. '
                .'No se entrega un dinero que no entró.',
                Decimal::format($this->financiacion->remaining($installment)),
            );
        }

        if ($reciboIngreso === null) {
            return 'Falta emitir el recibo de ingreso. Los dos comprobantes son los dos extremos '
                .'del mismo dinero y el expediente los archiva juntos.';
        }

        /*
         * §2.2.7 y Resolución 3671/16: no se liberan fondos sin
         * homologación previa. Es la traba que existe justamente para este
         * momento.
         */
        if ($installment->managementLabel?->blocks_payment === true) {
            return sprintf(
                'La etiqueta «%s» retiene la entrega al trabajador hasta que se levante.',
                $installment->managementLabel->code,
            );
        }

        /*
         * Sin Orden no hay transferencia posible: el organismo no mueve
         * dinero de un tercero sin el papel que se lo pide, y la base
         * tampoco admite el egreso.
         */
        if ($canal === PaymentChannel::Transfer && $orden === null) {
            return 'Falta emitir la Orden de Pago. El organismo no transfiere sin ella, '
                .'y se genera desde la sección de arriba.';
        }

        return null;
    }

    /**
     * Con qué se paga.
     *
     * El cheque se entrega como cheque (§2.5.5): el papel que estaba en
     * custodia cambia de manos, y por eso el formulario tiene su casilla
     * `Cheque Terc.`. Convertirlo a efectivo sería inventar un movimiento
     * de caja que no ocurrió.
     */
    private function metodo(PaymentChannel $canal, ?PaymentMedium $medio): ?DisbursementMethod
    {
        if ($canal === PaymentChannel::Transfer) {
            return DisbursementMethod::BankTransfer;
        }

        return match ($medio) {
            PaymentMedium::Cash => DisbursementMethod::Cash,
            PaymentMedium::Cheque => DisbursementMethod::Cheque,
            default => null,
        };
    }

    private function egresoVivo(BeneficiaryInstallment $installment): ?Disbursement
    {
        return Disbursement::query()
            ->live()
            ->where('beneficiary_installment_id', $installment->id)
            ->with([
                'cashDeliveredBy:id,name',
                'validatedBy:id,name',
                'bankTransaction:id,transaction_date,amount,operation_id,description,counterparty_name',
            ])
            ->first();
    }

    private function ordenVigente(BeneficiaryInstallment $installment): ?PaymentOrder
    {
        return PaymentOrder::query()
            ->active()
            ->where('beneficiary_installment_id', $installment->id)
            ->first();
    }

    private function recibo(BeneficiaryInstallment $installment, ReceiptType $tipo): ?Receipt
    {
        return Receipt::query()
            ->with(InstallmentReceiptData::RELATIONS)
            ->issued()
            ->where('receipt_type', $tipo)
            ->where('beneficiary_installment_id', $installment->id)
            ->first();
    }

    /**
     * Los recibos de egreso que quedaron sin efecto.
     *
     * Del más nuevo al más viejo: la cadena de reemplazos se lee del
     * vigente hacia atrás, que es la dirección en que alguien pregunta por
     * qué este comprobante tiene este número.
     *
     * @return list<Receipt>
     */
    private function recibosAnulados(BeneficiaryInstallment $installment): array
    {
        return array_values(Receipt::query()
            ->with(InstallmentReceiptData::RELATIONS)
            ->where('receipt_type', ReceiptType::Expense)
            ->where('beneficiary_installment_id', $installment->id)
            ->whereIn('status', [ReceiptStatus::Voided, ReceiptStatus::Replaced])
            ->orderByDesc('id')
            ->get()
            ->all());
    }
}
