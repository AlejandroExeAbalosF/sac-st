<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Http\Requests;

use App\Modules\Aranceles\Enums\FeeReasonAct;
use App\Modules\Shared\Support\Reason;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/** Lo que cambia un recibo de reemplazo: nombre, CUIT o DNI y redacción. */
final class ReplaceFeeReceiptRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'payerName' => trim((string) $this->input('payerName')),
            'payerDocument' => preg_replace('/\D+/', '', (string) $this->input('payerDocument')) ?: null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'payerName' => ['required', 'string', 'max:200'],
            'payerDocument' => ['nullable', 'regex:/^(\d{6,8}|\d{11})$/'],
            'wording' => ['nullable', 'array'],
            'wording.*' => ['nullable', 'string', 'max:300'],
            'signedById' => ['nullable', 'integer', 'exists:users,id'],
            ...Reason::rules(FeeReasonAct::ReceiptReplacement, max: 300),
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
            'payerName' => 'quién paga',
            'payerDocument' => 'CUIT o DNI',
            'wording' => 'concepto',
            'wording.*' => 'concepto',
            'signedById' => 'firma',
            'reasonCode' => 'motivo',
            'reason' => 'detalle',
        ];
    }

    public function reason(): Reason
    {
        return Reason::fromValidated($this->validated(), FeeReasonAct::ReceiptReplacement)
            ?? throw new LogicException('El motivo es obligatorio y ya se validó.');
    }
}
