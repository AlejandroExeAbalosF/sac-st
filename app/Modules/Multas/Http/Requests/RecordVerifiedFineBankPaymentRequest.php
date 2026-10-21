<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Un crédito del extracto que se identifica como el pago de la multa: se
 * informa y se verifica en el mismo acto, por el importe del crédito.
 */
final class RecordVerifiedFineBankPaymentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'medium' => ['required', 'in:bank_transfer,direct_deposit,echeq'],
            'bankTransactionId' => ['required', 'integer'],
            'judicialReference' => ['nullable', 'string', 'max:120'],
            'talonarioNumber' => ['nullable', 'string', 'max:20'],
            'signedById' => ['nullable', 'integer', 'exists:users,id'],
            'idempotencyKey' => ['required', 'string', 'max:90'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'medium' => 'medio',
            'bankTransactionId' => 'crédito del banco',
            'judicialReference' => 'orden de pago judicial',
            'talonarioNumber' => 'número de talonario',
            'signedById' => 'firmante',
        ];
    }
}
