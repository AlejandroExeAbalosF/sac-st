<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Support\InstallmentFunding;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Models\FinancialEvent;
use App\Modules\Shared\Enums\ReceiptType;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Models\Receipt;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cobrar por mostrador y entregar el recibo: un solo acto.
 *
 * **Es lo que pasa en el mostrador.** El efectivo llega con el expediente,
 * así que no hay nada que esperar: el recibo *es* la constancia de que
 * ingresó. Pedir dos confirmaciones separadas —registrar el pago, después
 * emitir— partía en dos algo que en la realidad no está partido.
 *
 * **Y por eso el cobro va acá y no en el alta de la cuota.** Cargar una
 * cuota es reconocer un derecho del expediente, que el §2.1.1 distingue
 * del dinero recibido; si crear una cuota asentara plata, cargarla mal
 * —o cargar el plan completo por adelantado— pondría en los libros dinero
 * que no entró. El asiento necesita un acto propio, pero uno solo.
 *
 * Con una cuota que ya está financiada —el circuito bancario— no hay nada
 * que cobrar y esto se limita a emitir.
 */
final class CollectAndIssueReceipt
{
    public function __construct(
        private readonly RegisterCashPayment $cobrar,
        private readonly IssueIncomeReceipt $emitir,
        private readonly InstallmentFunding $financiacion,
    ) {}

    /**
     * @param  array{number: string, bank: string|null, issueDate: string|null}|null  $cheque
     *
     * @throws ValidationException
     */
    public function handle(
        BeneficiaryInstallment $installment,
        string $idempotencyKey,
        ?int $actorId = null,
        ?string $talonarioNumber = null,
        ?int $signedById = null,
        bool $printsTalonarioNumber = false,
        // Lo unico del cobro que no está ya escrito en la cuota.
        ?CarbonInterface $receivedDate = null,
        ?array $cheque = null,
        ?string $notes = null,
    ): Receipt {
        $claveDelCobro = $idempotencyKey.':cobro';

        return DB::transaction(function () use (
            $installment, $claveDelCobro, $actorId, $talonarioNumber,
            $signedById, $printsTalonarioNumber, $receivedDate, $cheque, $notes
        ): Receipt {
            /*
             * Un segundo envío del mismo formulario.
             *
             * El cobro ya es idempotente por su clave, pero la emisión no
             * puede serlo: rechaza el segundo recibo de una cuota, y con
             * razón. Sin esto el doble clic devolvía «la cuota ya tiene
             * recibo» a alguien que solo apretó dos veces.
             */
            if ($this->yaOcurrio($claveDelCobro)) {
                $vigente = $this->reciboVigente($installment);

                if ($vigente !== null) {
                    return $vigente;
                }
            }

            if ($this->cobraAlEmitir($installment)) {
                $this->cobrar->handle(
                    installment: $installment,
                    amount: $this->financiacion->remaining($installment),
                    idempotencyKey: $claveDelCobro,
                    cashBoxId: $this->cajaDeHaberes(),
                    /*
                     * El dinero llegó con el expediente, así que la fecha
                     * del alta de la cuota es la que más se le parece. Se
                     * puede corregir: cargar el expediente puede demorar.
                     */
                    receivedDate: $receivedDate ?? $installment->created_at,
                    medium: $this->medioDelMostrador($installment) ?? PaymentMedium::Cash,
                    actorId: $actorId,
                    notes: $notes,
                    cheque: $cheque,
                );

                /*
                 * La cuota acaba de cambiar: el Action que emite consulta
                 * su financiación y la instancia en memoria todavía dice
                 * lo de antes.
                 */
                $installment->refresh();
            }

            return $this->emitir->handle(
                installment: $installment,
                actorId: $actorId,
                talonarioNumber: $talonarioNumber,
                signedById: $signedById,
                printsTalonarioNumber: $printsTalonarioNumber,
            );
        });
    }

    /**
     * Si este acto además cobra.
     *
     * Lo decide la cuota, no el formulario: cuánto y por qué medio ya
     * están escritos en ella desde que se cargó. Que el navegador pudiera
     * proponer un importe distinto era una forma de asentar plata que no
     * entró, y no había razón para permitirlo —el pago por partes no
     * está en uso y el redondeo del efectivo (§2.4) tiene su propio
     * circuito—.
     *
     * El controlador la usa para exigir el permiso de recepciones, que
     * solo corresponde cuando además se mueve dinero.
     */
    public function cobraAlEmitir(BeneficiaryInstallment $installment): bool
    {
        return ! $this->financiacion->isFullyFunded($installment)
            && $this->medioDelMostrador($installment) !== null;
    }

    /**
     * El medio, cuando la cuota entra por mostrador.
     *
     * `null` para el circuito bancario: ahí el dinero llega por el
     * extracto y este acto solo emite.
     */
    private function medioDelMostrador(BeneficiaryInstallment $installment): ?PaymentMedium
    {
        /*
         * Pregunta por el medio **efectivo**, no por la columna. Una
         * cuota financiada por transferencia a la que alguien le editó el
         * medio previsto a «efectivo» ofrecía cobrar por mostrador, y el
         * cobro moría contra el invariante 4 después de apretar el botón.
         * El libro nunca corrió riesgo; lo que había era una puerta
         * cerrada con cartel de abierta.
         */
        return match ($this->financiacion->effectiveMedium($installment)) {
            PaymentMedium::Cash => PaymentMedium::Cash,
            PaymentMedium::Cheque => PaymentMedium::Cheque,
            default => null,
        };
    }

    /**
     * @throws ValidationException
     */
    private function cajaDeHaberes(): int
    {
        $caja = CashBox::query()->where('code', 'haberes')->first();

        if ($caja === null) {
            throw ValidationException::withMessages([
                'installmentId' => 'No existe la caja de Haberes: hay que sembrarla antes de cobrar.',
            ]);
        }

        return $caja->id;
    }

    private function yaOcurrio(string $idempotencyKey): bool
    {
        return FinancialEvent::query()->where('idempotency_key', $idempotencyKey)->exists();
    }

    private function reciboVigente(BeneficiaryInstallment $installment): ?Receipt
    {
        return Receipt::query()
            ->issued()
            ->where('receipt_type', ReceiptType::Income)
            ->where('beneficiary_installment_id', $installment->id)
            ->first();
    }
}
