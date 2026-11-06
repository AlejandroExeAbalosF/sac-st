<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Actions;

use App\Modules\Aranceles\Enums\FeeChargeStatus;
use App\Modules\Aranceles\Models\FeeCharge;
use App\Modules\Aranceles\Models\FeeChargeReceipt;
use App\Modules\Ledger\Models\ReceiptFinancialEvent;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Enums\ReceiptStatus;
use App\Modules\Shared\Support\Reason;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Corrige lo que dice un recibo sin mover dinero: nombre, CUIT o DNI o redacción
 * (regla A72). También después de depositar.
 *
 * Hay recibo nuevo con número nuevo, y el anterior queda `replaced` a la vista.
 * Tipo, cantidad e importe no cambian: salen de los renglones, y eso se corrige
 * con otro cobro. Conserva la fecha y los hechos del original: la recaudación no
 * se mueve de mes (A102).
 */
final class ReplaceFeeReceipt
{
    public function __construct(
        private readonly IssueFeeReceipt $emitir,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /**
     * @param  array<int, string>  $wording  Por número de renglón, el concepto corregido.
     *
     * @throws ValidationException
     */
    public function handle(
        FeeCharge $cobro,
        string $payerName,
        ?string $payerDocument,
        array $wording,
        Reason $motivo,
        ?int $signedById,
        ?int $actorId,
    ): FeeChargeReceipt {
        return DB::transaction(function () use ($cobro, $payerName, $payerDocument, $wording, $motivo, $signedById, $actorId): FeeChargeReceipt {
            $cobro = FeeCharge::query()->whereKey($cobro->id)->lockForUpdate()->firstOrFail();

            if ($cobro->status !== FeeChargeStatus::Paid) {
                throw ValidationException::withMessages(['reason' => 'Se reemplaza el recibo de un cobro pagado.']);
            }

            $vigente = FeeChargeReceipt::query()
                ->where('fee_charge_id', $cobro->id)
                ->whereRelation('receipt', 'status', ReceiptStatus::Issued)
                ->with('receipt')
                ->lockForUpdate()
                ->firstOrFail();
            $anterior = $vigente->receipt;
            $papel = $vigente->snapshot;
            $cambio = trim($payerName) !== $papel['payerName'] || ($payerDocument ?: null) !== ($papel['payerDocument'] ?? null);

            foreach ($papel['lines'] as $indice => $linea) {
                $texto = trim($wording[$indice + 1] ?? '');

                if ($texto !== '' && $texto !== $linea['name']) {
                    $papel['lines'][$indice]['name'] = mb_substr($texto, 0, 300);
                    $cambio = true;
                }
            }

            if (! $cambio) {
                throw ValidationException::withMessages(['payerName' => 'No cambió nada: el recibo diría lo mismo que el vigente.']);
            }

            $papel['payerName'] = trim($payerName);
            $papel['payerDocument'] = $payerDocument ?: null;

            $anterior->forceFill([
                'status' => ReceiptStatus::Replaced,
                'void_reason' => mb_substr($motivo->describe(), 0, 300),
                'void_reason_code' => $motivo->code->value,
                'voided_by' => $actorId,
                'voided_at' => now(),
            ])->save();

            $comprobante = $this->emitir->handle(
                $cobro,
                $papel,
                Decimal::scale($anterior->amount),
                $anterior->issue_date,
                array_values(ReceiptFinancialEvent::query()->where('receipt_id', $anterior->id)->pluck('financial_event_id')->all()),
                null,
                $signedById,
                $actorId,
                $anterior->id,
            );

            $this->auditar->handle('arancel.recibo-reemplazado', $cobro, metadata: [
                'recibo' => $anterior->formatted_number,
                'nuevo' => $comprobante->receipt()->value('formatted_number'),
                ...$motivo->auditMetadata(),
            ], actorId: $actorId);

            return $comprobante;
        });
    }
}
