<?php

declare(strict_types=1);

namespace App\Modules\Banking\Http\Requests;

use App\Support\BusinessDate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Dar de baja una cuenta de la caja: el último día en que opera y, si era la
 * predeterminada, cuál la reemplaza.
 */
final class RetireCashBoxAccountRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'until' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.BusinessDate::today()->toDateString()],
            'newDefaultBankAccountId' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'until.before_or_equal' => 'La baja no se carga con fecha futura: es el último día en que la cuenta opera.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['until' => 'último día', 'newDefaultBankAccountId' => 'nueva predeterminada'];
    }
}
