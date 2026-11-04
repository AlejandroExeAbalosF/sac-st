<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Http\Requests;

use App\Modules\Aranceles\Enums\FeeCalculationMode;
use App\Modules\Aranceles\Models\FeeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y corrección de un tipo de arancel.
 *
 * El alta lleva además el modo y el primer precio; la corrección no: el modo no
 * cambia y un precio nuevo tiene su propio formulario.
 */
final class SaveFeeTypeRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $letra = trim((string) $this->input('decreeLetter'));
        $unidad = trim((string) $this->input('unitLabel'));
        $referencia = trim((string) $this->input('legalReference'));

        $this->merge([
            'name' => trim((string) $this->input('name')),
            'decreeLetter' => $letra === '' ? null : mb_strtolower($letra),
            'unitLabel' => $unidad === '' ? null : $unidad,
            'legalReference' => $referencia === '' ? null : $referencia,
            'value' => str_replace(',', '.', trim((string) $this->input('value'))),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tipo = $this->route('feeType');
        $id = $tipo instanceof FeeType ? $tipo->id : null;

        $reglas = [
            'code' => ['required', 'integer', 'min:1', 'max:32767', Rule::unique('fee_types', 'code')->ignore($id)],
            'decreeLetter' => ['nullable', 'string', 'regex:/^[a-z]{1,2}$/', Rule::unique('fee_types', 'decree_letter')->ignore($id)],
            'name' => ['required', 'string', 'max:300'],
            'unitLabel' => ['nullable', 'string', 'max:40'],
        ];

        if ($id !== null) {
            return [...$reglas, 'isActive' => ['required', 'boolean']];
        }

        return [
            ...$reglas,
            'calculationMode' => ['required', Rule::enum(FeeCalculationMode::class)],
            'validFrom' => ['required', 'date_format:Y-m-d'],
            'value' => $this->input('calculationMode') === FeeCalculationMode::Percentage->value
                ? ['required', 'numeric', 'gt:0', 'lte:100', 'decimal:0,4']
                : ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:99999999999999999'],
            'legalReference' => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'Ya hay un tipo de arancel con ese código.',
            'decreeLetter.unique' => 'Ya hay un tipo de arancel con ese inciso.',
            'decreeLetter.regex' => 'El inciso es una o dos letras, como en el decreto.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'code' => 'código',
            'decreeLetter' => 'inciso',
            'name' => 'concepto',
            'unitLabel' => 'unidad',
            'calculationMode' => 'modo de cálculo',
            'validFrom' => 'vigente desde',
            'value' => 'valor',
            'legalReference' => 'referencia legal',
        ];
    }
}
