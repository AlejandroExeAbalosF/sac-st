<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Actions;

use App\Modules\Aranceles\Enums\FeeChargeMedium;
use App\Modules\Aranceles\Models\FeeCharge;
use App\Modules\Aranceles\Support\FeeChargeFunds;
use App\Modules\Aranceles\Support\FeeLineCalculator;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Models\CashBookOpening;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Support\BusinessDate;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * El alta en ventanilla de quien va a pagar por depósito (regla A50): el cobro
 * queda pendiente, con el precio del día guardado y sin mover dinero.
 *
 * Un cambio de precio posterior no lo afecta (A57).
 *
 * @phpstan-import-type Linea from FeeLineCalculator
 */
final class CreateFeeCharge
{
    public function __construct(
        private readonly FeeLineCalculator $calcular,
        private readonly CollectFeeInCash $cobros,
        private readonly FeeChargeFunds $fondos,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /**
     * @param  list<Linea>  $lineas
     *
     * @throws ValidationException
     */
    public function handle(
        string $payerName,
        ?string $payerCuit,
        array $lineas,
        string $idempotencyKey,
        ?int $actorId,
        bool $puedeCambiarImporte,
    ): FeeCharge {
        try {
            return DB::transaction(function () use ($payerName, $payerCuit, $lineas, $idempotencyKey, $actorId, $puedeCambiarImporte): FeeCharge {
                $yaCreado = FeeCharge::query()->where('idempotency_key', $idempotencyKey)->first();

                if ($yaCreado !== null) {
                    return $yaCreado;
                }

                if (CashBookOpening::for($this->fondos->cashBoxId(), Currency::Ars) === null) {
                    throw ValidationException::withMessages(['items' => 'La caja de Aranceles todavía no se abrió.']);
                }

                $hoy = BusinessDate::today();
                ['items' => $items, 'total' => $total] = $this->calcular->build($lineas, $hoy, $puedeCambiarImporte);

                $cobro = $this->cobros->crear($payerName, $payerCuit, $items, $hoy, null, $idempotencyKey, $actorId, FeeChargeMedium::Bank);

                $this->auditar->handle('arancel.pendiente-creado', $cobro, metadata: ['amount' => $total], actorId: $actorId);

                return $cobro;
            });
        } catch (UniqueConstraintViolationException $e) {
            // El mismo clic dos veces a la vez: el que llegó tarde devuelve el que ganó.
            return FeeCharge::query()->where('idempotency_key', $idempotencyKey)->first() ?? throw $e;
        }
    }
}
