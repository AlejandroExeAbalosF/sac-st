<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Support;

/**
 * Se abrió el fajo de días anteriores y se lo contó.
 *
 * Es un acto excepcional. La rutina de todas las tardes es contar lo que
 * entró hoy y **declarar** el fajo sin tocarlo; acá se lo abre, y eso pasa
 * cuando se sospecha un faltante o cuando toca una verificación periódica.
 *
 * Va como objeto y no como tres parámetros sueltos porque los tres datos
 * son inseparables —la base los exige juntos con un `CHECK`— y porque un
 * recuento que no encuentra nada es un resultado legítimo: con la lista de
 * billetes vacía no habría forma de distinguirlo de «no se recontó».
 *
 * **Lo que el libro decía que había en el fajo no viaja acá.** Lo calcula el
 * Action, por lo mismo que el saldo teórico del arqueo: pedirle al operador
 * el número contra el que se lo compara sería pedirle que apruebe su propio
 * examen.
 */
final readonly class CarryRecount
{
    /**
     * @param  string  $reason  Por qué se abrió el fajo.
     * @param  array<int|string, int>  $denominations  Los billetes encontrados,
     *                                                 indexados por denominación.
     */
    public function __construct(
        public string $reason,
        public array $denominations = [],
    ) {}
}
