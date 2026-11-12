<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** La planilla histórica de Multas, como la lleva el área: un Excel. */
final class StoreFineImportRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // 32 MB: el mismo tope que el servidor web (`deploy/docker/php/php.ini`).
        return ['file' => ['required', 'file', 'mimes:xls,xlsx', 'max:32768']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Elegí la planilla.',
            'file.mimes' => 'La planilla tiene que ser un Excel (.xls o .xlsx).',
            'file.max' => 'La planilla supera los 32 MB.',
        ];
    }
}
