<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Banking\Enums\CashTransferStatus;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\CashToBankTransferItem;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Haberes\Support\InstallmentFunding;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Actions\StoreAttachment;
use App\Modules\Shared\Enums\AttachmentSource;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Enums\ReceiptType;
use App\Modules\Shared\Models\Receipt;
use App\Support\Money\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * El efectivo que nadie retiró se lleva al banco.
 *
 * Es el caso que el área describió: el empleador pagó, se emitió el
 * recibo, y el beneficiario no vino a buscar lo suyo. Ese efectivo no
 * puede quedarse en la caja indefinidamente, así que se deposita en la
 * cuenta del organismo.
 *
 * **Es un traslado interno, no un ingreso nuevo** (§2.1, punto 145). El
 * dinero sigue siendo del mismo beneficiario y sigue imputado a la misma
 * cuota: `BENEFICIARY_FUNDS` no se toca. Lo único que cambia es dónde
 * está.
 *
 * **Y el recibo de ingreso no se toca nunca.** Sigue diciendo «Efectivo»,
 * porque eso fue lo que ocurrió y el empleador tiene su copia firmada
 * (§2.1, punto 147). Este es un hecho nuevo, no una corrección de aquel.
 *
 * **Por qué el asiento va a `CASH_IN_TRANSIT` y no directo al banco.**
 * Entre que el efectivo sale de la caja y el banco lo acredita hay una
 * ventana real. Postear directo a `BANK_ACCOUNT` haría que el libro
 * afirme que el banco tiene una plata que el banco todavía no confirmó, y
 * dejaría un depósito que nunca acreditó indistinguible de uno que
 * todavía no se importó. En tránsito queda como un saldo que envejece,
 * con nombre y fecha.
 */
final class DepositCashToBank
{
    public function __construct(
        private readonly PostJournalEntry $asentar,
        private readonly InstallmentFunding $financiacion,
        private readonly RecordAuditEvent $auditar,
        private readonly StoreAttachment $attachments,
    ) {}

    /**
     * @param  array{
     *     bankAccountId: int,
     *     depositDate: CarbonInterface,
     *     depositTime?: string|null,
     *     operationNumber?: string|null,
     *     terminal?: string|null,
     *     notes?: string|null,
     * }  $ticket
     *
     * @throws ValidationException
     */
    public function handle(
        BeneficiaryInstallment $installment,
        array $ticket,
        UploadedFile $foto,
        string $idempotencyKey,
        ?int $actorId = null,
    ): CashToBankTransfer {
        $adjunto = null;

        try {
            return DB::transaction(function () use (
                $installment, $ticket, $foto, $idempotencyKey, $actorId, &$adjunto
            ): CashToBankTransfer {
                /*
                 * La idempotencia se resuelve **antes** que la validación,
                 * y el orden importa. En un segundo envío del mismo
                 * formulario ese efectivo ya está depositado —por este
                 * mismo acto—, así que validar primero le contestaría «ya
                 * se depositó» a alguien que solo apretó dos veces.
                 */
                $yaTrasladado = CashToBankTransfer::query()
                    ->whereRelation('depositEvent', 'idempotency_key', $idempotencyKey)
                    ->first();

                if ($yaTrasladado !== null) {
                    return $yaTrasladado;
                }

                $asignacion = $this->asignacionEnCaja($installment);
                $recepcion = $asignacion->fundReceipt;
                $importe = Decimal::scale($asignacion->amount);
                $cajaId = $recepcion->cash_box_id;

                /*
                 * El cheque sale de custodia y el efectivo de la caja,
                 * igual que entraron por lados distintos al recibirse. El
                 * cheque sigue el circuito del efectivo (§2.5), y esta es
                 * la línea donde eso se nota.
                 */
                $origen = $recepcion->medium === PaymentMedium::Cheque
                    ? LedgerAccount::ChequesInCustody
                    : LedgerAccount::CashOnHand;

                $evento = $this->asentar->handle(
                    type: FinancialEventType::CashDepositedToBank,
                    idempotencyKey: $idempotencyKey,
                    lines: [
                        EntryLine::debit(LedgerAccount::CashInTransit, $importe)
                            ->onBankAccount($ticket['bankAccountId'])
                            ->onCashBox($cajaId),
                        EntryLine::credit($origen, $importe)->onCashBox($cajaId),
                    ],
                    date: $ticket['depositDate'],
                    cashBoxId: $cajaId,
                    description: $ticket['notes'] ?? null,
                    actorId: $actorId,
                );

                $traslado = CashToBankTransfer::query()->create([
                    'deposit_event_id' => $evento->id,
                    'cash_box_id' => $cajaId,
                    'bank_account_id' => $ticket['bankAccountId'],
                    'amount' => $importe,
                    'deposit_date' => $ticket['depositDate'],
                    'deposit_time' => $ticket['depositTime'] ?? null,
                    'deposit_operation_number' => $ticket['operationNumber'] ?? null,
                    'deposit_terminal' => $ticket['terminal'] ?? null,
                    'deposited_by' => $actorId,
                    'notes' => $ticket['notes'] ?? null,
                    'status' => CashTransferStatus::Deposited,
                ]);

                CashToBankTransferItem::query()->create([
                    'cash_to_bank_transfer_id' => $traslado->id,
                    'fund_receipt_id' => $recepcion->id,
                    'funding_allocation_id' => $asignacion->id,
                    'amount' => $importe,
                ]);

                /*
                 * La foto es obligatoria, a diferencia del comprobante que
                 * trae el expediente: aquel documenta plata que entró y que
                 * el extracto va a confirmar igual; este es la única prueba
                 * de que el efectivo salió de la caja.
                 */
                $adjunto = $this->attachments->handle(
                    file: $foto,
                    subject: AttachmentSubject::CashTransfer,
                    subjectId: $traslado->id,
                    documentType: 'cash_transfer_ticket',
                    userId: $actorId,
                    title: 'Ticket del depósito',
                    source: AttachmentSource::Scanned,
                );

                $this->auditar->handle('traslado.depositado', $traslado, after: [
                    'amount' => $traslado->amount,
                    'bank_account_id' => $traslado->bank_account_id,
                    'deposit_date' => $traslado->deposit_date->toDateString(),
                    'beneficiary_installment_id' => $installment->id,
                ], actorId: $actorId);

                return $traslado;
            });
        } catch (Throwable $excepcion) {
            if ($adjunto !== null) {
                $this->attachments->deleteFile([
                    'disk' => $adjunto->storage_disk,
                    'key' => $adjunto->object_key,
                ]);
            }

            throw $excepcion;
        }
    }

