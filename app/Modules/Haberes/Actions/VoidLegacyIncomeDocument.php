<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Actions;

use App\Modules\Haberes\Enums\LegacyDocumentKind;
use App\Modules\Haberes\Enums\PaymentOrderStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\Haber;
use App\Modules\Haberes\Models\LegacyDocument;
use App\Modules\Haberes\Models\PaymentOrder;
use App\Modules\Haberes\Support\InstallmentFunding;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Anula un recibo de ingreso de papel mal cargado al apartar fondos.
 *
 * Al liberar la plata apartada el papel queda —es un hecho, y al volver a
 * apartar se reutiliza—, así que un número, una fecha o un importe mal
 * tipeados no tendrían salida. Esta es la salida: se anula con motivo y
 * se carga el correcto en el próximo apartado. Nada se borra.
 *
 * Solo cuando el papel ya no respalda nada: sin plata apartada en la
 * cuota y sin Orden de Pago que lo cite. La base lo impone igual
 * (`legacy_income_document_keeps_backing`).
 *
 * Los papeles de un pago registrado fuera del circuito no se anulan acá:
 * caen con su registro (`VoidLegacySettlement`).
 */
final class VoidLegacyIncomeDocument
{
    public function __construct(
        private readonly InstallmentFunding $financiacion,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /** @throws ValidationException */
    public function handle(BeneficiaryInstallment $installment, string $reason, ?int $actorId = null): LegacyDocument
    {
        $motivo = trim($reason);

        if (mb_strlen($motivo) < 10) {
            throw ValidationException::withMessages([
                'reason' => 'El motivo tiene que explicar qué estaba mal en el papel.',
            ]);
        }

        return DB::transaction(function () use ($installment, $motivo, $actorId): LegacyDocument {
            $expedienteId = (int) Haber::query()->whereKey($installment->haber_id)->valueOrFail('expediente_id');

            Expediente::query()->lockForUpdate()->findOrFail($expedienteId);
            Haber::query()->lockForUpdate()->findOrFail($installment->haber_id);
            $cuota = BeneficiaryInstallment::query()->lockForUpdate()->findOrFail($installment->id);

            $papel = LegacyDocument::query()
                ->current()
                ->where('beneficiary_installment_id', $cuota->id)
                ->where('kind', LegacyDocumentKind::IncomeReceipt->value)
                ->whereNull('legacy_settlement_id')
                ->lockForUpdate()
                ->first();

            if ($papel === null) {
                throw ValidationException::withMessages([
                    'reason' => 'La cuota no tiene un recibo de papel cargado al reservar fondos.',
                ]);
            }

            if (! Decimal::equals($this->financiacion->allocated($cuota), '0')) {
                throw ValidationException::withMessages([
                    'reason' => 'El papel respalda plata reservada: primero hay que quitar la reserva.',
                ]);
            }

            $orden = PaymentOrder::query()
                ->where('legacy_income_document_id', $papel->id)
                ->whereNotIn('status', [PaymentOrderStatus::Voided, PaymentOrderStatus::Rejected])
                ->value('formatted_number');

            if ($orden !== null) {
                throw ValidationException::withMessages([
                    'reason' => "El papel lo cita la Orden de Pago {$orden}: primero hay que anularla.",
                ]);
            }

            $papel->forceFill([
                'voided_at' => now(),
                'voided_by' => $actorId,
                'void_reason' => $motivo,
            ])->save();

            $this->auditar->handle('cuota.recibo-de-papel-anulado', $cuota, before: [
                'number' => $papel->number,
                'issued_on' => $papel->issued_on->toDateString(),
                'amount' => $papel->amount,
            ], metadata: ['reason' => $motivo], actorId: $actorId);

            return $papel;
        });
    }
}
