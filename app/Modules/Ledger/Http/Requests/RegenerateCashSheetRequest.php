<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Lo único que hace falta para rehacer una planilla: decir por qué.
 *
 * El Action lo vuelve a exigir con el mismo largo mínimo. Acá se gana el
 * mensaje al lado del campo en vez de un error suelto arriba del
 * formulario.
 */
final class RegenerateCashSheetRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:300'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['reason' => 'motivo'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.min' => 'El motivo tiene que explicar por qué se rehace una planilla ya emitida: '
                .'un par de palabras no alcanzan para entenderlo dentro de un año.',
        ];
    }
}
