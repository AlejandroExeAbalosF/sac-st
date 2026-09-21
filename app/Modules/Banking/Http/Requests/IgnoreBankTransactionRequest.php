<?php

declare(strict_types=1);

namespace App\Modules\Banking\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Dejar un movimiento fuera del circuito exige explicar por qué.
 *
 * El mínimo de caracteres no es burocracia: «ok» o «no» dentro de seis
 * meses no le dicen nada a quien audite por qué ese débito nunca se
 * imputó. La base ya impide que el motivo falte; esto impide que sea
 * inútil.
 */
final class IgnoreBankTransactionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:300'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Explicá por qué este movimiento no entra en la contabilidad.',
            'reason.min' => 'El motivo tiene que decir algo: escribí al menos cinco caracteres.',
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
