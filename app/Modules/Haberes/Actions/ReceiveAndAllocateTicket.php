<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Banking\Actions\RegisterBankFundReceipt;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\BankTransactionAllocation;
use App\Modules\Banking\Support\AllocatableAmount;
use App\Modules\Haberes\Enums\DepositTicketStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\DepositTicket;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Haberes\Support\InstallmentFunding;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Shared\Models\CashBox;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Del ticket cruzado a la cuota financiada, en un solo acto.
 *
 * **No fusiona hechos: fusiona clics.** Registrar la recepción y asignarla
 * siguen siendo dos asientos distintos, escritos por los mismos Actions de
 * siempre; lo que desaparece es tener que recorrer dos pantallas tipeando
 * importes que el sistema ya conoce.
 *
 * ── Por qué no se hace al vincular el ticket ───────────────────────────
 *
 * Porque **vincular no mueve plata**. Decir «este papel y este crédito son
 * el mismo hecho» es una lectura, y por eso desvincular hoy es gratis. Si
 * el vínculo asentara en los libros, corregir un cruce equivocado exigiría
 * una reversión contable: quedaría rastro de un ingreso que nunca existió.
 *
 * ── Cuándo alcanza, y cuándo no ────────────────────────────────────────
 *
 * Alcanza en el caso normal: un ticket cruzado con un crédito que tiene
 * saldo libre. Los importes se derivan —nunca se tipean— y se toma el
 * menor entre lo que la cuota necesita y lo que el crédito tiene.
 *
 * No alcanza cuando hay que repartir un mismo crédito entre cuotas de
 * expedientes distintos, o cuando el excedente bancario (§2.4) pide una
 * decisión. Para eso siguen estando las dos pantallas, que son el camino
 * largo y completo.
 */
final class ReceiveAndAllocateTicket
{
    public function __construct(
        private readonly RegisterBankFundReceipt $registrar,
        private readonly AllocateFundsToInstallment $asignar,
        private readonly AllocatableAmount $disponible,
        private readonly InstallmentFunding $financiacion,
    ) {}

    /**
     * @param  string  $idempotencyKey  Viaja con el formulario desde que se
     *                                  abre: el segundo clic tiene que ser
     *                                  inocuo, no un segundo ingreso.
     *
     * @throws ValidationException
     */
    public function handle(
        BeneficiaryInstallment $installment,
        string $idempotencyKey,
        ?int $actorId = null,
    ): FundReceipt {
        $yaAsignada = $this->recepcionYaAsignada($idempotencyKey);

        if ($yaAsignada !== null) {
            return $yaAsignada;
        }

        $ticket = $this->ticketCruzado($installment);
        $movimiento = BankTransaction::query()->findOrFail($ticket->bank_transaction_id);

        return DB::transaction(function () use (
            $installment, $ticket, $movimiento, $idempotencyKey, $actorId
        ): FundReceipt {
            $recepcion = $this->recepcionDe($movimiento)
                ?? $this->registrarLa($movimiento, $ticket, $idempotencyKey, $actorId);

            // Si otro envío con la misma clave terminó mientras éste
            // esperaba el lock del movimiento, devuelve ese mismo hecho.
            $yaAsignada = $this->recepcionYaAsignada($idempotencyKey);

            if ($yaAsignada !== null) {
                return $yaAsignada;
            }

            $importe = $this->cuantoAsignar($recepcion, $installment);

            if (Decimal::equals($importe, '0')) {
                throw ValidationException::withMessages([
                    'installmentId' => 'No queda nada por asignar: o la recepción ya está repartida, '
                        .'o la cuota ya está completa.',
                ]);
            }

            $this->asignar->handle(
                receipt: $recepcion,
                installment: $installment,
                amount: $importe,
                idempotencyKey: $idempotencyKey.':asignacion',
                actorId: $actorId,
            );

            return $recepcion->refresh();
        });
    }

