<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Requests;

use App\Modules\Haberes\Enums\ExpectedMedium;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Campos editables de una cuota; sus invariantes se validan bajo lock en el Action. */
final class SaveInstallmentRequest extends FormRequest
{
    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999999999.99'],
            'managementLabelId' => [
                'nullable',
                'integer',
                Rule::exists('haber_management_labels', 'id')->where('is_active', true),
            ],
            'concept' => ['nullable', 'string', 'max:255'],
            'dueDate' => ['nullable', 'date'],
            /*
             * Obligatorio también acá, y no solo en el alta: si la
             * corrección lo aceptara vacío, la puerta de atrás deshace lo
             * que el alta exige.
             */
            'expectedMedium' => ['required', Rule::enum(ExpectedMedium::class)],
            'notes' => ['nullable', 'string', 'max:1000'],
            'returnTo' => ['nullable', Rule::in(['expediente', 'haber'])],
            'version' => [
                Rule::requiredIf($this->route('installment') !== null),
                'nullable',
                'date',
            ],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'amount' => 'importe',
            'managementLabelId' => 'etiqueta de gestión',
            'concept' => 'concepto propio',
            'dueDate' => 'vencimiento',
            'expectedMedium' => 'medio previsto',
            'notes' => 'observaciones',
            'returnTo' => 'pantalla de regreso',
            'version' => 'versión de la cuota',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'managementLabelId.exists' => 'Esa etiqueta no existe o está dada de baja.',
            'version.required' => 'Recargá la página antes de editar esta cuota.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['managementLabelId', 'concept', 'dueDate', 'expectedMedium', 'notes'] as $field) {
            if ($this->input($field) === '') {
                $normalized[$field] = null;
            }
        }

        $this->merge($normalized);
    }
}
