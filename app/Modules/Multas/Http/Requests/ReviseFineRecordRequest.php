<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Requests;

use App\Modules\Multas\Http\Requests\Rules\PartialDateRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Completar o corregir los datos normalizados de un expediente, un
 * documento, una multa, o la fecha de un movimiento o de un pago anterior.
 *
 * Qué datos admite depende de la ruta. Si el cambio resulta ser una
 * corrección —el dato ya tenía valor— el motivo pasa a ser obligatorio; eso
 * lo decide `ReviseFineIdentifiers`, que es quien sabe qué cambió.
 *
 * El formulario manda además `expected`: los mismos datos tal como los
 * mostraba al abrirse. Es lo que permite no pisar lo que otro operador
 * cambió mientras tanto.
 */
final class ReviseFineRecordRequest extends FormRequest
{
    /**
     * Completar es de quien carga; corregir, de quien ajusta. Alguno de los
     * dos hace falta para pedir la revisión.
     */
    public function authorize(): bool
    {
        return $this->canCorrect() || ($this->user()?->can('multas.registrar') ?? false);
    }

    /** Si la revisión resulta ser una corrección, quien la pide puede hacerla. */
    public function canCorrect(): bool
    {
        return $this->user()?->can('multas.ajustar') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $campos = $this->camposDeLaRuta();

        $reglas = [
            'reason' => ['nullable', 'string', 'max:500'],
            'expected' => ['required', 'array'],
        ];

        foreach ($campos as $campo => $regla) {
            $reglas[$campo] = $regla;
            // Lo que se vio pasa la misma validación que lo nuevo, salvo que
            // nunca es obligatorio: pudo estar vacío.
            $reglas["expected.{$campo}"] = ['present', ...array_values(array_filter(
                $regla,
                fn (mixed $parte): bool => $parte !== 'required',
            ))];
        }

        return $reglas;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'reason' => 'motivo',
            'number' => 'número',
            'year' => 'año',
            'suffix' => 'sufijo',
            'actType' => 'tipo de acta',
            'issuedOn' => 'fecha',
            'caption' => 'carátula',
            'personId' => 'persona',
            'registeredOn' => 'fecha de registro',
            'date' => 'fecha',
            'expected' => 'datos del formulario',
            'expected.*' => 'datos del formulario',
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function camposDeLaRuta(): array
    {
        return match (true) {
            $this->routeIs('multas.expedientes.revisiones.store') => [
                'number' => ['nullable', 'integer', 'min:1', 'max:999999999'],
                'year' => ['nullable', 'integer', 'between:1990,2100'],
                'suffix' => ['nullable', 'string', 'regex:/^[0-9A-Za-z]{1,10}$/'],
            ],
            $this->routeIs('multas.documentos.revisiones.store') => [
                'actType' => ['nullable', 'in:LAB,HYS'],
                'number' => ['nullable', 'integer', 'min:1', 'max:999999999'],
                'year' => ['nullable', 'integer', 'between:1990,2100'],
                'issuedOn' => ['nullable', new PartialDateRule],
            ],
            $this->routeIs('multas.movimientos.revisiones.store', 'multas.antecedentes.revisiones.store') => [
                'date' => ['nullable', new PartialDateRule],
            ],
            default => [
                'caption' => ['required', 'string', 'max:300'],
                'personId' => ['nullable', 'integer', 'exists:people,id'],
                'registeredOn' => ['nullable', new PartialDateRule],
            ],
        };
    }
}
