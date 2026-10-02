<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Requests;

use App\Modules\Ledger\Enums\PaymentMedium;
use App\Support\BusinessDate;
use App\Support\Validation\ScannedDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Apartar del saldo del sistema anterior la plata de una cuota.
 *
 * El importe no se pide para el efectivo ni para el depósito directo: es el
 * de la cuota, porque se pagan enteras. Solo se reparte cuando la plata
 * está en varios cheques, y ahí cada uno dice cuánto aporta. El recibo de
 * ingreso de papel no se marca obligatorio porque la cuota puede tenerlo
 * cargado de antes; lo decide el Action.
 */
final class FundInstallmentFromLegacyRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $hoy = BusinessDate::today()->toDateString();
        $cheque = PaymentMedium::Cheque->value;

        return [
            'medium' => ['required', Rule::enum(PaymentMedium::class)],
            'bankAccountId' => [
                'nullable',
                'required_if:medium,'.PaymentMedium::Bank->value,
                'integer',
                Rule::exists('bank_accounts', 'id')->where('is_active', true),
            ],
            'cheques' => ['nullable', 'required_if:medium,'.$cheque, 'array', 'min:1'],
            'cheques.*.receiptId' => ['required', 'integer', 'distinct'],
            'cheques.*.amount' => ['required', 'numeric', 'gt:0'],
            'incomeNumber' => ['nullable', 'string', 'max:40'],
            'incomeDate' => ['nullable', 'required_with:incomeNumber', 'date', 'before_or_equal:'.$hoy],
            'incomeAmount' => ['nullable', 'required_with:incomeNumber', 'numeric', 'gt:0'],
            'incomePhoto' => ScannedDocument::rules(required: false),
            'idempotencyKey' => ['required', 'string', 'max:100'],
            'confirmDuplicates' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'medium' => 'de dónde sale',
            'bankAccountId' => 'cuenta bancaria',
            'cheques' => 'cheques',
            'cheques.*.receiptId' => 'cheque',
            'cheques.*.amount' => 'importe del cheque',
            'incomeNumber' => 'número del recibo de ingreso',
            'incomeDate' => 'fecha del recibo de ingreso',
            'incomeAmount' => 'importe del recibo de ingreso',
            'incomePhoto' => 'foto del recibo de ingreso',
            'idempotencyKey' => 'clave de la operación',
            'confirmDuplicates' => 'confirmación',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'bankAccountId.required_if' => 'Un depósito directo tiene que decir en qué cuenta está.',
            'cheques.required_if' => 'Elegí de qué cheques de la cartera sale la plata.',
            'cheques.*.receiptId.distinct' => 'El mismo cheque está dos veces.',
            'incomeDate.required_with' => 'Falta la fecha del recibo.',
            'incomeDate.before_or_equal' => 'La fecha no puede ser futura.',
            'incomeAmount.required_with' => 'Falta el importe del recibo.',
            ...ScannedDocument::messages('incomePhoto'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $numero = trim((string) $this->input('incomeNumber'));

        $this->merge(['incomeNumber' => $numero === '' ? null : $numero]);
    }
}
