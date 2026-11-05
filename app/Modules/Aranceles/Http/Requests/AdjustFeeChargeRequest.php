<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Http\Requests;

use App\Modules\Aranceles\Enums\FeeReasonAct;
use App\Modules\Shared\Support\Reason;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/** Los importes nuevos de un cobro pendiente, con su motivo. */
final class AdjustFeeChargeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.itemId' => ['required', 'integer'],
            'lines.*.amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:99999999999999999'],
            // Lo que el operador tenía a la vista: si cambió, otro ajustó mientras tanto.
            'lines.*.previousAmount' => ['required', 'numeric', 'decimal:0,2'],
            'signedById' => ['nullable', 'integer', 'exists:users,id'],
            ...Reason::rules(FeeReasonAct::AmountChange, max: 300),
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
        return ['lines.*.amount' => 'importe', 'reasonCode' => 'motivo', 'reason' => 'detalle', 'signedById' => 'firma'];
    }

    public function reason(): Reason
    {
        return Reason::fromValidated($this->validated(), FeeReasonAct::AmountChange)
            ?? throw new LogicException('El motivo es obligatorio y ya se validó.');
    }

    /** @return array<int, array{amount: string, previous: string}> por renglón */
    public function amounts(): array
    {
        $importes = [];

        foreach ((array) $this->validated('lines') as $linea) {
            $importes[(int) $linea['itemId']] = [
                'amount' => (string) $linea['amount'],
                'previous' => (string) $linea['previousAmount'],
            ];
        }

        return $importes;
    }
}
