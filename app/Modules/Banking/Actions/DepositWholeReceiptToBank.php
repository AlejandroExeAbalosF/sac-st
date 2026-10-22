<?php

declare(strict_types=1);

namespace App\Modules\Banking\Actions;

use App\Modules\Banking\Enums\CashTransferStatus;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Banking\Models\CashToBankTransferItem;
use App\Modules\Banking\Support\TransferredCheques;
use App\Modules\Banking\Support\WholeReceiptDeposits;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Enums\ChequeStatus;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\FundReceiptOrigin;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Actions\StoreAttachment;
use App\Modules\Shared\Enums\AttachmentSource;
use App\Modules\Shared\Enums\AttachmentSubject;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Lleva al banco un cobro de la caja, entero y solo (Multas M06).
 *
 * Es la política de las cajas que depositan cada cobro como llegó
 * (`cash_boxes.deposits_whole_receipts`): un traslado, un cobro, por su
 * importe completo —el excedente incluido—, a la cuenta con la que opera el
 * libro de la caja. Haberes no la tiene y deposita por cuota
 * (`DepositCashToBank`).
 *
 * Como en Haberes, es un traslado interno y no un ingreso: el asiento lleva
 * la plata de la caja —o el cheque de custodia— a `CASH_IN_TRANSIT`, hasta
 * que el extracto la acredite. El recibo del cobro no se toca. Y la foto del
 * ticket es obligatoria: es la única prueba de que la plata salió de la caja.
 *
 * Banking no sabe de quién era el cobro: lo sabe el módulo de la caja, que
 * llama a este Action y audita sobre lo suyo.
 */
final class DepositWholeReceiptToBank
{
    public function __construct(
        private readonly PostJournalEntry $asentar,
        private readonly RecordAuditEvent $auditar,
        private readonly StoreAttachment $attachments,
        private readonly TransferredCheques $cheques,
        private readonly WholeReceiptDeposits $cobrosEnteros,
    ) {}

    /**
     * @param  array{
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
        FundReceipt $receipt,
        array $ticket,
        UploadedFile $foto,
        string $idempotencyKey,
        ?int $actorId = null,
    ): CashToBankTransfer {
        $adjunto = null;

        try {
            return DB::transaction(function () use ($receipt, $ticket, $foto, $idempotencyKey, $actorId, &$adjunto): CashToBankTransfer {
                // Un segundo envío del mismo formulario: el cobro ya se depositó por este acto.
                $yaTrasladado = CashToBankTransfer::query()
                    ->whereRelation('depositEvent', 'idempotency_key', $idempotencyKey)
                    ->first();

                if ($yaTrasladado !== null) {
                    return $yaTrasladado;
                }

                // Bloqueado: dos depósitos del mismo cobro se serializan, y el segundo lo encuentra trasladado.
                $recepcion = FundReceipt::query()->lockForUpdate()->findOrFail($receipt->id);
                $moneda = Currency::from($recepcion->currency);
                $cuenta = $this->assertDepositable($recepcion, $ticket['depositDate']);

                $evento = $this->asentar->handle(
                    type: FinancialEventType::CashDepositedToBank,
                    idempotencyKey: $idempotencyKey,
                    lines: [
                        EntryLine::debit(LedgerAccount::CashInTransit, $recepcion->amount)
                            ->in($moneda)
                            ->onBankAccount($cuenta)
                            ->onCashBox($recepcion->cash_box_id),
                        EntryLine::credit($this->deDonde($recepcion), $recepcion->amount)
                            ->in($moneda)
                            ->onCashBox($recepcion->cash_box_id),
                    ],
                    date: $ticket['depositDate'],
                    cashBoxId: $recepcion->cash_box_id,
                    description: $ticket['notes'] ?? null,
                    actorId: $actorId,
                );

                $traslado = CashToBankTransfer::query()->create([
                    'deposit_event_id' => $evento->id,
                    'cash_box_id' => $recepcion->cash_box_id,
                    'bank_account_id' => $cuenta,
                    'amount' => $recepcion->amount,
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
                    'amount' => $recepcion->amount,
                ]);

                // El cheque sale de custodia: el papel está en el banco.
                $this->cheques->deposited($traslado);

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
                    'fund_receipt_id' => $recepcion->id,
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
     * Lo que tiene que cumplir el cobro para salir de la caja, y la cuenta a
     * la que va.
     *
     * @throws ValidationException
     */
    private function assertDepositable(FundReceipt $recepcion, CarbonInterface $fecha): int
    {
        if (! $this->cobrosEnteros->appliesTo($recepcion->cash_box_id)) {
            throw ValidationException::withMessages([
                'receiptId' => 'Esta caja no deposita cobros enteros: su traslado es otro.',
            ]);
        }

        // Lo recibido por el mostrador, o el cheque que reemplaza a uno rechazado (M03).
        if (! in_array($recepcion->origin, [FundReceiptOrigin::Received, FundReceiptOrigin::Replacement], true)
            || $recepcion->reversed_at !== null) {
            throw ValidationException::withMessages([
                'receiptId' => 'Solo se deposita un cobro recibido por la caja y vigente.',
            ]);
        }

        if ($recepcion->medium === PaymentMedium::Bank) {
            throw ValidationException::withMessages([
                'receiptId' => 'Ese cobro entró por el banco: ya está en la cuenta.',
            ]);
        }

        if ($recepcion->medium === PaymentMedium::Cheque && $recepcion->cheque_status !== ChequeStatus::InCustody) {
            throw ValidationException::withMessages([
                'receiptId' => sprintf(
                    'El cheque n.º %s ya no está en la caja: figura %s.',
                    $recepcion->cheque_number ?? '(sin número)',
                    mb_strtolower($recepcion->cheque_status?->label() ?? 'sin estado'),
                ),
            ]);
        }

        // El banco no recibe lo que la caja todavía no cobró.
        if ($fecha->lt($recepcion->received_date)) {
            throw ValidationException::withMessages([
                'depositDate' => 'El depósito no puede ser anterior al cobro, del '.$recepcion->received_date->format('d/m/Y').'.',
            ]);
        }

        $vigente = CashToBankTransferItem::query()
            ->where('fund_receipt_id', $recepcion->id)
            ->whereRelation('transfer', 'status', '!=', CashTransferStatus::Cancelled)
            ->exists();

        if ($vigente) {
            throw ValidationException::withMessages([
                'receiptId' => 'Ese cobro ya se depositó: tiene un traslado vigente.',
            ]);
        }

        $cuenta = $this->cobrosEnteros->accountFor((int) $recepcion->cash_box_id, Currency::from($recepcion->currency));

        if ($cuenta === null || BankAccount::query()->whereKey($cuenta)->value('currency') !== $recepcion->currency) {
            throw ValidationException::withMessages([
                'receiptId' => 'El libro de la caja no tiene una cuenta bancaria en la moneda del cobro.',
            ]);
        }

        return $cuenta;
    }

    /** El cheque sale de custodia y el efectivo de la caja, como entraron. */
    private function deDonde(FundReceipt $recepcion): LedgerAccount
    {
        return $recepcion->medium === PaymentMedium::Cheque
            ? LedgerAccount::ChequesInCustody
            : LedgerAccount::CashOnHand;
    }
}
