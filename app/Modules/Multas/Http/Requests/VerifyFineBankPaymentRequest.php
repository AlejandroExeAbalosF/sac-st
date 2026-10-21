<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Verificar un pago informado contra el crédito del extracto que lo trajo. */
final class VerifyFineBankPaymentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'bankTransactionId' => ['required', 'integer'],
            'talonarioNumber' => ['nullable', 'string', 'max:20'],
            'signedById' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'bankTransactionId' => 'crédito del banco',
            'talonarioNumber' => 'número de talonario',
            'signedById' => 'firmante',
        ];
    }
}
