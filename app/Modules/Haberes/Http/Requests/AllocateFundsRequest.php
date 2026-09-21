<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AllocateFundsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('recepciones.asignar') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'installmentId' => ['required', 'integer', 'exists:beneficiary_installments,id'],
            'amount' => ['required', 'string', 'regex:/^\d{1,17}(\.\d{1,2})?$/'],
            'notes' => ['nullable', 'string', 'max:500'],
            'idempotencyKey' => ['required', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.regex' => 'El importe tiene que ser un número con hasta dos decimales.',
            'installmentId.exists' => 'La cuota no existe.',
        ];
    }
}
