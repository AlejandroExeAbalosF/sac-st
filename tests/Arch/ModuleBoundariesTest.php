<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Fronteras entre módulos
|--------------------------------------------------------------------------
|
| La arquitectura del sistema es una dependencia dirigida, nunca lateral
| ni ascendente:
|
|     Haberes ──→ Banking ──→ Ledger ──→ Shared
|
| Shared no conoce a nadie. Ledger, que es el motor contable de doble
| partida, no puede saber que existen expedientes ni cuotas: eso es lo que
| permite que Aranceles y Multas lo reutilicen sin arrastrar el modelo
| jurídico de Haberes.
|
| Estas reglas no son documentación: fallan en CI.
|
*/

arch('Shared no depende de ningún otro módulo')
    ->expect('App\Modules\Shared')
    ->not->toUse([
        'App\Modules\Ledger',
        'App\Modules\Banking',
        'App\Modules\Haberes',
    ]);

arch('Ledger solo puede apoyarse en Shared')
    ->expect('App\Modules\Ledger')
    ->not->toUse([
        'App\Modules\Banking',
        'App\Modules\Haberes',
    ]);

arch('Banking no conoce el dominio de Haberes')
    ->expect('App\Modules\Banking')
    ->not->toUse('App\Modules\Haberes');

arch('Support es infraestructura sin dominio')
    ->expect('App\Support')
    ->not->toUse('App\Modules');

/*
|--------------------------------------------------------------------------
| Reglas del código
|--------------------------------------------------------------------------
*/

arch('todo el código de la aplicación declara strict_types')
    ->expect('App')
    ->toUseStrictTypes();

arch('sin funciones de depuración olvidadas')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die', 'exit'])
    ->not->toBeUsed();

arch('los Actions exponen una sola operación')
    ->expect('App\Modules\Shared\Actions')
    ->toHaveMethod('handle');

arch('los Enums del dominio están respaldados por string')
    ->expect('App\Modules\Haberes\Enums')
    ->toBeStringBackedEnums();

arch('los Enums de Banking están respaldados por string')
    ->expect('App\Modules\Banking\Enums')
    ->toBeStringBackedEnums();

arch('los modelos viven dentro de su módulo')
    ->expect('App\Modules\Haberes\Models')
    ->toExtend('Illuminate\Database\Eloquent\Model');

arch('los modelos de Banking son modelos de Eloquent')
    ->expect('App\Modules\Banking\Models')
    ->toExtend('Illuminate\Database\Eloquent\Model');

arch('los Actions de Banking exponen una sola operación')
    ->expect('App\Modules\Banking\Actions')
    ->toHaveMethod('handle');
