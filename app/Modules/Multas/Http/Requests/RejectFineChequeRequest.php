<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Requests;

use App\Support\BusinessDate;
use App\Support\Validation\ScannedDocument;
use Illuminate\Foundation\Http\FormRequest;

/**
 * El rechazo de un cheque: el día, el motivo y su respaldo.
 *
 * El respaldo lo exige el Action, que sabe si el débito del extracto ya lo
 * reemplaza; acá solo se valida que, si llega, sea un documento.
 */
final class RejectFineChequeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rejectedOn' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.BusinessDate::today()->toDateString()],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'evidence' => ScannedDocument::rules(required: false),
            'debitTransactionId' => ['nullable', 'integer', 'exists:bank_transactions,id'],
            'idempotencyKey' => ['required', 'string', 'max:110'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rejectedOn.before_or_equal' => 'El rechazo no puede tener fecha futura.',
            ...ScannedDocument::messages('evidence'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'rejectedOn' => 'fecha del rechazo',
            'reason' => 'motivo',
            'evidence' => 'respaldo',
        ];
    }
}
