<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Requests;

use App\Modules\Shared\Enums\AttachmentSubject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** De qué se pide el historial de archivos: el sujeto y, si hace falta, el tipo. */
final class AttachmentHistoryRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'subject' => ['required', Rule::enum(AttachmentSubject::class)],
            'id' => ['required', 'integer', 'min:1'],
            'type' => ['nullable', 'string', 'max:60'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['subject' => 'tipo de registro', 'id' => 'registro', 'type' => 'tipo de documento'];
    }
}
