<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Multas\Enums\FinePaymentMedium;
use App\Modules\Multas\Enums\FinePaymentStatus;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineBalance;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Enums\ReceiptType;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Support\TalonarioSequence;
use App\Support\BusinessDate;
use App\Support\Money\Decimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Resuelve un recurso cuyo recibo externo no coincide con el 20 % (M07).
 *
 * Lo hace el administrador —el permiso no se delega— después de revisar la
 * multa, sus ajustes y el redondeo. Hay dos salidas y ninguna es una
 * excepción al 20 %:
 *
 * - **Aplicar** por el importe exigido: el recibo externo estaba mal. En
 *   efectivo se cobra en el acto y sale el recibo; por depósito directo queda
 *   informado, a verificar contra el extracto.
 * - **Descartar**: el dato propio estaba mal o el respaldo no sirve. Se
 *   corrige lo que corresponda y se registra un intento nuevo, con la base de
 *   ese momento.
 *
 * Queda quién resolvió y por qué.
 *
 * Aplicarlo en efectivo recibe dinero en ese momento, así que se vuelve a
 * mirar el saldo: si la multa ya se pagó por otro lado mientras el recurso
 * esperaba, no se cobra —se descarta—. Por depósito directo el dinero puede
 * estar ya en el banco, y se identifica al verificarlo, aunque sea excedente.
 */
final class ResolveFineAppeal
{
    public function __construct(
        private readonly ReceiveFinePaymentAtCounter $recibir,
        private readonly TalonarioSequence $talonarios,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(
        FinePayment $pago,
        bool $aplicar,
        string $reason,
        ?int $actorId,
        ?string $talonarioNumber = null,
        ?int $signedById = null,
    ): FinePayment {
        $motivo = trim($reason);
        $talonario = trim((string) $talonarioNumber) ?: null;

        if (mb_strlen($motivo) < 5) {
            throw ValidationException::withMessages(['reason' => 'Decí qué se revisó y por qué se resuelve así.']);
        }

        try {
            return $this->resolve($pago, $aplicar, $motivo, $actorId, $talonario, $signedById);
        } catch (UniqueConstraintViolationException $e) {
            throw $this->talonarios->translate(
                $e, ReceiptType::Income, (int) CashBox::query()->where('code', CashBox::MULTAS)->value('id'), $talonario,
            ) ?? $e;
        }
    }

    /**
     * @throws ValidationException
     * @throws UniqueConstraintViolationException dos cobros simultáneos con la misma hoja del talonario
     */
    private function resolve(
        FinePayment $pago,
        bool $aplicar,
        string $motivo,
        ?int $actorId,
        ?string $talonario,
        ?int $signedById,
    ): FinePayment {
        return DB::transaction(function () use ($pago, $aplicar, $motivo, $actorId, $talonario, $signedById): FinePayment {
            $multa = Fine::query()->whereKey($pago->fine_id)->lockForUpdate()->firstOrFail();
            $pago = FinePayment::query()->whereKey($pago->id)->lockForUpdate()->firstOrFail();

            if ($pago->status !== FinePaymentStatus::UnderReview) {
                throw ValidationException::withMessages(['reason' => 'Ese recurso ya no está en revisión.']);
            }

            if ($aplicar && $pago->medium === FinePaymentMedium::Cash
                && Decimal::equals((string) FineBalance::query()->whereKey($multa->id)->value('debt'), '0')) {
                throw ValidationException::withMessages([
                    'decision' => 'La multa ya no tiene saldo pendiente: no se cobra el recurso. Descartalo con su motivo.',
                ]);
            }

            $ahora = now();

            $pago->forceFill($aplicar
                ? [
                    'status' => $pago->medium === FinePaymentMedium::Cash
                        ? FinePaymentStatus::Applied
                        : FinePaymentStatus::Reported,
                    'reviewed_by' => $actorId,
                    'reviewed_at' => $ahora,
                    'review_reason' => $motivo,
                ]
                : [
                    'status' => FinePaymentStatus::Discarded,
                    'discarded_by' => $actorId,
                    'discarded_at' => $ahora,
                    'discard_reason' => $motivo,
                ])->save();

            $comprobante = $pago->status === FinePaymentStatus::Applied
                ? $this->recibir->handle($pago, BusinessDate::today(), null, $talonario, $signedById, $actorId)
                : null;

            $this->auditar->handle('multa.recurso-resuelto', $multa, metadata: array_filter([
                'decision' => match ($pago->status) {
                    FinePaymentStatus::Applied => 'Aplicado y cobrado por el 20 % exigido',
                    FinePaymentStatus::Reported => 'Aplicado por el 20 % exigido; el depósito queda por verificar',
                    default => 'Descartado',
                },
                'exigido' => $pago->appeal_required_amount,
                'importe_externo' => $pago->external_receipt_amount,
                'reason' => $motivo,
                'recibo' => $comprobante?->receipt()->value('formatted_number'),
            ]), actorId: $actorId);

            return $pago;
        });
    }
}
