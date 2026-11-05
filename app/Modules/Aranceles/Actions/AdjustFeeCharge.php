<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Actions;

use App\Modules\Aranceles\Enums\FeeChargeStatus;
use App\Modules\Aranceles\Models\FeeCharge;
use App\Modules\Aranceles\Models\FeeChargeBalance;
use App\Modules\Aranceles\Models\FeeChargeItem;
use App\Modules\Aranceles\Models\FeeChargeItemAdjustment;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Support\Reason;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Acepta más o menos de lo calculado en un cobro pendiente (reglas A60 a A62).
 *
 * Cambia el importe vigente de cada renglón y conserva el original. Con varios
 * renglones, quien ajusta reparte la diferencia, para que el informe sepa a qué
 * tipo atribuirla. Si lo vinculado alcanza el total nuevo, el cobro se completa y
 * sale su recibo. Un cobro con recibo no se ajusta: se corrige (A63).
 */
final class AdjustFeeCharge
{
    public function __construct(
        private readonly CompleteFeeCharge $completar,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /**
     * @param  array<int, array{amount: string, previous: string}>  $importes  Por renglón editado, el importe nuevo y el que se tenía a la vista.
     *
     * @throws ValidationException
     */
    public function handle(FeeCharge $cobro, array $importes, Reason $motivo, ?int $signedById, ?int $actorId): FeeCharge
    {
        return DB::transaction(function () use ($cobro, $importes, $motivo, $signedById, $actorId): FeeCharge {
            $cobro = FeeCharge::query()->whereKey($cobro->id)->lockForUpdate()->firstOrFail();

            if ($cobro->status !== FeeChargeStatus::Pending) {
                throw ValidationException::withMessages(['lines' => 'Solo se ajusta un cobro pendiente: uno con recibo se corrige.']);
            }

            $anterior = (string) FeeChargeBalance::query()->whereKey($cobro->id)->value('total');
            $vigentes = DB::table('fee_charge_item_amounts')->where('fee_charge_id', $cobro->id)->pluck('amount', 'fee_charge_item_id');
            $cambios = 0;

            foreach (FeeChargeItem::query()->where('fee_charge_id', $cobro->id)->orderBy('line_number')->get() as $renglon) {
                if (! isset($importes[$renglon->id])) {
                    continue;
                }

                $nuevo = Decimal::scale($importes[$renglon->id]['amount']);
                $vigente = Decimal::scale((string) $vigentes[$renglon->id]);

                // El cobro bloqueado ordena los ajustes; esto evita pisar el de otro.
                if (! Decimal::equals($importes[$renglon->id]['previous'], $vigente)) {
                    throw ValidationException::withMessages([
                        'lines' => 'Otra persona ajustó este cobro mientras lo editabas: el renglón '.$renglon->line_number.' ahora vale $ '.Decimal::format($vigente).'. Volvé a abrirlo.',
                    ]);
                }

                if (Decimal::equals($nuevo, $vigente)) {
                    continue;
                }

                if (Decimal::isNegative($nuevo) || Decimal::equals($nuevo, '0')) {
                    throw ValidationException::withMessages(['lines' => 'El importe tiene que ser mayor que cero.']);
                }

                FeeChargeItemAdjustment::query()->create([
                    'fee_charge_item_id' => $renglon->id,
                    'previous_amount' => $vigente,
                    'new_amount' => $nuevo,
                    'change_reason_code' => $motivo->code->value,
                    'change_reason' => $motivo->detail === null ? null : mb_substr($motivo->detail, 0, 300),
                    'adjusted_by' => $actorId,
                ]);
                $cambios++;
            }

            if ($cambios === 0) {
                throw ValidationException::withMessages(['lines' => 'No cambió ningún importe.']);
            }

            $saldo = FeeChargeBalance::query()->findOrFail($cobro->id);
            $total = (string) $saldo->total;
            $vinculado = (string) $saldo->funded;

            if (Decimal::isNegative(Decimal::sub($total, $vinculado))) {
                throw ValidationException::withMessages([
                    'lines' => 'Ya hay $ '.Decimal::format($vinculado).' vinculados: el total no puede ser menor. Si ese dinero no es de este cobro, anulalo.',
                ]);
            }

            $this->auditar->handle('arancel.ajustado', $cobro, metadata: [
                'anterior' => $anterior,
                'amount' => $total,
                ...$motivo->auditMetadata(),
            ], actorId: $actorId);

            if (Decimal::equals($total, $vinculado)) {
                $this->completar->handle($cobro, $signedById, $actorId);
            }

            return $cobro->refresh();
        });
    }
}
