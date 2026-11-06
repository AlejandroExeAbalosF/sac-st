<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Requests;

use App\Modules\Haberes\Enums\HaberesReasonAct;
use App\Modules\Shared\Support\Reason;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

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
        return Reason::rules(HaberesReasonAct::FundReceiptReversal, max: 250) + [
            'idempotencyKey' => ['required', 'string', 'max:120'],
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
            'idempotencyKey' => 'clave de idempotencia',
        ];
    }

    public function motivo(): Reason
    {
        return Reason::fromValidated($this->validated(), HaberesReasonAct::FundReceiptReversal)
            ?? throw new LogicException('El motivo es obligatorio y ya se validó.');
    }
}
