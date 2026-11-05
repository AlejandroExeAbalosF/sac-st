<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Http\Requests;

/** El alta en ventanilla: lo mismo que un cobro, sin fecha, talonario ni firma. */
final class CreateFeeChargeRequest extends CollectFeeRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $reglas = parent::rules();

        unset($reglas['receivedOn'], $reglas['talonarioNumber'], $reglas['signedById']);

        return $reglas;
    }
}
