<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Anulación de un expediente.
 *
 * El motivo es obligatorio y con un mínimo que descarta el «error» de una
 * palabra: es lo único que va a quedar para explicar, dentro de dos años,
 * por qué ese expediente figura anulado.
 */
final class CancelExpedienteRequest extends FormRequest
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
            'reason.required' => 'Escribí el motivo de la anulación.',
            'reason.min' => 'El motivo tiene que explicar qué pasó: es lo único que va a quedar para entenderlo después.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'reason' => trim((string) $this->input('reason')),
        ]);
    }
}
