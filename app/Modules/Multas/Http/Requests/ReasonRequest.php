<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Un acto que solo pide el motivo: revertir un ajuste, desvincular un
 * documento.
 *
 * El motivo es lo único que va a quedar para entender, dentro de un tiempo,
 * por qué ese ajuste figura revertido o ese documento desvinculado.
 */
final class ReasonRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['reason' => 'motivo'];
    }
}
