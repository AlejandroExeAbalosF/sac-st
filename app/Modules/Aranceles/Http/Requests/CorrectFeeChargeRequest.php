<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Http\Requests;

use App\Modules\Aranceles\Enums\FeeReasonAct;
use App\Modules\Shared\Support\Reason;
use LogicException;

/** Corregir un cobro: lo mismo que cobrar, más el motivo de la corrección. */
final class CorrectFeeChargeRequest extends CollectFeeRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $reglas = parent::rules();

        unset($reglas['receivedOn'], $reglas['talonarioNumber'], $reglas['idempotencyKey']);

        return [...$reglas, ...Reason::rules(FeeReasonAct::ChargeCorrection, max: 300)];
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
        return [...parent::attributes(), 'reasonCode' => 'motivo', 'reason' => 'detalle'];
    }

    public function reason(): Reason
    {
        return Reason::fromValidated($this->validated(), FeeReasonAct::ChargeCorrection)
            ?? throw new LogicException('El motivo es obligatorio y ya se validó.');
    }
}
