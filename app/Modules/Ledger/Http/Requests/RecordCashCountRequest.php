<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Http\Requests;

use App\Modules\Ledger\Enums\Currency;
use App\Support\Money\Decimal;
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
            'countedOn' => ['required', 'date', 'before_or_equal:today'],
            'currency' => ['required', Rule::enum(Currency::class)],

            /*
             * Un arqueo sin ninguna denominación es válido: significa que
             * el cajón estaba vacío, y hay días así. Lo que no puede es
             * faltar la clave.
             */
            'denominations' => ['present', 'array'],
            'denominations.*' => ['integer', 'min:0', 'max:100000'],

            'uncountedAmount' => ['nullable', 'numeric', 'min:0'],
            'uncountedReason' => ['nullable', 'string', 'max:300', 'required_with:uncountedAmount'],
            'explanation' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'cashBoxId' => 'caja',
            'countedOn' => 'fecha del arqueo',
            'denominations' => 'conteo por denominación',
            'uncountedAmount' => 'importe no recontado',
            'uncountedReason' => 'motivo de lo no recontado',
            'explanation' => 'explicación de la diferencia',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'countedOn.before_or_equal' => 'No se puede arquear una caja de un día que todavía no pasó.',
            'uncountedReason.required_with' => 'Declarar un importe sin recontar exige decir por qué no se contó.',
            'denominations.*.max' => 'Cien mil billetes de una misma denominación es un error de tipeo, no un arqueo.',
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
     * El importe no recontado, como cadena decimal.
     *
     * Nunca pasa por `float`, ni siquiera de ida y vuelta para
     * normalizarlo: la regla del modelo es que el punto flotante no existe
     * en ningún punto de la pila, y `739050.10` convertido y vuelto a
     * convertir es justamente donde aparecería el centavo perdido.
     *
     * @return numeric-string
     */
    public function uncountedAmount(): string
    {
        $importe = $this->validated('uncountedAmount');

        if ($importe === null || $importe === '') {
            return '0.00';
        }

        return Decimal::parse((string) $importe) ?? '0.00';
    }
}
