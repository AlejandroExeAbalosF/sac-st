<?php

declare(strict_types=1);

namespace App\Modules\Multas\Actions;

use App\Modules\Banking\Actions\DepositWholeReceiptToBank;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Multas\Enums\FinePaymentStatus;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Multas\Support\FineDeposits;
use App\Modules\Shared\Actions\RecordAuditEvent;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lleva al banco un cobro de la caja: entero, solo y con el ticket del
 * cajero (M06).
 *
 * El cobro ya está aplicado y tiene su recibo: depositarlo no es recaudar
 * otra vez ni toca la deuda. Cambia dónde está la plata —de la caja, o de
 * custodia si es un cheque, a tránsito— hasta que el extracto lo acredite.
 * El traslado lo arma Banking (`DepositWholeReceiptToBank`); acá se decide
 * qué cobro es y se deja el rastro en la multa.
 */
final class DepositFineCollection
{
    public function __construct(
        private readonly DepositWholeReceiptToBank $depositar,
        private readonly FineDeposits $depositos,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /**
     * @param  array{
     *     depositDate: CarbonInterface,
     *     depositTime?: string|null,
     *     operationNumber?: string|null,
     *     terminal?: string|null,
     *     notes?: string|null,
     * }  $ticket
     *
     * @throws ValidationException
     */
    public function handle(
        FinePayment $pago,
        array $ticket,
        UploadedFile $foto,
        string $idempotencyKey,
        ?int $actorId,
    ): CashToBankTransfer {
        return DB::transaction(function () use ($pago, $ticket, $foto, $idempotencyKey, $actorId): CashToBankTransfer {
            // Un segundo envío: el depósito ya está, y su rastro también.
            $yaTrasladado = CashToBankTransfer::query()
                ->whereRelation('depositEvent', 'idempotency_key', $idempotencyKey)
                ->first();

            if ($yaTrasladado !== null) {
                return $yaTrasladado;
            }

            if ($pago->status !== FinePaymentStatus::Applied || ! $pago->medium->isCounter()) {
                throw ValidationException::withMessages([
                    'receiptId' => 'Se deposita lo cobrado en la caja: efectivo o cheque, ya aplicado a la multa.',
                ]);
            }

            $recepcion = $this->depositos->receiptOf($pago)
                ?? throw ValidationException::withMessages(['receiptId' => 'Ese cobro no tiene su recepción en la caja.']);

            $traslado = $this->depositar->handle($recepcion, $ticket, $foto, $idempotencyKey, $actorId);

            $this->auditar->handle('multa.cobro-depositado', $pago->fine, metadata: array_filter([
                'medio' => $pago->medium->label(),
                'amount' => $traslado->amount,
                'recibo' => $pago->paymentReceipt?->receipt()->value('formatted_number'),
                'fecha' => $traslado->deposit_date->format('d/m/Y'),
                'traslado' => 'N.º '.$traslado->id,
            ]), actorId: $actorId);

            return $traslado;
        });
    }
}
