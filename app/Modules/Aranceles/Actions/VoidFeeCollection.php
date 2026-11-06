<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Actions;

use App\Modules\Aranceles\Enums\FeeChargeMedium;
use App\Modules\Aranceles\Enums\FeeChargeStatus;
use App\Modules\Aranceles\Models\FeeCharge;
use App\Modules\Aranceles\Models\FeeChargeFunding;
use App\Modules\Aranceles\Support\FeeChargeFunds;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Enums\ReceiptStatus;
use App\Modules\Shared\Models\Receipt;
use App\Modules\Shared\Support\Reason;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Anula un cobro cuyo dinero nunca entró (regla A70), solo mientras el efectivo
 * siga en la caja: lo depositado sí entró. También un cobro por el banco, con su
 * recibo o pendiente (A71): el crédito vuelve a quedar libre en el extracto.
 *
 * Revierte la aplicación y la recepción con fecha de hoy. El recibo queda anulado
 * con su número, que no se reutiliza, y el cobro queda a la vista con su motivo.
 *
 * Toma los bloqueos en orden —cobro, créditos y recepciones—, como un depósito o
 * una identificación: si dos llegan juntos, el segundo espera y encuentra lo que
 * hizo el primero.
 */
final class VoidFeeCollection
{
    public function __construct(
        private readonly FeeChargeFunds $fondos,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /** @throws ValidationException */
    public function handle(FeeCharge $cobro, Reason $motivo, ?int $actorId): FeeCharge
    {
        return DB::transaction(function () use ($cobro, $motivo, $actorId): FeeCharge {
            $cobro = FeeCharge::query()->whereKey($cobro->id)->lockForUpdate()->firstOrFail();

            // Un segundo envío del mismo formulario.
            if ($cobro->status === FeeChargeStatus::Voided) {
                return $cobro;
            }

            if ($cobro->medium === FeeChargeMedium::Bank) {
                $this->fondos->lockCreditsOf($cobro);
            }

            $recepciones = FundReceipt::query()
                ->whereIn('id', FeeChargeFunding::query()->where('fee_charge_id', $cobro->id)->whereNull('released_at')->select('fund_receipt_id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($recepciones as $recepcion) {
                if ($this->fondos->isDeposited($recepcion->id)) {
                    throw ValidationException::withMessages([
                        'reason' => 'Ese efectivo ya salió de la caja para depositarlo: no se puede afirmar que nunca entró. Si el error es otro, corregí el cobro o reemplazá su recibo.',
                    ]);
                }
            }

            $this->fondos->release($cobro, $motivo, $actorId);

            foreach ($recepciones as $recepcion) {
                $this->fondos->reverseReceipt($recepcion, 'arancel-cobro-'.$cobro->id.'-anulacion:recepcion-'.$recepcion->id, $motivo, $actorId);
            }

            $numeros = self::anularRecibos($cobro, $motivo, $actorId);

            self::marcarAnulado($cobro, $motivo, $actorId);

            $this->auditar->handle('arancel.cobro-anulado', $cobro, metadata: [
                'recibo' => implode(', ', $numeros),
                ...$motivo->auditMetadata(),
            ], actorId: $actorId);

            return $cobro;
        });
    }

    /** @return list<string> los números de los recibos anulados */
    public static function anularRecibos(FeeCharge $cobro, Reason $motivo, ?int $actorId): array
    {
        $numeros = [];

        $recibos = Receipt::query()
            ->whereIn('id', $cobro->chargeReceipts()->select('receipt_id'))
            ->where('status', ReceiptStatus::Issued)
            ->get();

        foreach ($recibos as $recibo) {
            $recibo->forceFill([
                'status' => ReceiptStatus::Voided,
                'void_reason' => mb_substr($motivo->describe(), 0, 300),
                'void_reason_code' => $motivo->code->value,
                'voided_by' => $actorId,
                'voided_at' => now(),
            ])->save();

            $numeros[] = $recibo->formatted_number;
        }

        return $numeros;
    }

    public static function marcarAnulado(FeeCharge $cobro, Reason $motivo, ?int $actorId): void
    {
        $cobro->forceFill([
            'status' => FeeChargeStatus::Voided,
            'voided_at' => now(),
            'voided_by' => $actorId,
            'void_reason_code' => $motivo->code->value,
            'void_reason' => $motivo->detail === null ? null : mb_substr($motivo->detail, 0, 300),
        ])->save();
    }
}
