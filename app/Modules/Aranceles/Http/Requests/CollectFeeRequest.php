<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Http\Requests;

use App\Support\BusinessDate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Un cobro en efectivo. El importe de cada renglón es opcional: sin él vale lo
 * calculado, y el Action decide si el cambio tiene permiso y motivo.
 */
class CollectFeeRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'payerName' => trim((string) $this->input('payerName')),
            'payerCuit' => preg_replace('/\D+/', '', (string) $this->input('payerCuit')) ?: null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'payerName' => ['required', 'string', 'max:200'],
            'payerCuit' => ['nullable', 'digits:11'],
            'receivedOn' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.BusinessDate::today()->toDateString()],
            'talonarioNumber' => ['nullable', 'string', 'max:20'],
            'signedById' => ['nullable', 'integer', 'exists:users,id'],
            'idempotencyKey' => [$this->isMethod('GET') ? 'nullable' : 'required', 'string', 'max:90'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.feeTypeId' => ['required', 'integer'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'items.*.baseAmount' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2', 'max:99999999999999999'],
            'items.*.amount' => ['nullable', 'numeric', 'decimal:0,2', 'max:99999999999999999'],
            'items.*.reasonCode' => ['nullable', 'string', 'max:30'],
            'items.*.reason' => ['nullable', 'string', 'max:300'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'payerName' => 'quién paga',
            'payerCuit' => 'CUIT',
            'receivedOn' => 'fecha del cobro',
            'talonarioNumber' => 'número de talonario',
            'signedById' => 'firma',
            'idempotencyKey' => 'clave del cobro',
            'items' => 'aranceles',
            'items.*.feeTypeId' => 'arancel',
            'items.*.quantity' => 'cantidad',
            'items.*.baseAmount' => 'monto',
            'items.*.amount' => 'importe',
            'items.*.reasonCode' => 'motivo',
            'items.*.reason' => 'detalle',
        ];
    }
}
