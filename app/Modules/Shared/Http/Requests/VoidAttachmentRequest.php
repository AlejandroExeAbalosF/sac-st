<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Requests;

use App\Modules\Shared\Enums\SharedReasonAct;
use App\Modules\Shared\Support\Reason;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/** Anular un adjunto exige el motivo, de la lista del acto. */
final class VoidAttachmentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return Reason::rules(SharedReasonAct::VoidAttachment);
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
        return Reason::fromValidated($this->validated(), SharedReasonAct::VoidAttachment)
            ?? throw new LogicException('El motivo es obligatorio y ya se validó.');
    }
}
