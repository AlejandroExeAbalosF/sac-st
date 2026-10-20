<?php

declare(strict_types=1);

namespace App\Modules\Multas\Http\Requests\Rules;

use App\Modules\Multas\Support\PartialDate;
use App\Support\BusinessDate;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Una fecha con precisión: «2025-07-14», «2025-07» o vacía.
 *
 * Nunca en el futuro: un pago o una resolución que todavía no pasó no se
 * registra. `$requireDay` es para las cargas manuales que la base exige con
 * día exacto.
 */
final readonly class PartialDateRule implements ValidationRule
{
    public function __construct(private bool $requireDay = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $fecha = PartialDate::parse(is_string($value) ? $value : null);

        if ($fecha === null) {
            $fail('Escribí la fecha completa o, si solo se sabe el mes, año y mes.');

            return;
        }

        if ($this->requireDay && $fecha->date === null) {
            $fail('Hace falta la fecha completa.');

            return;
        }

        if ($fecha->isAfter(BusinessDate::today())) {
            $fail('La fecha no puede ser posterior a hoy.');
        }
    }
}
