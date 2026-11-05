<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Support;

use App\Modules\Aranceles\Enums\FeeCalculationMode;
use App\Modules\Aranceles\Enums\FeeReasonCode;
use App\Modules\Aranceles\Models\FeeType;
use App\Support\Money\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Convierte lo cargado en renglones: el precio lo pone el servidor, según la
 * fecha de tarifa, nunca el navegador (regla A27).
 *
 * El importe se calcula. Si se escribe otro, pide el permiso y un motivo de la
 * lista (A24, A25). Lo mismo decide la base con sus `CHECK`.
 *
 * @phpstan-type Linea array{feeTypeId: int|string, quantity?: int|string|null, baseAmount?: string|null, amount?: string|null, reasonCode?: string|null, reason?: string|null}
 * @phpstan-type Renglon array{line_number: int, fee_type_id: int, fee_type_price_id: int, code_snapshot: int, name_snapshot: string, calculation_mode_snapshot: FeeCalculationMode, unit_label_snapshot: string|null, quantity: int|null, unit_price: string|null, rate: string|null, base_amount: string|null, calculated_amount: string, original_amount: string, change_reason_code: string|null, change_reason: string|null}
 */
final class FeeLineCalculator
{
    /**
     * @param  list<Linea>  $lineas
     * @return array{items: list<Renglon>, total: numeric-string}
     *
     * @throws ValidationException
     */
    public function build(array $lineas, CarbonInterface $fechaDeTarifa, bool $puedeCambiarImporte): array
    {
        if ($lineas === []) {
            throw ValidationException::withMessages(['items' => 'Agregá al menos un arancel.']);
        }

        $tipos = FeeType::query()->whereIn('id', array_map(fn (array $l): int => (int) $l['feeTypeId'], $lineas))->get()->keyBy('id');
        $renglones = [];
        $total = '0.00';

        foreach ($lineas as $indice => $linea) {
            $renglon = $this->renglon($linea, $indice, $tipos->get((int) $linea['feeTypeId']), $fechaDeTarifa, $puedeCambiarImporte);
            $renglones[] = $renglon;
            $total = Decimal::add($total, $renglon['original_amount']);
        }

        return ['items' => $renglones, 'total' => $total];
    }

    /**
     * @param  Linea  $linea
     * @return Renglon
     *
     * @throws ValidationException
     */
    private function renglon(array $linea, int $indice, ?FeeType $tipo, CarbonInterface $fecha, bool $puedeCambiarImporte): array
    {
        $campo = "items.{$indice}";

        if ($tipo === null || ! $tipo->is_active) {
            throw ValidationException::withMessages(["{$campo}.feeTypeId" => 'Ese arancel no está disponible para cobros nuevos.']);
        }

        $precio = $tipo->priceOn($fecha)
            ?? throw ValidationException::withMessages(["{$campo}.feeTypeId" => 'Ese arancel no tenía precio el '.$fecha->format('d/m/Y').'.']);

        $esUnitario = $tipo->calculation_mode === FeeCalculationMode::Unit;
        $cantidad = null;
        $base = null;

        if ($esUnitario) {
            $cantidad = (int) ($linea['quantity'] ?? 0);

            if ($cantidad < 1) {
                throw ValidationException::withMessages(["{$campo}.quantity" => 'La cantidad es un número entero mayor que cero.']);
            }

            $calculado = Decimal::scale(Decimal::times((string) $precio->unit_price, $cantidad));
        } else {
            $crudo = trim((string) ($linea['baseAmount'] ?? ''));

            if ($crudo === '' || ! is_numeric($crudo) || bccomp($crudo, '0', 2) <= 0) {
                throw ValidationException::withMessages(["{$campo}.baseAmount" => 'Cargá el monto sobre el que se calcula, mayor que cero.']);
            }

            $base = Decimal::scale($crudo);
            $calculado = Decimal::percentOf($base, (string) $precio->rate);
        }

        $escrito = trim((string) ($linea['amount'] ?? ''));
        $importe = $escrito === '' ? $calculado : Decimal::scale($escrito);
        $codigo = null;
        $detalle = null;

        if (Decimal::isNegative($importe) || Decimal::equals($importe, '0')) {
            throw ValidationException::withMessages(["{$campo}.amount" => Decimal::equals($calculado, '0')
                ? 'El cálculo da $ 0,00: escribí el importe a cobrar, con su motivo.'
                : 'El importe tiene que ser mayor que cero.']);
        }

        if (! Decimal::equals($importe, $calculado)) {
            if (! $puedeCambiarImporte) {
                throw ValidationException::withMessages(["{$campo}.amount" => 'No tenés permiso para cobrar un importe distinto del calculado.']);
            }

            [$codigo, $detalle] = $this->motivo($linea, $campo);
        }

        return [
            'line_number' => $indice + 1,
            'fee_type_id' => $tipo->id,
            'fee_type_price_id' => $precio->id,
            'code_snapshot' => $tipo->code,
            'name_snapshot' => $tipo->name,
            'calculation_mode_snapshot' => $tipo->calculation_mode,
            'unit_label_snapshot' => $tipo->unit_label,
            'quantity' => $cantidad,
            'unit_price' => $precio->unit_price,
            'rate' => $precio->rate,
            'base_amount' => $base,
            'calculated_amount' => $calculado,
            'original_amount' => $importe,
            'change_reason_code' => $codigo,
            'change_reason' => $detalle,
        ];
    }

    /**
     * @param  Linea  $linea
     * @return array{0: string, 1: string|null}
     *
     * @throws ValidationException
     */
    private function motivo(array $linea, string $campo): array
    {
        $codigo = FeeReasonCode::tryFrom((string) ($linea['reasonCode'] ?? ''));

        if ($codigo === null || ! in_array($codigo, [FeeReasonCode::Rounding, FeeReasonCode::Exception, FeeReasonCode::Other], true)) {
            throw ValidationException::withMessages(["{$campo}.reasonCode" => 'El importe es distinto del calculado: elegí el motivo.']);
        }

        $detalle = trim((string) ($linea['reason'] ?? ''));

        if (in_array($codigo, [FeeReasonCode::Exception, FeeReasonCode::Other], true) && $detalle === '') {
            throw ValidationException::withMessages(["{$campo}.reason" => 'Con este motivo, contá el detalle.']);
        }

        return [$codigo->value, $detalle === '' ? null : mb_substr($detalle, 0, 300)];
    }
}
