<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Un crédito viejo del banco como pago anterior: cuál es y de dónde se sabe
 * que fue de esta multa. Importe y fecha salen del crédito.
 */
final class StoreFineBankCreditPaymentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'bankTransactionId' => ['required', 'integer'],
            'evidence' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'bankTransactionId' => 'crédito del banco',
            'evidence' => 'respaldo',
        ];
    }
}
