<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Http\Requests;

use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * El efectivo que sale de la caja: importe y cuenta. La foto va después.
 *
 * La fecha la toma el sistema, y el importe es todo lo disponible. Solo quien tiene
 * `aranceles.ajustar-traspaso` puede indicar otra cosa. A los demás se les rechaza:
 * ignorarlo registraría otra cosa que lo que mandaron. El importe se coteja en el
 * controlador, que es quien conoce lo disponible.
 */
final class StartFeeTransferRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'transferDate' => $this->user()?->can('aranceles.ajustar-traspaso')
                ? ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.BusinessDate::today()->toDateString()]
                : ['prohibited'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:99999999999999999'],
            // Sin elegir, la predeterminada de la caja; el Action la valida contra la fecha.
            'bankAccountId' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:500'],
            'idempotencyKey' => ['required', 'string', 'max:120'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'transferDate.before_or_equal' => 'La fecha del traspaso no puede ser futura.',
            'transferDate.prohibited' => 'La fecha del traspaso la toma el sistema.',
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'transferDate' => 'fecha del traspaso',
            'amount' => 'importe',
            'notes' => 'observaciones',
        ];
    }

    public function transferDate(): CarbonImmutable
    {
        $fecha = $this->validated('transferDate');

        return $fecha === null ? BusinessDate::today() : CarbonImmutable::parse((string) $fecha);
    }
}
