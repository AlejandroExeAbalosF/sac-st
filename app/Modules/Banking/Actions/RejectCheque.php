<?php

declare(strict_types=1);

namespace App\Modules\Banking\Actions;

use App\Modules\Banking\Enums\CashTransferStatus;
use App\Modules\Banking\Enums\ChequeRejectionBankStatus;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Banking\Models\ChequeRejection;
use App\Modules\Banking\Support\WholeReceiptDeposits;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Enums\ChequeStatus;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\FundReceiptOrigin;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Models\FinancialEvent;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Shared\Actions\StoreAttachment;
use App\Modules\Shared\Enums\AttachmentSource;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Support\BusinessDate;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Se rechazó un cheque (Multas M03, DER físico §6.6).
 *
 * El pago, su aplicación y su recibo no se tocan: lo que cambia es que el
 * cheque ya no es plata. Pasa a `REJECTED_CHEQUES` —lo que la empresa tiene
 * que regularizar con otro cheque del mismo importe— desde donde estaba:
 *
 * - **en custodia**: nunca salió de la caja; el banco no tiene nada que ver;
 * - **depositado y sin acreditar en el sistema**: el traslado queda devuelto
 *   y sale de tránsito. Si el banco llegó a acreditarlo, lo dirá el extracto:
 *   hasta entonces el seguimiento bancario queda sin verificar;
 * - **acreditado**: el banco ya lo había acreditado; su débito queda anunciado
 *   (`PENDING_BANK_DEBITS`) hasta que el extracto lo traiga. Si ya lo trae, se
 *   registra en el mismo acto.
 *
 * Un asiento por hecho, fechado el día en que se registra: el día del
 * rechazo queda en el rechazo. El respaldo —el aviso del banco, la
 * constancia— es obligatorio, salvo cuando el débito del extracto ya es la
 * evidencia.
 */
final class RejectCheque
{
    public function __construct(
        private readonly PostJournalEntry $asentar,
        private readonly StoreAttachment $attachments,
        private readonly WholeReceiptDeposits $cobrosEnteros,
        private readonly RecordChequeRejectionDebit $debito,
    ) {}

    /** @throws ValidationException */
    public function handle(
        FundReceipt $cheque,
        CarbonInterface $rejectedOn,
        string $reason,
        ?UploadedFile $respaldo,
        ?BankTransaction $debito,
        string $idempotencyKey,
        ?int $actorId,
    ): ChequeRejection {
        $adjunto = null;

        try {
            return DB::transaction(function () use (
                $cheque, $rejectedOn, $reason, $respaldo, $debito, $idempotencyKey, $actorId, &$adjunto
            ): ChequeRejection {
                // Un segundo envío: el rechazo ya está.
                $yaRechazado = ChequeRejection::query()
                    ->whereIn('rejection_event_id', FinancialEvent::query()->where('idempotency_key', $idempotencyKey)->select('id'))
                    ->first();

                if ($yaRechazado !== null) {
                    return $yaRechazado;
                }

                /*
                 * Traslado y después cheque: el mismo orden que acreditar y
                 * cancelar, que bloquean el traslado y después mueven el
                 * cheque. Al revés, rechazar mientras otro acredita se
                 * trabarían entre sí. Bloqueado el cheque, se vuelve a mirar
                 * el traslado: si otro lo depositó en el medio, se reintenta.
                 */
                $trasladoId = $this->trasladoVigente($cheque->id);
                $traslado = $trasladoId === null ? null : CashToBankTransfer::query()->lockForUpdate()->findOrFail($trasladoId);
                $cheque = FundReceipt::query()->lockForUpdate()->findOrFail($cheque->id);

                if ($this->trasladoVigente($cheque->id) !== $trasladoId) {
                    throw ValidationException::withMessages([
                        'chequeId' => 'Ese cheque se acaba de depositar o de cancelar su depósito. Volvé a registrar el rechazo.',
                    ]);
                }

                $motivo = trim($reason);
                $this->assertRejectable($cheque, $rejectedOn, $motivo);

                [$desde, $estado] = $this->dondeEstaba($cheque, $traslado, $debito);

                if ($respaldo === null && $debito === null) {
                    throw ValidationException::withMessages([
                        'evidence' => 'Adjuntá el respaldo del rechazo: el aviso del banco o la constancia.',
                    ]);
                }

                $moneda = Currency::from($cheque->currency);
                $salida = EntryLine::credit($desde, $cheque->amount)->in($moneda)->onCashBox($cheque->cash_box_id);

                $evento = $this->asentar->handle(
                    type: FinancialEventType::ChequeRejected,
                    idempotencyKey: $idempotencyKey,
                    lines: [
                        EntryLine::debit(LedgerAccount::RejectedCheques, $cheque->amount)->in($moneda)->onCashBox($cheque->cash_box_id),
                        $traslado === null ? $salida : $salida->onBankAccount($traslado->bank_account_id),
                    ],
                    date: BusinessDate::today(),
                    cashBoxId: $cheque->cash_box_id,
                    description: 'Cheque n.º '.$cheque->cheque_number.' rechazado: '.$motivo,
                    actorId: $actorId,
                );

                if ($traslado?->status === CashTransferStatus::Deposited) {
                    $traslado->forceFill(['status' => CashTransferStatus::Bounced])->save();
                }

                $rechazo = ChequeRejection::query()->create([
                    'fund_receipt_id' => $cheque->id,
                    'cash_to_bank_transfer_id' => $traslado?->id,
                    'rejected_on' => $rejectedOn->toDateString(),
                    'reason' => $motivo,
                    'rejection_event_id' => $evento->id,
                    'registered_by' => $actorId,
                    'registered_at' => now(),
                    'bank_status' => $estado,
                ]);

                $cheque->forceFill(['cheque_status' => ChequeStatus::Rejected])->save();

                if ($respaldo !== null) {
                    $adjunto = $this->attachments->handle(
                        file: $respaldo,
                        subject: AttachmentSubject::ChequeRejection,
                        subjectId: $rechazo->id,
                        documentType: 'cheque_rejection_notice',
                        userId: $actorId,
                        title: 'Respaldo del rechazo',
                        source: AttachmentSource::Scanned,
                    );
                }

                // El débito sin aviso previo: rechazo y débito en un mismo acto.
                return $debito === null
                    ? $rechazo
                    : $this->debito->handle($rechazo, $debito, $idempotencyKey.':debito', $actorId);
            });
        } catch (Throwable $excepcion) {
            if ($adjunto !== null) {
                $this->attachments->deleteFile(['disk' => $adjunto->storage_disk, 'key' => $adjunto->object_key]);
            }

            throw $excepcion;
        }
    }

