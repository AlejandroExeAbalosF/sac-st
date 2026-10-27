<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Requests;

use App\Modules\Haberes\Enums\HaberesReasonAct;
use App\Modules\Shared\Support\Reason;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * Anulación o reactivación de un haber.
 *
 * Las dos piden un motivo de la lista de su acto, con detalle opcional salvo
 * con «Otro». Es lo único que va a quedar para entender, dentro de dos años,
 * por qué ese beneficiario figura anulado dentro de un expediente vigente.
 */
final class CancelHaberRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return Reason::rules($this->act());
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['reasonCode' => 'motivo', 'reason' => 'detalle'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return Reason::messages();
    }

    public function reason(): Reason
    {
        return Reason::fromValidated($this->validated(), $this->act())
            ?? throw new LogicException('El motivo es obligatorio y ya se validó.');
    }

    public function act(): HaberesReasonAct
    {
        return $this->routeIs('*haber.reactivate')
            ? HaberesReasonAct::Reactivation
            : HaberesReasonAct::Cancellation;
    }
}
