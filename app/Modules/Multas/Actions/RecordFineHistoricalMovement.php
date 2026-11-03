<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Multas\Enums\FineProvenance;
use App\Modules\Multas\Enums\HistoricalMovementKind;
use App\Modules\Multas\Enums\HistoricalMovementStatus;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineHistoricalMovement;
use App\Modules\Multas\Support\FineAppeals;
use App\Modules\Multas\Support\PartialDate;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deja constancia de un pago —o una devolución— anterior al sistema.
 *
 * No es plata que entra: esa ya se rindió en su momento. Por eso **no
 * genera asientos** y no cuenta en el saldo hasta que alguien con permiso
 * de ajustar la valida (DER físico v1.1, §3.8). La fecha puede ser solo el
 * mes, que es lo que da el Excel.
 */
final class RecordFineHistoricalMovement
{
    public function __construct(
        private readonly FineAppeals $recursos,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /** @throws ValidationException */
    public function handle(
        Fine $multa,
        HistoricalMovementKind $tipo,
        string $amount,
        PartialDate $occurred,
        string $evidence,
        ?int $actorId,
    ): FineHistoricalMovement {
        return DB::transaction(function () use ($multa, $tipo, $amount, $occurred, $evidence, $actorId): FineHistoricalMovement {
            // Con la multa bloqueada: un recurso que se registra a la vez espera o la ve.
            $multa = Fine::query()->whereKey($multa->id)->lockForUpdate()->firstOrFail();
            $this->recursos->assertAcceptsHistorical($multa, 'amount');

            $antecedente = FineHistoricalMovement::query()->create([
                'fine_id' => $multa->id,
                'kind' => $tipo,
                'amount' => Decimal::scale($amount),
                'occurred_on' => $occurred->date,
                'occurred_precision' => $occurred->precision,
                'evidence_text' => trim($evidence),
                'status' => HistoricalMovementStatus::PendingReview,
                'provenance' => FineProvenance::Manual,
                'created_by' => $actorId,
            ]);

            $this->auditar->handle(
                'multa.antecedente-registrado',
                $multa,
                metadata: [
                    'tipo' => $tipo->label(),
                    'amount' => $antecedente->amount,
                    'respaldo' => $antecedente->evidence_text,
                ],
                actorId: $actorId,
            );

            return $antecedente;
        });
    }
}
