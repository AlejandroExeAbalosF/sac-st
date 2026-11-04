<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Requests;

use App\Modules\Multas\Enums\FineReasonAct;
use App\Modules\Shared\Support\Reason;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * Corregir un pago verificado: a qué multa y con qué crédito, y por qué. El
 * importe no viaja: un pago es por el crédito entero.
 */
final class CorrectFineBankPaymentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...Reason::rules(FineReasonAct::PaymentCorrection),
            'fineId' => ['required', 'integer', 'exists:fines,id'],
            'bankTransactionId' => ['required', 'integer', 'exists:bank_transactions,id'],
            'talonarioNumber' => ['nullable', 'string', 'max:20'],
            'signedById' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return Reason::messages();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'reasonCode' => 'motivo',
            'reason' => 'detalle',
            'fineId' => 'multa',
            'bankTransactionId' => 'crédito',
        ];
    }

    public function reason(): Reason
    {
        return Reason::fromValidated($this->validated(), FineReasonAct::PaymentCorrection)
            ?? throw new LogicException('El motivo es obligatorio y ya se validó.');
    }
}
