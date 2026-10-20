<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validar o descartar un antecedente. Descartarlo dice por qué.
 */
final class ReviewHistoricalMovementRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'in:validated,rejected'],
            'note' => ['required_if:status,rejected', 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['status' => 'resultado', 'note' => 'motivo'];
    }
}
