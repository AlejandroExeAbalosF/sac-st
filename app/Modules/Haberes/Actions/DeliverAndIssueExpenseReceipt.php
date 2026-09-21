<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Shared\Enums\ReceiptType;
use App\Modules\Shared\Models\Receipt;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pagar por mostrador y hacer firmar el recibo: un solo acto.
 *
 * **Es lo que pasa en el mostrador**, y es el espejo exacto de
 * `CollectAndIssueReceipt` en el otro extremo del circuito. El trabajador
 * está enfrente: se le entrega el dinero y firma el papel en el mismo
 * movimiento. Pedir dos confirmaciones separadas —registrar el egreso,
 * después emitir— partiría en dos algo que en la realidad no está
 * partido.
 *
 * **La diferencia con el ingreso está en el orden, y es una regla, no una
 * comodidad.** Allá el recibo puede emitirse sin esperar nada (§2.5.4);
 * acá el comprobante exige que el egreso ya esté confirmado (invariante
 * 13), así que el pago va primero por obligación. Que el operador lo vea
 * como un solo botón no cambia que la base los ordena.
 *
 * Con el egreso ya registrado —el recibo se anuló y hay que rehacerlo—
 * esto se limita a emitir.
 */
final class DeliverAndIssueExpenseReceipt
{
    public function __construct(
        private readonly DeliverToBeneficiary $entregar,
        private readonly IssueExpenseReceipt $emitir,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(
        BeneficiaryInstallment $installment,
        string $idempotencyKey,
        ?int $actorId = null,
        ?string $talonarioNumber = null,
        bool $printsTalonarioNumber = false,
        ?CarbonInterface $paymentDate = null,
        ?string $notes = null,
    ): Receipt {
        return DB::transaction(function () use (
            $installment, $idempotencyKey, $actorId, $talonarioNumber,
            $printsTalonarioNumber, $paymentDate, $notes
        ): Receipt {
            /*
             * Un segundo envío del mismo formulario.
             *
             * La entrega ya es idempotente por su clave, pero la emisión
             * no puede serlo: rechaza el segundo recibo de una cuota, y
             * con razón. Sin esto el doble clic devolvía «la cuota ya
             * tiene recibo» a alguien que solo apretó dos veces.
             */
            $vigente = $this->reciboVigente($installment);

            if ($vigente !== null) {
                return $vigente;
            }

            $egreso = $this->entregar->handle(
                installment: $installment,
                idempotencyKey: $idempotencyKey.':egreso',
                actorId: $actorId,
                paymentDate: $paymentDate,
                notes: $notes,
            );

            /*
             * La cuota acaba de cambiar de estado y la instancia en
             * memoria todavía dice lo de antes.
             */
            $installment->refresh();

            return $this->emitir->handle(
                installment: $installment,
                disbursement: $egreso,
                actorId: $actorId,
                talonarioNumber: $talonarioNumber,
                printsTalonarioNumber: $printsTalonarioNumber,
            );
        });
    }

    private function reciboVigente(BeneficiaryInstallment $installment): ?Receipt
    {
        return Receipt::query()
            ->issued()
            ->where('receipt_type', ReceiptType::Expense)
            ->where('beneficiary_installment_id', $installment->id)
            ->first();
    }
}
