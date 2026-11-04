<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Requests;

use App\Modules\Multas\Enums\FineReasonAct;
use App\Modules\Shared\Support\Reason;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * Revertir el veredicto: el motivo y cuál se estaba mirando. El Action rechaza
 * si ya no es el vigente.
 */
final class RevertFineAppealVerdictRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...Reason::rules(FineReasonAct::VerdictReversal),
            'verdictId' => ['required', 'integer'],
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
        return ['reasonCode' => 'motivo', 'reason' => 'detalle', 'verdictId' => 'veredicto'];
    }

    public function reason(): Reason
    {
        return Reason::fromValidated($this->validated(), FineReasonAct::VerdictReversal)
            ?? throw new LogicException('El motivo es obligatorio y ya se validó.');
    }
}
