<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Requests;

use App\Modules\Shared\Enums\SharedReasonAct;
use App\Modules\Shared\Support\Reason;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/** Eliminar un adjunto anulado exige el motivo, de la lista del acto. */
final class PurgeAttachmentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return Reason::rules(SharedReasonAct::PurgeAttachment);
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
        return Reason::fromValidated($this->validated(), SharedReasonAct::PurgeAttachment)
            ?? throw new LogicException('El motivo es obligatorio y ya se validó.');
    }
}
