<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Haberes\Enums\DepositTicketStatus;
use App\Modules\Haberes\Models\DepositTicket;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Actions\StoreAttachment;
use App\Modules\Shared\Enums\AttachmentSource;
use App\Modules\Shared\Enums\AttachmentSubject;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Corrige lo que se transcribió del comprobante.
 *
 * **Un ticket no es un hecho monetario: es la copia a mano de un papel.**
 * Por eso se edita, a diferencia de un movimiento bancario o una línea del
 * diario. Y por eso hace falta que se pueda: el cruce contra el extracto
 * exige importe exacto, así que un dígito mal tipeado hace que el
 * movimiento **no aparezca nunca**. El error se manifiesta como «el
 * depósito no está en el banco», un síntoma que no señala su causa.
 *
 * **Solo mientras espera.** Vinculado, sus datos ya son la base de una
 * afirmación —«este papel y este crédito son el mismo hecho»— y cambiarlos
 * por debajo dejaría un cruce que nadie podría justificar. El camino es
 * desvincular primero, que deja rastro en `audit_events`.
 */
final class UpdateDepositTicket
{
    public function __construct(
        private readonly RecordAuditEvent $auditar,
        private readonly StoreAttachment $attachments,
    ) {}

    /**
     * @param  array<string, mixed>  $datos
     */
    public function handle(
        DepositTicket $ticket,
        array $datos,
        ?UploadedFile $foto,
        ?int $userId,
    ): DepositTicket {
        $this->assertEditable($ticket);

        $antes = $this->snapshot($ticket);
        $adjunto = null;

        try {
            return DB::transaction(function () use ($ticket, $datos, $foto, $userId, $antes, &$adjunto): DepositTicket {
                /*
                 * Lo que no vino en el formulario no se toca.
                 *
                 * `?? null` habría sido el error caro: el modal de la
                 * cuota corrige el importe sin enviar haber ni cuota
                 * —ahí ya se saben— y con esa lectura una corrección de
                 * un dígito desvinculaba el comprobante de su cuota en
                 * silencio. Distinguir «no vino» de «vino vacío» es lo
                 * único que lo impide.
                 */
                $ticket->forceFill([
                    'haber_id' => array_key_exists('haberId', $datos)
                        ? $datos['haberId']
                        : $ticket->haber_id,
                    'beneficiary_installment_id' => array_key_exists('installmentId', $datos)
                        ? $datos['installmentId']
                        : $ticket->beneficiary_installment_id,
                    'bank_account_id' => $datos['bankAccountId'],
                    'deposited_at' => $datos['depositedAt'],
                    'deposited_time' => $datos['depositedTime'] ?? null,
                    'amount' => $datos['amount'],
                    'operation_number' => $datos['operationNumber'] ?? null,
                    'terminal' => $datos['terminal'] ?? null,
                    'deposit_kind' => $datos['depositKind'],
                    'notes' => $datos['notes'] ?? null,
                ])->save();

                /*
                 * La foto no se reemplaza: se agrega otra.
                 *
                 * `attachments` es inmutable por trigger, y el DER lo pide
                 * así —*«cada reenvío genera otro attachment»*—. La
                 * anterior queda como rastro de qué se estuvo mirando
                 * cuando se cargaron los datos viejos, y la pantalla
                 * muestra la última.
                 */
                if ($foto !== null) {
                    $adjunto = $this->attachments->handle(
                        file: $foto,
                        subject: AttachmentSubject::DepositTicket,
                        subjectId: $ticket->id,
                        documentType: 'deposit_ticket',
                        userId: $userId,
                        title: 'Comprobante del depósito',
                        source: AttachmentSource::Scanned,
                    );
                }

                $this->auditar->handle(
                    'ticket.corregido',
                    $ticket,
                    before: $antes,
                    after: $this->snapshot($ticket->refresh()),
                    metadata: $foto === null ? [] : ['foto' => 'reemplazada'],
                    actorId: $userId,
                );

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

    private function assertEditable(DepositTicket $ticket): void
    {
        if ($ticket->status === DepositTicketStatus::Matched) {
            throw new RuntimeException(
                'El comprobante ya está vinculado a un movimiento bancario. '
                .'Para corregirlo hay que desvincularlo primero.'
            );
        }

        if ($ticket->status === DepositTicketStatus::Discarded) {
            throw new RuntimeException(
                'El comprobante está descartado. Hay que reabrirlo antes de corregirlo.'
            );
        }
    }

    /**
     * Lo que la auditoría necesita para reconstruir la corrección.
     *
     * @return array<string, mixed>
     */
    private function snapshot(DepositTicket $ticket): array
    {
        return [
            'amount' => $ticket->amount,
            'deposited_at' => $ticket->deposited_at->toDateString(),
            'deposited_time' => $ticket->deposited_time,
            'operation_number' => $ticket->operation_number,
            'terminal' => $ticket->terminal,
            'bank_account_id' => $ticket->bank_account_id,
            'haber_id' => $ticket->haber_id,
            'beneficiary_installment_id' => $ticket->beneficiary_installment_id,
            'deposit_kind' => $ticket->deposit_kind->value,
        ];
    }
}
