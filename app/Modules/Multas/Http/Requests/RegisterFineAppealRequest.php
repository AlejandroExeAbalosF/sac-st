<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Requests;

use App\Support\BusinessDate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * El pago del 20 % para recurrir: el recurso, el medio y lo que dice el
 * recibo externo, que es el importe que se cobra. Puede corregir un depósito
 * informado que se cargó mal.
 */
final class RegisterFineAppealRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'in:hierarchical,appeal'],
            'medium' => ['required', 'in:cash,direct_deposit'],
            'externalReference' => ['nullable', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:99999999999999999'],
            'replacesPaymentId' => ['nullable', 'integer', 'exists:fine_payments,id'],
            'correctionReason' => ['required_with:replacesPaymentId', 'nullable', 'string', 'min:5', 'max:400'],
            'declaredPaidOn' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.BusinessDate::today()->toDateString()],
            'presentedOn' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.BusinessDate::today()->toDateString()],
            'presentationEvidence' => ['nullable', 'string', 'max:300'],
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
            'kind' => 'recurso',
            'medium' => 'medio',
            'externalReference' => 'recibo externo',
            'amount' => 'importe del recibo externo',
            'correctionReason' => 'motivo de la corrección',
            'declaredPaidOn' => 'fecha del depósito',
            'presentedOn' => 'fecha de presentación',
            'presentationEvidence' => 'respaldo de la presentación',
            'talonarioNumber' => 'número de talonario',
            'signedById' => 'firmante',
        ];
    }
}
