<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Requests;

use App\Modules\Haberes\Enums\HaberesReasonAct;
use App\Modules\Shared\Support\Reason;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * Reactivación de un expediente anulado.
 *
 * El motivo se elige de la lista y el detalle es opcional, salvo con «Otro»:
 * explica por qué vuelve a estar vigente.
 */
final class ReactivateExpedienteRequest extends FormRequest
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
        return HaberesReasonAct::Reactivation;
    }
}
