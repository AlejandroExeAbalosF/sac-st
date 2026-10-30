<?php

declare(strict_types=1);

namespace App\Modules\Banking\Http\Requests;

use App\Support\BusinessDate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Habilitar una cuenta para la caja: cuál, desde cuándo —vacío es «inicio
 * histórico desconocido»— y si pasa a ser la predeterminada.
 */
final class LinkCashBoxAccountRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'bankAccountId' => ['required', 'integer', 'exists:bank_accounts,id'],
            'validFrom' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.BusinessDate::today()->toDateString()],
            'makeDefault' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'validFrom.before_or_equal' => 'La vigencia no empieza en una fecha futura: cargala el día en que la cuenta empieza a usarse.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['bankAccountId' => 'cuenta', 'validFrom' => 'vigente desde'];
    }
}
