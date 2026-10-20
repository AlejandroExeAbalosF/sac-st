<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Los filtros de la cuenta corriente.
 *
 * Un filtro mal formado vuelve a la misma pantalla sin filtros, no a la
 * anterior: `$redirectRoute` evita el ciclo de volver a una URL que vuelve a
 * fallar.
 */
final class FineIndexRequest extends FormRequest
{
    protected $redirectRoute = 'multas.index';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],
            'estado' => ['nullable', 'in:con-deuda,sin-deuda'],
            'anio' => ['nullable', 'integer', 'between:1990,2100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'q' => 'búsqueda',
            'estado' => 'deuda',
            'anio' => 'año de registro',
            'page' => 'página',
        ];
    }

    /**
     * Lo mismo para la pantalla y para el exporte.
     *
     * @return array{q: string, estado: string, anio: int|null}
     */
    public function filters(): array
    {
        return [
            'q' => trim((string) $this->validated('q')),
            'estado' => (string) $this->validated('estado'),
            'anio' => $this->validated('anio') === null ? null : (int) $this->validated('anio'),
        ];
    }
}
