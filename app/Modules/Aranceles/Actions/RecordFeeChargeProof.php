<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Actions;

use App\Modules\Aranceles\Enums\FeeChargeMedium;
use App\Modules\Aranceles\Enums\FeeChargeStatus;
use App\Modules\Aranceles\Models\FeeCharge;
use App\Modules\Aranceles\Models\FeeChargeProof;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Actions\StoreAttachment;
use App\Modules\Shared\Enums\AttachmentSource;
use App\Modules\Shared\Enums\AttachmentSubject;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Carga o cambia el comprobante del depósito de un cobro por banco: lo que dice el
 * papel y su foto. Sin él, un pendiente no se identifica.
 *
 * Solo se agrega: cambiarlo suma otro, vale el último y el anterior queda como
 * rastro. No mueve dinero ni identifica el crédito, y el importe puede diferir del
 * cobro: el dinero se verifica contra el extracto.
 */
final class RecordFeeChargeProof
{
    public function __construct(
        private readonly StoreAttachment $adjuntos,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /**
     * @param  array{depositDate: CarbonImmutable, depositTime: string|null, operationNumber: string|null, terminal: string|null, notes: string|null}  $papel
     *
     * @throws ValidationException
     */
    public function handle(FeeCharge $cobro, string $importe, array $papel, UploadedFile $foto, string $idempotencyKey, ?int $actorId): FeeChargeProof
    {
        $adjunto = null;

        try {
            return DB::transaction(function () use ($cobro, $importe, $papel, $foto, $idempotencyKey, $actorId, &$adjunto): FeeChargeProof {
                $vigente = FeeCharge::query()->whereKey($cobro->id)->lockForUpdate()->firstOrFail();

                // Un segundo envío del mismo formulario: ya se cargó.
                $yaCargado = FeeChargeProof::query()->where('idempotency_key', $idempotencyKey)->first();

                if ($yaCargado !== null) {
                    return $yaCargado;
                }

                if ($vigente->medium !== FeeChargeMedium::Bank) {
                    throw ValidationException::withMessages(['ticket' => 'Solo un cobro por depósito o transferencia lleva comprobante.']);
                }

                if ($vigente->status === FeeChargeStatus::Voided) {
                    throw ValidationException::withMessages(['ticket' => 'El cobro está anulado: su comprobante ya no se cambia.']);
                }

                $cambia = FeeChargeProof::query()->where('fee_charge_id', $vigente->id)->exists();

                $adjunto = $this->adjuntos->handle(
                    file: $foto,
                    subject: AttachmentSubject::FeeCharge,
                    subjectId: $vigente->id,
                    documentType: 'deposit_proof',
                    userId: $actorId,
                    title: 'Comprobante del depósito',
                    source: AttachmentSource::Scanned,
                );

                $comprobante = FeeChargeProof::query()->create([
                    'fee_charge_id' => $vigente->id,
                    'attachment_id' => $adjunto->id,
                    'amount' => $importe,
                    'deposit_date' => $papel['depositDate'],
                    'deposit_time' => $papel['depositTime'],
                    'operation_number' => $papel['operationNumber'],
                    'terminal' => $papel['terminal'],
                    'notes' => $papel['notes'],
                    'idempotency_key' => $idempotencyKey,
                    'created_by' => $actorId,
                ]);

                $this->auditar->handle(
                    $cambia ? 'arancel.comprobante-cambiado' : 'arancel.comprobante-cargado',
                    $vigente,
                    metadata: [
                        'amount' => $importe,
                        'fecha' => $papel['depositDate']->format('d/m/Y'),
                        'operacion' => $papel['operationNumber'],
                    ],
                    actorId: $actorId,
                );

                return $comprobante;
            });
        } catch (Throwable $e) {
            // La transacción volvió atrás y se llevó la fila: el archivo quedaría huérfano.
            if ($adjunto !== null) {
                $this->adjuntos->deleteFile(['disk' => $adjunto->storage_disk, 'key' => $adjunto->object_key]);
            }

            if (! $e instanceof UniqueConstraintViolationException) {
                throw $e;
            }

            // El mismo clic dos veces a la vez: el que llegó tarde devuelve el que ganó.
            return FeeChargeProof::query()->where('idempotency_key', $idempotencyKey)->first() ?? throw $e;
        }
    }
}
