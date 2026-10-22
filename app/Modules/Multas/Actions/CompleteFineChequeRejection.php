<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Banking\Actions\CompleteChequeRejection;
use App\Modules\Banking\Enums\ChequeDisposition;
use App\Modules\Banking\Models\ChequeRejection;
use App\Modules\Multas\Support\FineDeposits;
use App\Modules\Shared\Actions\RecordAuditEvent;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El aviso a la empresa y qué se hizo con el cheque rechazado (M03), con su
 * constancia. Se completan después del rechazo, una vez cada uno. Lo
 * registra Banking (`CompleteChequeRejection`); acá queda el rastro en la
 * multa.
 */
final class CompleteFineChequeRejection
{
    public function __construct(
        private readonly CompleteChequeRejection $completar,
        private readonly FineDeposits $depositos,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /** @throws ValidationException */
    public function handle(
        ChequeRejection $rechazo,
        ?CarbonInterface $companyNotifiedOn,
        ?UploadedFile $notice,
        ?ChequeDisposition $disposition,
        ?UploadedFile $dispositionProof,
        ?int $actorId,
    ): ChequeRejection {
        return DB::transaction(function () use ($rechazo, $companyNotifiedOn, $notice, $disposition, $dispositionProof, $actorId): ChequeRejection {
            $rechazo = $this->completar->handle($rechazo, $companyNotifiedOn, $notice, $disposition, $dispositionProof, $actorId);
            $pago = $this->depositos->paymentOfRejection($rechazo);

            if ($pago !== null) {
                $this->auditar->handle('multa.rechazo-completado', $pago->fine, metadata: array_filter([
                    'aviso' => $companyNotifiedOn?->format('d/m/Y'),
                    'disposicion' => $disposition?->label(),
                ]), actorId: $actorId);
            }

            return $rechazo;
        });
    }
}
