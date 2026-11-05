<?php

declare(strict_types=1);

namespace App\Modules\Banking\Actions;

use App\Modules\Banking\Enums\CashTransferStatus;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Banking\Support\CashBoxBankAccounts;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Actions\StoreAttachment;
use App\Modules\Shared\Enums\AttachmentSource;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Support\Money\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * El segundo paso del depósito en dos pasos: el comprobante del banco.
 *
 * No mueve dinero ni lleva asiento: es la evidencia de un traspaso ya
 * registrado, y por eso no reabre el día en que el efectivo salió. El importe
 * tiene que ser el del traspaso: si difiere, algo se cargó mal y se cancela,
 * para que la diferencia no quede disimulada con otro reparto.
 */
final class RecordTransferTicket
{
    public function __construct(
        private readonly RecordAuditEvent $auditar,
        private readonly StoreAttachment $attachments,
        private readonly CashBoxBankAccounts $cuentas,
    ) {}

    /**
     * @param  array{depositDate: CarbonInterface, depositTime?: string|null, operationNumber?: string|null, terminal?: string|null, notes?: string|null}  $ticket
     *
     * @throws ValidationException
     */
    public function handle(
        CashToBankTransfer $transfer,
        string $importe,
        array $ticket,
        UploadedFile $foto,
        string $idempotencyKey,
        ?int $actorId,
    ): CashToBankTransfer {
        $adjunto = null;

        try {
            return DB::transaction(function () use ($transfer, $importe, $ticket, $foto, $idempotencyKey, $actorId, &$adjunto): CashToBankTransfer {
                $traslado = CashToBankTransfer::query()->lockForUpdate()->findOrFail($transfer->id);

                // Un segundo envío del mismo formulario.
                if ($traslado->ticket_idempotency_key === $idempotencyKey) {
                    return $traslado;
                }

                if ($traslado->status !== CashTransferStatus::AwaitingTicket) {
                    throw ValidationException::withMessages([
                        'transferId' => $traslado->status === CashTransferStatus::Cancelled
                            ? 'Ese traspaso se canceló.'
                            : 'Ese traspaso ya tiene su comprobante.',
                    ]);
                }

                if (! Decimal::equals($importe, $traslado->amount)) {
                    throw ValidationException::withMessages([
                        'amount' => 'El traspaso es por $ '.Decimal::format($traslado->amount).'. Si el comprobante dice otro importe, cancelá el traspaso y registrá uno nuevo.',
                    ]);
                }

                // Puede ser anterior al traspaso, nunca al efectivo que lleva.
                $ultimoCobro = $traslado->items()->with('fundReceipt')->get()->max('fundReceipt.received_date');

                if ($ultimoCobro !== null && $ticket['depositDate']->lt($ultimoCobro)) {
                    throw ValidationException::withMessages([
                        'depositDate' => 'El depósito no puede ser anterior al efectivo que lleva: el último cobro es del '.$ultimoCobro->format('d/m/Y').'.',
                    ]);
                }

                if (! $this->cuentas->covers((int) $traslado->cash_box_id, (int) $traslado->bank_account_id, $ticket['depositDate'])) {
                    throw ValidationException::withMessages([
                        'depositDate' => 'La cuenta del traspaso no está habilitada para la caja en esa fecha.',
                    ]);
                }

                $traslado->forceFill([
                    'status' => CashTransferStatus::Deposited,
                    'ticket_date' => $ticket['depositDate'],
                    'ticket_loaded_at' => now(),
                    'ticket_loaded_by' => $actorId,
                    'ticket_idempotency_key' => $idempotencyKey,
                    'deposit_time' => $ticket['depositTime'] ?? null,
                    'deposit_operation_number' => $ticket['operationNumber'] ?? null,
                    'deposit_terminal' => $ticket['terminal'] ?? null,
                    'notes' => $ticket['notes'] ?? $traslado->notes,
                ])->save();

                $adjunto = $this->attachments->handle(
                    file: $foto,
                    subject: AttachmentSubject::CashTransfer,
                    subjectId: $traslado->id,
                    documentType: 'cash_transfer_ticket',
                    userId: $actorId,
                    title: 'Comprobante del depósito',
                    source: AttachmentSource::Scanned,
                );

                $this->auditar->handle('traslado.comprobante-cargado', $traslado, after: [
                    'amount' => $traslado->amount,
                    'ticket_date' => $ticket['depositDate']->toDateString(),
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
}
