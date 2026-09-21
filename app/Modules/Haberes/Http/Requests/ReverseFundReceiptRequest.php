<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Deshacer una recepción exige decir por qué.
 *
 * No es prolijidad: el CHECK `financial_events_reversal_reason_check` ata
 * el motivo a toda reversión, y sin pedirlo acá el operador recibiría un
 * error de PostgreSQL en lugar de una frase. La regla ya existe abajo; esto
 * es hacerla legible.
 */
final class ReverseFundReceiptRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'idempotencyKey' => ['required', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Hace falta decir por qué se revierte esta recepción.',
            'reason.min' => 'El motivo tiene que explicar qué pasó, no alcanzan unas pocas letras.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'reason' => 'motivo',
            'idempotencyKey' => 'clave de idempotencia',
        ];
    }
}
