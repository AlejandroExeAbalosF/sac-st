<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reactivación de un expediente anulado.
 *
 * Pide motivo por el mismo criterio que la anulación: dentro de dos años,
 * un expediente que figura anulado y después activo necesita explicar las
 * dos cosas, no solo la primera.
 */
final class ReactivateExpedienteRequest extends FormRequest
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
            'reason.required' => 'Escribí el motivo de la reactivación.',
            'reason.min' => 'El motivo tiene que explicar por qué vuelve a estar vigente.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['reason' => trim((string) $this->input('reason'))]);
    }
}
