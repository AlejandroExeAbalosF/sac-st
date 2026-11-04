<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Http\Requests;

use App\Modules\Aranceles\Enums\FeeReasonAct;
use App\Modules\Shared\Support\Reason;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/** Por qué se anula un cobro: el dinero nunca entró. */
final class VoidFeeCollectionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return Reason::rules(FeeReasonAct::CollectionVoid, max: 300);
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
        return ['reasonCode' => 'motivo', 'reason' => 'detalle'];
    }

    public function reason(): Reason
    {
        return Reason::fromValidated($this->validated(), FeeReasonAct::CollectionVoid)
            ?? throw new LogicException('El motivo es obligatorio y ya se validó.');
    }
}
