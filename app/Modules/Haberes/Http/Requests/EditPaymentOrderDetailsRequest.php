<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Lo accesorio de la Orden y su Pase, que sí se corrige.
 *
 * El destinatario y el párrafo de la nota son opcionales y vaciables: son
 * renglones administrativos, y borrar uno que ya no corresponde es una
 * corrección tan válida como escribirlo. **La foja no**: se exige al
 * emitir, así que dejarla vaciar por esta puerta sería la forma de tener
 * igual una Orden sin ella.
 *
 * Lo que compromete al organismo —importe, beneficiario, cuenta, número—
 * no está acá ni puede estarlo: lo protege un trigger de la base.
 */
final class EditPaymentOrderDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('ordenes.emitir') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cbuFolio.required' => 'La foja no se puede vaciar: la nota la cita y '
                .'el renglón OBS de la Orden la imprime.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * El OBS de la Orden no está acá porque no se recibe: lo
             * redacta `PaymentOrderObservation` desde esta misma foja
             * (D-003, §47). Aceptarlo por separado dejaría que el renglón
             * impreso citara una foja distinta de la que la nota cita.
             */
            'cbuFolio' => ['required', 'string', 'max:40'],
            'paseDestination' => ['nullable', 'string', 'max:160'],
            'paseNotes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
