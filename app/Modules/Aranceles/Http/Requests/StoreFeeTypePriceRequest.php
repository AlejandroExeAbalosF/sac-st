<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Http\Requests;

use App\Modules\Aranceles\Enums\FeeCalculationMode;
use App\Modules\Aranceles\Models\FeeType;
use Illuminate\Foundation\Http\FormRequest;

/** Un precio nuevo: precio unitario o tasa, según el modo del tipo. */
final class StoreFeeTypePriceRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $referencia = trim((string) $this->input('legalReference'));

        $this->merge([
            'value' => str_replace(',', '.', trim((string) $this->input('value'))),
            'legalReference' => $referencia === '' ? null : $referencia,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tipo = $this->route('feeType');
        $esPorcentaje = $tipo instanceof FeeType && $tipo->calculation_mode === FeeCalculationMode::Percentage;

        return [
            'validFrom' => ['required', 'date_format:Y-m-d'],
            'value' => $esPorcentaje
                ? ['required', 'numeric', 'gt:0', 'lte:100', 'decimal:0,4']
                : ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:99999999999999999'],
            'legalReference' => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'validFrom' => 'vigente desde',
            'value' => 'valor',
            'legalReference' => 'referencia legal',
        ];
    }
}
