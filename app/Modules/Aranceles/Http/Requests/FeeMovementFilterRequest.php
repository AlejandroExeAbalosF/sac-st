<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Http\Requests;

use App\Modules\Aranceles\Enums\FeeChargeMedium;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Los filtros del informe del mes. Uno mal formado vuelve a la misma pantalla sin
 * filtros: `$redirectRoute` evita el ciclo de volver a una URL que vuelve a fallar.
 */
final class FeeMovementFilterRequest extends FormRequest
{
    protected $redirectRoute = 'aranceles.movimientos.index';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Sin el año cero, que PostgreSQL no admite como fecha.
            'mes' => ['nullable', 'date_format:Y-m', 'regex:/^[1-9]\d{3}-/'],
            'medio' => ['nullable', Rule::enum(FeeChargeMedium::class)],
            'tipo' => ['nullable', 'integer', 'exists:fee_types,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['mes' => 'mes', 'medio' => 'medio', 'tipo' => 'tipo de arancel'];
    }

    /** El mes pedido, el actual por defecto y nunca uno futuro. */
    public function month(): CarbonImmutable
    {
        $hoy = BusinessDate::today()->startOfMonth();
        $pedido = $this->validated('mes');

        if ($pedido === null) {
            return $hoy;
        }

        $mes = CarbonImmutable::createFromFormat('!Y-m', (string) $pedido);

        return $mes === null || $mes->greaterThan($hoy) ? $hoy : $mes;
    }

    /** @return array{medium: string|null, feeType: int|null} */
    public function filters(): array
    {
        $tipo = $this->validated('tipo');

        return [
            'medium' => $this->validated('medio') === null ? null : (string) $this->validated('medio'),
            'feeType' => $tipo === null ? null : (int) $tipo,
        ];
    }
}
