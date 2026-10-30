<?php

declare(strict_types=1);

namespace App\Modules\Banking\Http\Requests;

use App\Support\BusinessDate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Cambiar desde cuándo vale una cuenta en la caja, con su motivo: vacío es
 * «inicio histórico desconocido».
 */
final class MoveCashBoxAccountStartRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'validFrom' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.BusinessDate::today()->toDateString()],
            'reason' => ['required', 'string', 'min:5', 'max:300'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'validFrom.before_or_equal' => 'La vigencia no empieza en una fecha futura.',
            'reason.required' => 'Contá por qué cambia la vigencia: habilita o deja afuera créditos de la cuenta.',
            'reason.min' => 'Contá por qué cambia la vigencia: habilita o deja afuera créditos de la cuenta.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['validFrom' => 'vigente desde', 'reason' => 'motivo'];
    }
}