    /**
     * Lo que hay que asignar: lo que la cuota necesita, si el crédito llega.
     *
     * El menor de los dos, y por eso se calcula en vez de pedirse. Asignar
     * más de lo que la cuota espera es sobre-asignarla; asignar más de lo
     * que la recepción tiene libre lo rechaza el trigger de la base.
     *
     * @return numeric-string
     */
    private function cuantoAsignar(FundReceipt $recepcion, BeneficiaryInstallment $installment): string
    {
        $falta = $this->financiacion->remaining($installment);
        $libre = $this->financiacion->unallocatedForUpdate($recepcion);

        return Decimal::isNegative(Decimal::sub($libre, $falta)) ? $libre : $falta;
    }

    /**
     * La recepción ya nacida de ese crédito, si alguien la registró antes.
     *
     * Es el caso de quien hizo el paso largo a medias: registró y no
     * asignó. El botón tiene que terminar el trabajo, no empezarlo de
     * nuevo ni asentar el dinero dos veces.
     */
    private function recepcionDe(BankTransaction $movimiento): ?FundReceipt
    {
        $eventos = BankTransactionAllocation::query()
            ->where('bank_transaction_id', $movimiento->id)
            ->whereNull('reversal_of_id')
            ->orderBy('id')
            ->pluck('financial_event_id');

        if ($eventos->isEmpty()) {
            return null;
        }

        $recepciones = FundReceipt::query()
            ->whereIn('financial_event_id', $eventos)
            ->orderBy('id')
            ->get();

        if ($recepciones->count() > 1) {
            throw ValidationException::withMessages([
                'installmentId' => 'Ese crédito ya fue dividido en varias recepciones. '
                    .'Hay que repartirlo desde la pantalla de recepciones para elegir de cuál tomar.',
            ]);
        }

        return $recepciones->first();
    }

    /** La recepción que ya completó este mismo envío, si existe. */
    private function recepcionYaAsignada(string $idempotencyKey): ?FundReceipt
    {
        return FundingAllocation::query()
            ->whereRelation('allocationEvent', 'idempotency_key', $idempotencyKey.':asignacion')
            ->with('fundReceipt')
            ->first()
            ?->fundReceipt;
    }

    /**
     * @throws ValidationException
     */
    private function registrarLa(
        BankTransaction $movimiento,
        DepositTicket $ticket,
        string $idempotencyKey,
        ?int $actorId,
    ): FundReceipt {
        /*
         * Lo que el crédito todavía tiene sin imputar, acotado por lo que
         * el ticket dice. Un crédito puede traer más de lo que este
         * comprobante documenta —dos depósitos que el banco juntó— y en ese
         * caso lo que sobra queda libre para quien corresponda.
         */
        $libre = $this->disponible->forUpdate($movimiento);
        $delTicket = Decimal::scale($ticket->amount);
        $importe = Decimal::isNegative(Decimal::sub($libre, $delTicket)) ? $libre : $delTicket;

        if (Decimal::equals($importe, '0')) {
            throw ValidationException::withMessages([
                'installmentId' => 'Ese crédito ya está imputado por completo. '
                    .'Si el dinero de esta cuota vino en otro movimiento, hay que cruzarlo con ése.',
            ]);
        }

        return $this->registrar->handle(
            transaction: $movimiento,
            amount: $importe,
            idempotencyKey: $idempotencyKey.':recepcion',
            cashBoxId: $this->cajaDeHaberes(),
            actorId: $actorId,
        );
    }

    /**
     * @throws ValidationException
     */
    private function ticketCruzado(BeneficiaryInstallment $installment): DepositTicket
    {
        $ticket = DepositTicket::query()
            ->where('beneficiary_installment_id', $installment->id)
            ->where('status', '!=', DepositTicketStatus::Discarded)
            ->whereNotNull('bank_transaction_id')
            ->latest('id')
            ->first();

        if ($ticket === null) {
            throw ValidationException::withMessages([
                'installmentId' => 'La cuota no tiene un comprobante cruzado con un crédito del extracto. '
                    .'Hasta que el cruce no esté hecho no hay de dónde tomar el dinero.',
            ]);
        }

        return $ticket;
    }

    private function cajaDeHaberes(): int
    {
        $id = CashBox::query()->where('code', CashBox::HABERES)->value('id');

        if ($id === null) {
            throw ValidationException::withMessages([
                'installmentId' => 'No existe la caja de Haberes: el ingreso no tiene dónde asentarse.',
            ]);
        }

        return (int) $id;
    }
}