    /**
     * El efectivo de esta cuota que todavía está en la caja.
     *
     * @throws ValidationException
     */
    private function asignacionEnCaja(BeneficiaryInstallment $installment): FundingAllocation
    {
        if (! $this->financiacion->isFullyFunded($installment)) {
            throw ValidationException::withMessages([
                'installmentId' => 'La cuota no está completa: no hay efectivo suyo en la caja.',
            ]);
        }

        $medio = $this->financiacion->medium($installment);

        if ($medio === null || $medio === PaymentMedium::Bank) {
            throw ValidationException::withMessages([
                'installmentId' => 'Esta cuota entró por transferencia: su dinero ya está en el banco.',
            ]);
        }

        /*
         * Sin recibo no se traslada. No es formalismo: el recibo es lo que
         * documenta de quién se recibió ese efectivo, y depositar primero
         * dejaría un movimiento bancario respaldado por nada.
         */
        $tieneRecibo = Receipt::query()
            ->issued()
            ->where('receipt_type', ReceiptType::Income)
            ->where('beneficiary_installment_id', $installment->id)
            ->exists();

        if (! $tieneRecibo) {
            throw ValidationException::withMessages([
                'installmentId' => 'Primero hay que emitir el recibo de ingreso de esta cuota.',
            ]);
        }

        $asignacion = FundingAllocation::query()
            ->live()
            ->with('fundReceipt')
            ->where('beneficiary_installment_id', $installment->id)
            ->first();

        if ($asignacion === null) {
            throw ValidationException::withMessages([
                'installmentId' => 'La cuota no tiene ninguna asignación vigente.',
            ]);
        }

        $yaDepositada = CashToBankTransferItem::query()
            ->where('funding_allocation_id', $asignacion->id)
            ->whereRelation('transfer', 'status', '!=', CashTransferStatus::Cancelled)
            ->exists();

        if ($yaDepositada) {
            throw ValidationException::withMessages([
                'installmentId' => 'Ese efectivo ya se depositó en el banco.',
            ]);
        }

        return $asignacion;
    }
}
