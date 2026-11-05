<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Actions;

use App\Modules\Aranceles\Enums\FeeChargeMedium;
use App\Modules\Aranceles\Enums\FeeChargeStatus;
use App\Modules\Aranceles\Models\FeeCharge;
use App\Modules\Aranceles\Models\FeeChargeBalance;
use App\Modules\Aranceles\Models\FeeChargeCorrection;
use App\Modules\Aranceles\Models\FeeChargeFunding;
use App\Modules\Aranceles\Support\FeeChargeFunds;
use App\Modules\Aranceles\Support\FeeLineCalculator;
use App\Modules\Ledger\Actions\RegisterCashFundReceipt;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Modules\Shared\Support\Reason;
use App\Support\BusinessDate;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Corrige un cobro: otro tipo, otra cantidad u otro importe (reglas A73 a A76).
 *
 * El cobro anterior queda anulado y a la vista, y nace otro vinculado a él, todo
 * en una transacción: si algo falla, el original sigue vigente. Usa la fecha de
 * tarifa del original y la fecha de hoy para los hechos: el mes cerrado no cambia.
 *
 * - **Mismo importe:** el dinero no se mueve. Se revierte la aplicación y la misma
 *   recepción se aplica al cobro nuevo, aunque ya se haya depositado.
 * - **Otro importe, sin depósito:** se revierte la recepción y se registra la
 *   correcta. Es para lo cargado mal: se recibieron $ 220 y se cargaron $ 250.
 * - **Otro importe, con depósito:** se cancela el depósito sin acreditar, y con
 *   uno acreditado no hay operación: está pendiente con el área.
 *
 * @phpstan-import-type Linea from FeeLineCalculator
 */
final class CorrectFeeCharge
{
    public function __construct(
        private readonly FeeLineCalculator $calcular,
        private readonly FeeChargeFunds $fondos,
        private readonly RegisterCashFundReceipt $recibir,
        private readonly CollectFeeInCash $cobrar,
        private readonly IssueFeeReceipt $emitir,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /**
     * @param  list<Linea>  $lineas
     *
     * @throws ValidationException
     */
    public function handle(
        FeeCharge $cobro,
        string $payerName,
        ?string $payerCuit,
        array $lineas,
        Reason $motivo,
        ?int $signedById,
        ?int $actorId,
        bool $puedeCambiarImporte,
    ): FeeCharge {
        return DB::transaction(function () use ($cobro, $payerName, $payerCuit, $lineas, $motivo, $signedById, $actorId, $puedeCambiarImporte): FeeCharge {
            $cobro = FeeCharge::query()->whereKey($cobro->id)->lockForUpdate()->firstOrFail();

            $yaCorregido = FeeChargeCorrection::query()->where('corrected_charge_id', $cobro->id)->value('new_charge_id');

            if ($yaCorregido !== null) {
                return FeeCharge::query()->whereKey($yaCorregido)->firstOrFail();
            }

            if ($cobro->status !== FeeChargeStatus::Paid) {
                throw ValidationException::withMessages(['reason' => 'Se corrige un cobro pagado.']);
            }

            if ($cobro->medium !== FeeChargeMedium::Cash) {
                throw ValidationException::withMessages(['reason' => 'Un pago por el banco se corrige desde su crédito.']);
            }

            ['items' => $items, 'total' => $total] = $this->calcular->build($lineas, $cobro->pricing_date, $puedeCambiarImporte);
            $totalAnterior = (string) FeeChargeBalance::query()->whereKey($cobro->id)->value('total');
            $mismoImporte = Decimal::equals($total, $totalAnterior);

            $recepciones = FundReceipt::query()
                ->whereIn('id', FeeChargeFunding::query()->where('fee_charge_id', $cobro->id)->whereNull('released_at')->select('fund_receipt_id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if (! $mismoImporte) {
                $this->assertImporteCorregible($recepciones->pluck('id')->all());
            }

            $hoy = BusinessDate::today();
            $clave = 'arancel-correccion-'.$cobro->id;

            $this->fondos->release($cobro, $motivo, $actorId);
            VoidFeeCollection::anularRecibos($cobro, $motivo, $actorId);
            VoidFeeCollection::marcarAnulado($cobro, $motivo, $actorId);

            $nuevo = $this->cobrar->crear($payerName, $payerCuit, $items, $cobro->pricing_date, $hoy, $clave, $actorId);

            if ($mismoImporte) {
                $eventos = [];

                foreach (FeeChargeFunding::query()->where('fee_charge_id', $cobro->id)->orderBy('id')->get() as $vinculo) {
                    $recepcion = $recepciones->firstWhere('id', $vinculo->fund_receipt_id);
                    $this->fondos->apply($nuevo, $recepcion, $vinculo->amount, $hoy, $actorId);
                    // El renglón del libro sigue en el día en que entró el dinero.
                    $eventos[] = $recepcion->financial_event_id;
                }
            } else {
                foreach ($recepciones as $recepcion) {
                    $this->fondos->reverseReceipt($recepcion, $clave.':recepcion-'.$recepcion->id, $motivo, $actorId);
                }

                $recepcion = $this->recibir->handle(
                    amount: $total,
                    idempotencyKey: $clave.':recepcion',
                    cashBoxId: $this->fondos->cashBoxId(),
                    receivedDate: $hoy,
                    medium: PaymentMedium::Cash,
                    actorId: $actorId,
                    notes: 'Arancel de '.$payerName.', corrige el cobro '.$cobro->id,
                    currency: Currency::Ars,
                );

                $this->fondos->apply($nuevo, $recepcion, $total, $hoy, $actorId);
                $eventos = [$recepcion->financial_event_id];
            }

            $comprobante = $this->emitir->handle(
                $nuevo,
                CollectFeeInCash::papel($payerName, $payerCuit, $items),
                $total,
                $hoy,
                array_values(array_unique($eventos)),
                null,
                $signedById,
                $actorId,
            );

            FeeChargeCorrection::query()->create([
                'corrected_charge_id' => $cobro->id,
                'new_charge_id' => $nuevo->id,
                'reason_code' => $motivo->code->value,
                'reason' => $motivo->detail === null ? null : mb_substr($motivo->detail, 0, 300),
                'corrected_by' => $actorId,
                'corrected_at' => now(),
            ]);

            $this->auditar->handle('arancel.cobro-corregido', $nuevo, metadata: [
                'anterior' => $totalAnterior,
                'amount' => $total,
                'recibo' => $comprobante->receipt()->value('formatted_number'),
                ...$motivo->auditMetadata(),
            ], actorId: $actorId);

            return $nuevo;
        });
    }

    /**
     * @param  array<int|string, mixed>  $recepciones
     *
     * @throws ValidationException
     */
    private function assertImporteCorregible(array $recepciones): void
    {
        foreach ($recepciones as $id) {
            if ($this->fondos->isCredited((int) $id)) {
                throw ValidationException::withMessages([
                    'items' => 'Ese efectivo ya se acreditó en el banco: un cambio de importe no tiene corrección todavía. Hay que definirlo con el área.',
                ]);
            }

            if ($this->fondos->isDeposited((int) $id)) {
                throw ValidationException::withMessages([
                    'items' => 'Ese efectivo está en un traspaso sin acreditar: cancelá el traspaso y corregí el cobro.',
                ]);
            }
        }
    }
}
