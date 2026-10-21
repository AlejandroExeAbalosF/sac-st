<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Requests;

use App\Support\BusinessDate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * El pago del 20 % para recurrir: el recurso, el medio y lo que dice el
 * recibo externo. El importe a cobrar no viaja: lo calcula el servidor.
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
            'externalReference' => ['required', 'string', 'max:120'],
            'externalAmount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:99999999999999999'],
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
            'externalAmount' => 'importe del recibo externo',
            'declaredPaidOn' => 'fecha del depósito',
            'presentedOn' => 'fecha de presentación',
            'presentationEvidence' => 'respaldo de la presentación',
            'talonarioNumber' => 'número de talonario',
            'signedById' => 'firmante',
        ];
    }
}
