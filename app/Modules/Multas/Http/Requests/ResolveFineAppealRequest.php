<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Cómo se resuelve la diferencia de un recurso, y por qué. */
final class ResolveFineAppealRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:apply,discard'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'talonarioNumber' => ['nullable', 'string', 'max:20'],
            'signedById' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'decision' => 'decisión',
            'reason' => 'motivo',
            'talonarioNumber' => 'número de talonario',
            'signedById' => 'firmante',
        ];
    }
}
