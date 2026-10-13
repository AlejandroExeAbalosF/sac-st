<?php

declare(strict_types=1);

namespace App\Modules\Shared\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Los roles del área, más uno que no es del área.
 *
 * Viven en un enum y no en una constante del seeder porque los lee también
 * la pantalla de usuarios —para ofrecerlos en el alta— y la de roles, y una
 * lista de roles duplicada en dos lados termina divergiendo.
 *
 * Que estén acá no los vuelve inmutables: los permisos de cada uno se
 * editan desde Configuración. Lo que es fijo es cuáles son.
 *
 * ── Por qué `super-admin` no es «administrador con más permisos» ───────
 *
 * No podría serlo: el administrador **ya recibe todos los permisos** por
 * `Gate::before`, así que un rol por encima con «todo» sería exactamente
 * igual de poderoso y no agregaría nada.
 *
 * Lo que lo distingue es al revés. Existen capacidades —hoy una sola,
 * forzar la verificación de un CBU— que el comodín del administrador
 * **deliberadamente no alcanza**, y este rol es el único que las recibe.
 * Son herramientas de desarrollo: sirven para atravesar el circuito con
 * datos inventados, no para operar.
 */
#[TypeScript]
enum SystemRole: string
{
    case SuperAdmin = 'super-admin';
    case Administrador = 'administrador';
    case Contador = 'contador';
    case Administrativo = 'administrativo';
    case Consulta = 'consulta';

    /**
     * El prefijo de las capacidades que sólo tiene `super-admin`.
     *
     * **Es una convención de nombre y no una lista, a propósito.** Estas
     * capacidades van a ser varias —pantallas a medio construir, funciones
     * en prueba, atajos para atravesar el circuito con datos inventados— y
     * una lista enumerada obliga a tocar el provider cada vez que aparece
     * una. Con el prefijo, crear una nueva es sólo nombrarla `dev.algo` y
     * sembrarla para este rol.
     *
     * De paso se lee sola: ver `can:dev.x` en una ruta dice al instante
     * que eso no es del área.
     */
    public const DEVELOPER_ABILITY_PREFIX = 'dev.';

    /**
     * Si esta capacidad está fuera del comodín del administrador.
     *
     * Si `Gate::before` se la concediera —como hace con todo lo demás—,
     * `super-admin` no se distinguiría en nada del administrador y el
     * control que envuelve sería decorativo.
     */
    public static function isDeveloperAbility(string $ability): bool
    {
        return str_starts_with($ability, self::DEVELOPER_ABILITY_PREFIX);
    }

    public function description(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Desarrollo: saltea controles para probar el circuito con datos inventados. '
                .'No es un rol operativo.',
            self::Administrador => 'Administra usuarios, roles y configuración del sistema.',
            self::Contador => 'Valida egresos, cierra períodos y autoriza anulaciones.',
            self::Administrativo => 'Carga expedientes, registra recepciones y emite comprobantes.',
            self::Consulta => 'Solo lectura sobre expedientes, comprobantes y reportes.',
        };
    }

    /**
     * Ni el administrador ni el super-admin se editan: los dos reciben lo
     * suyo por `Gate::before`, así que desmarcarles un permiso no les
     * quitaría nada y la pantalla estaría mintiendo.
     */
    /**
     * El orden en que se listan: de más a menos atribuciones, y al final el
     * de desarrollo, que no es del área.
     */
    public function displayOrder(): int
    {
        return match ($this) {
            self::Administrador => 1,
            self::Contador => 2,
            self::Administrativo => 3,
            self::Consulta => 4,
            self::SuperAdmin => 5,
        };
    }

    public function permissionsAreEditable(): bool
    {
        return $this->lockedReason() === null;
    }

    /**
     * Por qué la matriz de este rol no se edita, dicho para quien la mira.
     *
     * Es la misma regla que aplica `UpdateRolePermissions`: la pantalla y el
     * servidor leen este texto, así que no pueden contar cosas distintas.
     */
    public function lockedReason(): ?string
    {
        return match ($this) {
            self::Administrador => 'El administrador no pasa por esta matriz: el sistema le concede todo antes '
                .'de consultarla, incluidos los permisos que agreguen las fases futuras. Desmarcarle un '
                .'casillero daría la impresión de haberlo restringido sin haberlo hecho.',
            self::SuperAdmin => 'El super-admin no pasa por esta matriz: el sistema le concede todo antes de '
                .'consultarla, incluidas las capacidades de desarrollo que ni el administrador tiene. No es '
                .'un rol operativo y sus permisos no se restringen desde acá.',
            default => null,
        };
    }

    /**
     * @return array<string, string> rol => descripción
     */
    public static function catalog(): array
    {
        $catalog = [];

        foreach (self::cases() as $role) {
            $catalog[$role->value] = $role->description();
        }

        return $catalog;
    }
}
