<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Enums\PaymentChannel;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\PaymentOrder;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Shared\Enums\ReceiptType;
use App\Modules\Shared\Models\Person;
use App\Modules\Shared\Models\PersonBankAccount;
use App\Modules\Shared\Models\Receipt;
use App\Support\Money\Decimal;
use Illuminate\Database\Eloquent\Collection;

/**
 * Si esta cuota lleva Orden de Pago, y qué le falta para poder emitirla.
 *
 * **Es la única fuente de la respuesta.** La tarjeta de la cuota decide
 * con esto qué botón mostrar, el modal decide con esto qué secciones
 * pintar en rojo, y el Action decide con esto si deja emitir. Si cada uno
 * razonara por su cuenta, la pantalla terminaría ofreciendo un papel que
 * la base rechaza.
 *
 * ── Cuándo corresponde ─────────────────────────────────────────────────
 *
 * La Orden existe **solo cuando el dinero sale por transferencia del
 * organismo**. El §2.4.6 separa la entrada de la salida, y el §2.4.7
 * cierra la regla: *«depositado el efectivo, el pago solo puede hacerse
 * por transferencia»*.
 *
 * | Entró por | Se depositó | ¿Orden? |
 * |---|---|---|
 * | Banco | — | sí |
 * | Efectivo o cheque | no | no: se paga por mostrador |
 * | Efectivo o cheque | sí, acreditado | sí |
 * | Efectivo o cheque | sí, en tránsito | todavía no |
 */
final class PaymentOrderEligibility
{
    public function __construct(
        private readonly InstallmentFunding $financiacion,
        private readonly PaymentOrderSources $origen,
    ) {}

    /**
     * @param  PaymentMedium|null  $medium  El medio ya resuelto, cuando
     *                                      quien llama lo trae en lote. Un
     *                                      plan puede tener sesenta cuotas
     *                                      y preguntárselo a cada una sería
     *                                      una consulta por fila.
     */
    public function for(BeneficiaryInstallment $installment, ?PaymentMedium $medium = null): PaymentOrderReadiness
    {
        $installment->loadMissing([
            'haber.beneficiary',
            'haber.expediente.employer',
            'managementLabel',
        ]);

        $canal = $this->origen->channel($installment, $medium ?? $this->financiacion->medium($installment));

        /*
         * El mostrador no es un bloqueo: es la otra mitad del circuito.
         * Todo lo demás —qué falta, qué traba, de dónde vino la plata—
         * deja de tener sentido acá, y calcularlo igual sería recorrer las
         * asignaciones de cada cuota del plan para armar una tabla que
         * nadie va a imprimir.
         */
        if ($canal === PaymentChannel::Counter) {
            return new PaymentOrderReadiness(
                channel: $canal,
                applies: false,
                blockedReason: null,
                missing: [],
                activeOrder: null,
                incomeReceipt: null,
                rows: [],
                organismBankAccountId: null,
            );
        }

        $renglones = $this->origen->rows($installment);
        $cuentaOrganismo = $this->origen->organismAccountId($renglones);
        $recibo = $this->reciboVigente($installment);
        $vigente = $this->ordenVigente($installment);

        return new PaymentOrderReadiness(
            channel: $canal,
            applies: true,
            blockedReason: $this->traba($installment, $canal, $recibo, $vigente),
            missing: $this->faltantes($installment, $cuentaOrganismo),
            activeOrder: $vigente,
            incomeReceipt: $recibo,
            rows: $renglones,
            organismBankAccountId: $cuentaOrganismo,
        );
    }

