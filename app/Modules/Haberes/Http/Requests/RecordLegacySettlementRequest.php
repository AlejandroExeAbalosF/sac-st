<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Http\Requests;

use App\Modules\Ledger\Enums\PaymentMedium;
use App\Support\BusinessDate;
use App\Support\Validation\ScannedDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Que una cuota se pagó fuera del circuito, con los papeles que lo prueban.
 *
 * Cada papel son cuatro campos con el mismo prefijo —`income`, `order`,
 * `expense`—: número, fecha, importe y foto. El recibo de ingreso no se
 * marca obligatorio acá porque la cuota puede tenerlo cargado de antes; lo
 * decide el Action, que es quien sabe.
 */
final class RecordLegacySettlementRequest extends FormRequest
{
    private const array PAPELES = ['income', 'order', 'expense'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $hoy = BusinessDate::today()->toDateString();

        $reglas = [
            'paidOn' => ['required', 'date', 'before_or_equal:'.$hoy],
            'paymentMedium' => ['required', Rule::enum(PaymentMedium::class)],
            'notes' => ['nullable', 'string', 'max:500'],
            'confirmDuplicates' => ['boolean'],
        ];

        foreach (self::PAPELES as $papel) {
            $reglas[$papel.'Number'] = ['nullable', 'string', 'max:40'];
            $reglas[$papel.'Date'] = ['nullable', 'required_with:'.$papel.'Number', 'date', 'before_or_equal:'.$hoy];
            $reglas[$papel.'Amount'] = ['nullable', 'required_with:'.$papel.'Number', 'numeric', 'gt:0'];
            $reglas[$papel.'Photo'] = ScannedDocument::rules(required: false);
        }

        return $reglas;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $nombres = [
            'paidOn' => 'fecha del pago',
            'paymentMedium' => 'medio del pago',
            'notes' => 'observaciones',
            'confirmDuplicates' => 'confirmación',
        ];

        // Con su artículo: «la Orden de Pago», «el recibo de ingreso».
        foreach (['income' => 'del recibo de ingreso', 'order' => 'de la Orden de Pago', 'expense' => 'del recibo de egreso'] as $papel => $deQue) {
            $nombres[$papel.'Number'] = 'número '.$deQue;
            $nombres[$papel.'Date'] = 'fecha '.$deQue;
            $nombres[$papel.'Amount'] = 'importe '.$deQue;
            $nombres[$papel.'Photo'] = 'foto '.$deQue;
        }

        return $nombres;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $mensajes = [
            'paidOn.required' => 'Falta la fecha en que se le pagó al beneficiario.',
            'paymentMedium.required' => 'Falta cómo se le pagó.',
        ];

        foreach (self::PAPELES as $papel) {
            $mensajes[$papel.'Date.required_with'] = 'Falta la fecha del papel.';
            $mensajes[$papel.'Date.before_or_equal'] = 'La fecha no puede ser futura.';
            $mensajes[$papel.'Amount.required_with'] = 'Falta el importe del papel.';
            $mensajes = [...$mensajes, ...ScannedDocument::messages($papel.'Photo')];
        }

        return $mensajes;
    }

    protected function prepareForValidation(): void
    {
        $limpio = [];

        foreach (self::PAPELES as $papel) {
            $numero = trim((string) $this->input($papel.'Number'));
            $limpio[$papel.'Number'] = $numero === '' ? null : $numero;
        }

        $this->merge($limpio);
    }
}
