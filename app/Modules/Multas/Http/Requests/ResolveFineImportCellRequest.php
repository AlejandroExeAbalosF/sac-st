<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Un pago de la planilla posterior a la apertura: identificado o descartado, con una nota. */
final class ResolveFineImportCellRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'in:identified,dismissed'],
            'note' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['status' => 'estado', 'note' => 'nota'];
    }
}