    /**
     * Lo que impide emitir, en orden de precedencia.
     *
     * El orden importa: a quien todavía no cobró la cuota no le sirve
     * enterarse de que además la etiqueta la bloquea. Se dice lo primero
     * que hay que resolver.
     */
    private function traba(
        BeneficiaryInstallment $installment,
        PaymentChannel $canal,
        ?Receipt $recibo,
        ?PaymentOrder $vigente,
    ): ?string {
        if ($installment->workflow_status === InstallmentWorkflowStatus::Cancelled) {
            return 'La cuota está anulada.';
        }

        if (! $this->financiacion->isFullyFunded($installment)) {
            return sprintf(
                'La cuota todavía no está completa: le faltan $ %s.',
                Decimal::format($this->financiacion->remaining($installment)),
            );
        }

        if ($recibo === null) {
            return 'Falta emitir el recibo de ingreso. La Orden lo referencia por número, '
                .'así que no puede salir antes que él.';
        }

        if ($canal === PaymentChannel::Undetermined) {
            return 'El depósito al banco todavía no está acreditado. '
                .'Hasta que el extracto lo confirme, la cuenta del organismo no tiene ese dinero.';
        }

        /*
         * §2.2.7: una condición administrativa bloquea el egreso, nunca el
         * ingreso. El empleador deposita igual; lo que la Secretaría
         * retiene es la entrega al trabajador.
         */
        if ($installment->managementLabel?->blocks_payment === true) {
            return sprintf(
                'La etiqueta «%s» impide emitir la Orden hasta que se levante.',
                $installment->managementLabel->code,
            );
        }

        if ($vigente === null && $installment->workflow_status === InstallmentWorkflowStatus::Paid) {
            return 'La cuota figura pagada y no tiene Orden vigente.';
        }

        return null;
    }

    /**
     * Los datos del maestro que el papel pide y todavía no existen.
     *
     * @return list<MissingOrderField>
     */
    private function faltantes(BeneficiaryInstallment $installment, ?int $cuentaOrganismo): array
    {
        $beneficiario = $installment->haber->beneficiary;
        $empleador = $installment->haber->expediente->employer;
        $faltan = [];

        if ($this->vacio($beneficiario->document)) {
            $faltan[] = MissingOrderField::beneficiaryDocument();
        }

        if ($this->vacio($beneficiario->address)) {
            $faltan[] = MissingOrderField::beneficiaryAddress();
        }

        if ($this->vacio($beneficiario->phone)) {
            $faltan[] = MissingOrderField::beneficiaryPhone();
        }

        if ($this->cuentaVerificada($beneficiario) === null) {
            $faltan[] = MissingOrderField::verifiedAccount();
        }

        if ($empleador === null) {
            $faltan[] = MissingOrderField::employer();
        } else {
            if ($this->vacio($empleador->document)) {
                $faltan[] = MissingOrderField::employerTaxIdentifier();
            }

            if ($this->vacio($empleador->address)) {
                $faltan[] = MissingOrderField::employerAddress();
            }

            if ($this->vacio($empleador->phone)) {
                $faltan[] = MissingOrderField::employerPhone();
            }
        }

        if ($installment->haber->expediente->received_date === null) {
            $faltan[] = MissingOrderField::custodyStartDate();
        }

        if ($cuentaOrganismo === null) {
            $faltan[] = MissingOrderField::organismAccount();
        }

        return $faltan;
    }

    /**
     * Las cuentas del beneficiario que habilitan una Orden.
     *
     * Verificadas y activas, nada más. Una `rejected` no vuelve a usarse
     * —es lo que pasa con un CVU de billetera— y una `unverified` es
     * justamente lo que alguien tiene que mirar antes de que el organismo
     * transfiera.
     *
     * @return Collection<int, PersonBankAccount>
     */
    public function verifiedAccounts(Person $beneficiary): Collection
    {
        return PersonBankAccount::query()
            ->where('person_id', $beneficiary->id)
            ->where('is_active', true)
            ->where('verification_status', 'verified')
            ->orderBy('id')
            ->get();
    }

    /** La primera cuenta utilizable, que es la que el modal propone. */
    private function cuentaVerificada(Person $beneficiary): ?PersonBankAccount
    {
        return $this->verifiedAccounts($beneficiary)->first();
    }

    private function reciboVigente(BeneficiaryInstallment $installment): ?Receipt
    {
        return Receipt::query()
            ->issued()
            ->where('receipt_type', ReceiptType::Income)
            ->where('beneficiary_installment_id', $installment->id)
            ->first();
    }

    private function ordenVigente(BeneficiaryInstallment $installment): ?PaymentOrder
    {
        return PaymentOrder::query()
            ->active()
            ->where('beneficiary_installment_id', $installment->id)
            ->with(['pase', 'organismBankAccount:id,label,bank_name,account_number'])
            ->first();
    }

    private function vacio(?string $valor): bool
    {
        return $valor === null || trim($valor) === '';
    }
}
