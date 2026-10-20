<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Requests;

use App\Support\Validation\ScannedDocument;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Un archivo para un expediente, una multa o un pago anterior: foto o PDF,
 * validado por lo que el archivo es y no por cómo se llama.
 */
final class StoreFineAttachmentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ScannedDocument::rules(required: true),
            'title' => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['file' => 'archivo', 'title' => 'descripción'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        // No siempre es un comprobante: puede ser una resolución o un acta.
        return ScannedDocument::messages('file', 'El archivo');
    }
}
