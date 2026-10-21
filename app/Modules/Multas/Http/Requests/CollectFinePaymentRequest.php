<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Requests;

use App\Support\BusinessDate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Un cobro en la caja: efectivo o cheque, por el importe que se recibe.
 *
 * La fecha solo va cuando se carga un cobro de un día anterior, y entonces
 * con el número del recibo de talonario que lo respalda: eso lo exige el
 * Action, que es quien sabe qué día es hoy para la caja.
 */
final class CollectFinePaymentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'medium' => ['required', 'in:cash,cheque'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:99999999999999999'],
            'receivedOn' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.BusinessDate::today()->toDateString()],
            // Como en Haberes: un número de talonario real tiene ocho dígitos.
            'talonarioNumber' => ['nullable', 'string', 'max:20'],
            'signedById' => ['nullable', 'integer', 'exists:users,id'],
            'judicialReference' => ['nullable', 'string', 'max:120'],
            'cheque' => ['nullable', 'array'],
            'cheque.number' => ['required_if:medium,cheque', 'nullable', 'string', 'max:50'],
            'cheque.bank' => ['nullable', 'string', 'max:120'],
            'cheque.issueDate' => ['nullable', 'date_format:Y-m-d'],
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
            'amount' => 'importe',
            'receivedOn' => 'fecha del cobro',
            'talonarioNumber' => 'número de talonario',
            'signedById' => 'firmante',
            'judicialReference' => 'orden de pago judicial',
            'cheque.number' => 'número de cheque',
            'cheque.bank' => 'banco',
            'cheque.issueDate' => 'fecha del cheque',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['receivedOn.before_or_equal' => 'Un cobro no puede tener fecha futura.'];
    }
}
