<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Anulación o reactivación de un haber.
 *
 * Las dos piden lo mismo: un motivo que explique qué pasó. Es lo único
 * que va a quedar para entender, dentro de dos años, por qué ese
 * beneficiario figura anulado dentro de un expediente vigente.
 */
final class CancelHaberRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['reason' => 'motivo'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Escribí el motivo.',
            'reason.min' => 'El motivo tiene que explicar qué pasó: es lo único que va a quedar para entenderlo después.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['reason' => trim((string) $this->input('reason'))]);
    }
}
