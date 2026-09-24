<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Http\Requests;

use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Support\CarryRecount;
use App\Support\BusinessDate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lo que la pantalla del arqueo puede mandar.
 *
 * **El saldo teórico no está acá, y es deliberado.** Lo calcula el Action
 * desde `journal_lines`: si viajara desde el navegador, el operador estaría
 * poniendo el número contra el que se compara su propio conteo. Lo mismo
 * con el total contado, que sale de las denominaciones.
 *
 * Sin Zod del otro lado: los errores de este `FormRequest` llegan solos a
 * `useForm().errors`.
 */
final class RecordCashCountRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cashBoxId' => ['required', 'integer', Rule::exists('cash_boxes', 'id')->where('is_active', true)],
            'countedOn' => ['required', 'date', 'before_or_equal:'.BusinessDate::today()->toDateString()],
            'currency' => ['required', Rule::enum(Currency::class)],

            /*
             * Un arqueo sin ninguna denominación es válido: significa que
             * el cajón estaba vacío, y hay días así. Lo que no puede es
             * faltar la clave.
             */
            'denominations' => ['present', 'array'],
            'denominations.*' => ['integer', 'min:0', 'max:100000'],

            'explanation' => ['nullable', 'string', 'max:500'],

            /*
             * El recuento del fajo de días anteriores, cuando se lo abrió.
             * Viaja entero o no viaja: el motivo es obligatorio porque
             * abrirlo es excepcional, y la lista de billetes puede quedar
             * vacía —encontrar el fajo vacío es un resultado—.
             *
             * Lo que el libro decía que había ahí **no** se manda: lo
             * calcula el Action, igual que el saldo teórico.
             */
            'carryRecount' => ['sometimes', 'nullable', 'array'],
            'carryRecount.reason' => ['required_with:carryRecount', 'string', 'max:300'],
            'carryRecount.denominations' => ['required_with:carryRecount', 'array'],
            'carryRecount.denominations.*' => ['integer', 'min:0', 'max:100000'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'cashBoxId' => 'caja',
            'countedOn' => 'fecha del arqueo',
            'denominations' => 'conteo por denominación',
            'explanation' => 'explicación de la diferencia',
            'carryRecount.reason' => 'motivo del recuento',
            'carryRecount.denominations' => 'billetes del fajo',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'countedOn.before_or_equal' => 'No se puede arquear una caja de un día que todavía no pasó.',
            'denominations.*.max' => 'Cien mil billetes de una misma denominación es un error de tipeo, no un arqueo.',
            'carryRecount.reason.required_with' => 'Abrir el fajo de días anteriores exige decir por qué.',
        ];
    }

    /**
     * Las cantidades llegan como texto desde un formulario y las claves
     * como denominación. Se normalizan acá para que el Action reciba
     * enteros y no tenga que desconfiar del transporte.
     *
     * @return array<int, int>
     */
    public function denominations(): array
    {
        $conteo = [];

        /** @var array<array-key, mixed> $crudo */
        $crudo = $this->validated('denominations') ?? [];

        foreach ($crudo as $denominacion => $cantidad) {
            $conteo[(int) $denominacion] = (int) $cantidad;
        }

        return $conteo;
    }

    /**
     * El recuento del fajo, o null si en este arqueo no se lo abrió.
     *
     * Se arma acá y no en el controlador para que el Action reciba el
     * objeto que la base exige entero, y no tres datos sueltos que podrían
     * llegar de a uno.
     */
    public function carryRecount(): ?CarryRecount
    {
        /** @var array<string, mixed>|null $crudo */
        $crudo = $this->validated('carryRecount');

        if (! is_array($crudo)) {
            return null;
        }

        $billetes = [];

        /** @var array<array-key, mixed> $cantidades */
        $cantidades = $crudo['denominations'] ?? [];

        foreach ($cantidades as $denominacion => $cantidad) {
            $billetes[(int) $denominacion] = (int) $cantidad;
        }

        return new CarryRecount(
            reason: (string) ($crudo['reason'] ?? ''),
            denominations: $billetes,
        );
    }
}