    private function trasladoVigente(int $chequeId): ?int
    {
        $id = CashToBankTransfer::query()
            ->whereHas('items', fn ($q) => $q->where('fund_receipt_id', $chequeId))
            ->where('status', '!=', CashTransferStatus::Cancelled)
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /** @throws ValidationException */
    private function assertRejectable(FundReceipt $cheque, CarbonInterface $rechazadoEl, string $motivo): void
    {
        if ($cheque->medium !== PaymentMedium::Cheque
            || ! in_array($cheque->origin, [FundReceiptOrigin::Received, FundReceiptOrigin::Replacement], true)
            || ! $this->cobrosEnteros->appliesTo($cheque->cash_box_id)) {
            throw ValidationException::withMessages([
                'chequeId' => 'El rechazo se registra para un cheque cobrado por una caja que deposita cobros enteros.',
            ]);
        }

        if ($cheque->cheque_status === ChequeStatus::Rejected) {
            throw ValidationException::withMessages([
                'chequeId' => 'Ese cheque ya figura rechazado. Si el reemplazo también se rechazó, se registra sobre el reemplazo.',
            ]);
        }

        if ($cheque->reversed_at !== null) {
            throw ValidationException::withMessages(['chequeId' => 'Ese cobro está revertido.']);
        }

        if ($rechazadoEl->gt(BusinessDate::today()) || $rechazadoEl->lt($cheque->received_date)) {
            throw ValidationException::withMessages([
                'rejectedOn' => 'El rechazo no puede ser futuro ni anterior al cobro, del '.$cheque->received_date->format('d/m/Y').'.',
            ]);
        }

        if (mb_strlen($motivo) < 5) {
            throw ValidationException::withMessages(['reason' => 'Anotá el motivo del rechazo que informa el banco.']);
        }
    }

    /**
     * De dónde sale el cheque y qué se sabe del banco, según su traslado.
     *
     * @return array{LedgerAccount, ChequeRejectionBankStatus}
     *
     * @throws ValidationException
     */
    private function dondeEstaba(FundReceipt $cheque, ?CashToBankTransfer $traslado, ?BankTransaction $debito): array
    {
        // Sin traslado vigente, el papel tiene que estar en la caja.
        if ($traslado === null && $cheque->cheque_status !== ChequeStatus::InCustody) {
            throw ValidationException::withMessages(['chequeId' => 'Ese cheque no está en la caja ni en un depósito vigente.']);
        }

        if ($traslado !== null && $traslado->status === CashTransferStatus::BankConfirmed) {
            return [LedgerAccount::PendingBankDebits, ChequeRejectionBankStatus::DebitPending];
        }

        // Sin acreditación registrada, un débito no tiene contra qué imputarse todavía.
        if ($debito !== null) {
            throw ValidationException::withMessages([
                'debitTransactionId' => 'El débito se imputa junto con el rechazo solo si el depósito ya figura acreditado. '
                    .'Si el extracto trae el crédito y el débito, registrá el rechazo y después los dos movimientos.',
            ]);
        }

        return $traslado === null
            ? [LedgerAccount::ChequesInCustody, ChequeRejectionBankStatus::NotApplicable]
            : [LedgerAccount::CashInTransit, ChequeRejectionBankStatus::Unverified];
    }
}
