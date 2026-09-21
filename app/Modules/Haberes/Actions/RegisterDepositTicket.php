<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Haberes\Enums\DepositTicketStatus;
use App\Modules\Haberes\Enums\ExpectedMedium;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\DepositTicket;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Actions\StoreAttachment;
use App\Modules\Shared\Enums\AttachmentSource;
use App\Modules\Shared\Enums\AttachmentSubject;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Registra el comprobante que llegó con el expediente.
 *
 * Queda **esperando**: no financia nada ni emite comprobante. La recepción
 * nace cuando el crédito aparece en el extracto (§2.1.13), y esto es lo
 * que hay antes.
 *
 * **La foto es opcional pero se pide.** El área definió que no sea
 * obligatoria —a veces el papel se traspapela y el dato hay que cargarlo
 * igual—, así que se advierte que falta en lugar de trabar la carga. Es el
 * mismo criterio de la continuidad de saldos: avisar sin impedir.
 */
final class RegisterDepositTicket
{
    public function __construct(
        private readonly RecordAuditEvent $auditar,
        private readonly StoreAttachment $attachments,
    ) {}

    /**
     * @param  array<string, mixed>  $datos
     */
    public function handle(array $datos, ?UploadedFile $foto, ?int $userId): DepositTicket
    {
        $adjunto = null;

        try {
            return DB::transaction(function () use ($datos, $foto, $userId, &$adjunto): DepositTicket {
                $ticket = DepositTicket::query()->create([
                    'expediente_id' => $datos['expedienteId'],
                    'haber_id' => $datos['haberId'] ?? null,
                    'beneficiary_installment_id' => $datos['installmentId'] ?? null,
                    'bank_account_id' => $datos['bankAccountId'],
                    'deposited_at' => $datos['depositedAt'],
                    'deposited_time' => $datos['depositedTime'] ?? null,
                    'amount' => $datos['amount'],
                    'operation_number' => $datos['operationNumber'] ?? null,
                    'terminal' => $datos['terminal'] ?? null,
                    'deposit_kind' => $datos['depositKind'],
                    'notes' => $datos['notes'] ?? null,
                    'status' => DepositTicketStatus::Waiting,
                    'created_by' => $userId,
                ]);

                if ($foto !== null) {
                    $adjunto = $this->attachments->handle(
                        file: $foto,
                        subject: AttachmentSubject::DepositTicket,
                        subjectId: $ticket->id,
                        documentType: 'deposit_ticket',
                        userId: $userId,
                        title: 'Comprobante del depósito',
                        // Viene de la cámara del teléfono casi siempre.
                        source: AttachmentSource::Scanned,
                    );
                }

                $this->auditar->handle('ticket.registrado', $ticket, after: [
                    'expediente_id' => $ticket->expediente_id,
                    'amount' => $ticket->amount,
                    'deposited_at' => $ticket->deposited_at->toDateString(),
                    'operation_number' => $ticket->operation_number,
                ], actorId: $userId);

                $this->alinearMedioPrevisto($ticket, $userId);

                return $ticket;
            });
        } catch (Throwable $exception) {
            if ($adjunto !== null) {
                $this->attachments->deleteFile([
                    'disk' => $adjunto->storage_disk,
                    'key' => $adjunto->object_key,
                ]);
            }

            throw $exception;
        }
    }

    /**
     * El comprobante bancario corrige la expectativa de la cuota.
     *
     * Una cuota que decía «efectivo» y trajo un ticket de transferencia no
     * se cobra por mostrador: ese dinero viene por el banco, y la pantalla
     * tiene que dejar de ofrecer el mostrador antes de que alguien lo
     * intente. El medio previsto es justamente eso —una expectativa— y el
     * ticket es la primera noticia concreta de por dónde llega.
     *
     * **Queda escrito de dónde venía.** El cambio se audita con su motivo,
     * así que el historial de la cuota lo muestra como «Medio previsto:
     * Efectivo → Transferencia» y no parece que alguien lo editó a mano.
     *
     * **No se revierte solo al descartar el ticket.** Descartarlo dice que
     * ese comprobante no valía, no que el dinero vuelva a esperarse por
     * mostrador —puede que la transferencia haya entrado igual con otro
     * comprobante—. Adivinarlo sería peor que dejar que el operador lo
     * corrija, que es una edición libre mientras no haya pase de pago.
     */
    private function alinearMedioPrevisto(DepositTicket $ticket, ?int $userId): void
    {
        if ($ticket->beneficiary_installment_id === null) {
            return;
        }

        $cuota = BeneficiaryInstallment::query()->find($ticket->beneficiary_installment_id);

        if ($cuota === null || $cuota->expected_medium === ExpectedMedium::Bank) {
            return;
        }

        $anterior = $cuota->expected_medium;

        $cuota->forceFill(['expected_medium' => ExpectedMedium::Bank])->save();

        $this->auditar->handle(
            'cuota.medio-alineado',
            $cuota,
            before: ['expected_medium' => $anterior->value],
            after: ['expected_medium' => ExpectedMedium::Bank->value],
            metadata: [
                'usuario' => $userId,
                'motivo' => 'Se cargó el comprobante bancario del depósito.',
                'deposit_ticket_id' => $ticket->id,
            ],
            actorId: $userId,
        );
    }
}
