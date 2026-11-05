<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Actions;

use App\Modules\Aranceles\Enums\FeeChargeMedium;
use App\Modules\Aranceles\Models\FeeCharge;
use App\Modules\Aranceles\Support\FeeBankCredits;
use App\Modules\Aranceles\Support\FeeLineCalculator;
use App\Modules\Banking\Models\BankTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Un crédito que llegó sin paso por ventanilla: el cobro nace al identificarlo
 * (regla A56), con el precio de la fecha del más antiguo de sus créditos (A33).
 * Si los créditos no alcanzan, queda pendiente con lo que falta.
 *
 * @phpstan-import-type Linea from FeeLineCalculator
 */
final class RecordVerifiedFeeBankPayment
{
    public function __construct(
        private readonly FeeLineCalculator $calcular,
        private readonly CollectFeeInCash $cobros,
        private readonly FeeBankCredits $creditos,
        private readonly IdentifyFeeCredits $identificar,
    ) {}

    /**
     * @param  list<Linea>  $lineas
     * @param  list<int>  $creditos
     *
     * @throws ValidationException
     */
    public function handle(
        string $payerName,
        ?string $payerCuit,
        array $lineas,
        array $creditos,
        string $idempotencyKey,
        ?int $signedById,
        ?int $actorId,
        bool $puedeCambiarImporte,
    ): FeeCharge {
        try {
            return DB::transaction(function () use ($payerName, $payerCuit, $lineas, $creditos, $idempotencyKey, $signedById, $actorId, $puedeCambiarImporte): FeeCharge {
                $yaRegistrado = FeeCharge::query()->where('idempotency_key', $idempotencyKey)->first();

                if ($yaRegistrado !== null) {
                    return $yaRegistrado;
                }

                if ($this->creditos->book() === null) {
                    throw ValidationException::withMessages(['credits' => 'La caja de Aranceles todavía no se abrió o no tiene cuentas.']);
                }

                $masAntiguo = BankTransaction::query()->whereIn('id', $creditos)->min('transaction_date');

                if ($masAntiguo === null) {
                    throw ValidationException::withMessages(['credits' => 'Elegí al menos un crédito del extracto.']);
                }

                $fechaDeTarifa = CarbonImmutable::parse((string) $masAntiguo);
                ['items' => $items] = $this->calcular->build($lineas, $fechaDeTarifa, $puedeCambiarImporte);

                $cobro = $this->cobros->crear($payerName, $payerCuit, $items, $fechaDeTarifa, null, $idempotencyKey, $actorId, FeeChargeMedium::Bank);

                $this->identificar->within($cobro, $creditos, $idempotencyKey, $signedById, $actorId);

                return $cobro->refresh();
            });
        } catch (UniqueConstraintViolationException $e) {
            return FeeCharge::query()->where('idempotency_key', $idempotencyKey)->first() ?? throw $e;
        }
    }
}
