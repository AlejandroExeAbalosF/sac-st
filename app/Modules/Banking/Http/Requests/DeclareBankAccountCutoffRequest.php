<?php

declare(strict_types=1);

namespace App\Modules\Banking\Http\Requests;

use App\Support\Validation\ScannedDocument;
use Illuminate\Foundation\Http\FormRequest;

/**
 * El corte de una cuenta compartida: el último día incluido y el saldo que
 * informó el banco, con su comprobante si lo hay.
 */
final class DeclareBankAccountCutoffRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cutoffOn' => ['required', 'date_format:Y-m-d'],
            // Puede ser negativo: es lo que dice el banco.
            'statementBalance' => ['required', 'string', 'regex:/^-?\d{1,17}(\.\d{1,2})?$/'],
            'notes' => ['nullable', 'string', 'max:300'],
            'evidence' => ScannedDocument::rules(required: false),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'cutoffOn' => 'último día incluido',
            'statementBalance' => 'saldo según el banco',
            'notes' => 'nota',
            'evidence' => 'comprobante',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'statementBalance.regex' => 'Escribí el saldo con hasta dos decimales, con signo menos si es negativo.',
            ...ScannedDocument::messages('evidence'),
        ];
    }
}
