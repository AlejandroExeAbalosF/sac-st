<?php

declare(strict_types=1);

use App\Modules\Haberes\Pdf\FormAccountNumber;

/*
|--------------------------------------------------------------------------
| El número de cuenta como lo escribe el formulario
|--------------------------------------------------------------------------
|
| El recorte no es cosmético: la columna «CTA. CTE.» tiene 17,2 mm útiles y
| los quince dígitos que guarda el sistema necesitan veinticuatro. Antes de
| recortar, el número se derramaba sobre la columna del importe y los dos
| quedaban ilegibles.
|
| Lo que se prueba acá es que se recorte por la cola —los primeros dígitos
| son entidad y sucursal, iguales en todas las cuentas del organismo— y que
| un número más corto que el corte salga intacto.
|
*/

test('escribe la cola del número, que es lo que el papel anota', function (): void {
    expect(FormAccountNumber::acortar('310000123456789', FormAccountNumber::EN_LOS_DEPOSITOS))
        ->toBe('23456789')
        ->and(FormAccountNumber::acortar('310000123456789', FormAccountNumber::EN_LA_FICHA))
        ->toBe('0123456789');
});

test('un número que ya entra sale tal cual, con su formato', function (): void {
    expect(FormAccountNumber::acortar('23456789', FormAccountNumber::EN_LOS_DEPOSITOS))
        ->toBe('23456789')
        ->and(FormAccountNumber::acortar('3100-0941', FormAccountNumber::EN_LA_FICHA))
        ->toBe('3100-0941');
});

test('cuenta dígitos, no caracteres: un separador no corre el corte', function (): void {
    // Nueve dígitos con guiones: el corte a ocho tiene que dar los últimos
    // ocho dígitos, no los últimos ocho caracteres —que serían «0-941-2».
    expect(FormAccountNumber::acortar('3100-0941-2', FormAccountNumber::EN_LOS_DEPOSITOS))
        ->toBe('10009412');
});

test('sin cuenta no inventa una', function (): void {
    expect(FormAccountNumber::acortar(null, FormAccountNumber::EN_LOS_DEPOSITOS))->toBeNull();
});
