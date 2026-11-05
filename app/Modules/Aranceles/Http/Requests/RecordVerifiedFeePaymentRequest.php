<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Http\Requests;

/** Un crédito sin paso por ventanilla: lo que se cobró y con qué créditos. */
final class RecordVerifiedFeePaymentRequest extends CollectFeeRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $reglas = parent::rules();

        unset($reglas['receivedOn'], $reglas['talonarioNumber']);

        return [
            ...$reglas,
            'credits' => ['required', 'array', 'min:1', 'max:50'],
            'credits.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [...parent::attributes(), 'credits' => 'créditos'];
    }
}
