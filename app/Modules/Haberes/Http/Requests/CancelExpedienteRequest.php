<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Requests;

use App\Modules\Haberes\Enums\HaberesReasonAct;
use App\Modules\Shared\Support\Reason;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * Anulación de un expediente.
 *
 * El motivo se elige de la lista y el detalle es opcional, salvo con «Otro».
 * Es lo único que va a quedar para entender, dentro de dos años, por qué el
 * expediente figura anulado: queda en la auditoría, con su código.
 */
final class CancelExpedienteRequest extends FormRequest
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
        return HaberesReasonAct::Cancellation;
    }
}
