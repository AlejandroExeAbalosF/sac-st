<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Anular el registro de un pago fuera del circuito.
 *
 * Deja la cuota pendiente otra vez, así que el motivo es lo que va a
 * explicar, el día que alguien pregunte, por qué una cuota que figuraba
 * pagada volvió a estar por pagar.
 */
final class VoidLegacySettlementRequest extends FormRequest
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
