<?php

declare(strict_types=1);

namespace App\Modules\Shared\Permissions;

use App\Modules\Shared\Enums\SystemRole;
use LogicException;

/**
 * El único lugar donde se declara qué permisos existen y qué hace cada uno.
 *
 * Antes eran dos mapas que no se conocían: el seeder decía qué permisos
 * había y a quién se daban, y la pantalla de roles mostraba el nombre
 * técnico sin explicarlo, porque un diccionario aparte de traducciones
 * hubiera terminado diciendo una cosa mientras la ruta hacía otra. Acá el
 * permiso y su explicación se declaran juntos: no puede existir uno sin la
 * otra, y el seeder siembra exactamente lo que este catálogo dice.
 *
 * Las inconsistencias fallan al armarlo, no en la pantalla: un nombre
 * repetido, un grupo que nadie registró, un rol que no existe o una
 * capacidad de desarrollo ofrecida a alguien que no es `super-admin`.
 */
final class PermissionCatalog
{
    /** @var array<string, array{label: string, order: int}> */
    private array $groups = [];

    /** @var array<string, PermissionDefinition> */
    private array $permissions = [];

    public function registerGroup(string $key, string $label, int $order): void
    {
        if (isset($this->groups[$key])) {
            throw new LogicException("El grupo de permisos «{$key}» ya está registrado.");
        }

        $this->groups[$key] = ['label' => $label, 'order' => $order];
    }

    public function register(PermissionDefinition ...$definitions): void
    {
        foreach ($definitions as $definition) {
            $this->guard($definition);

            $this->permissions[$definition->name] = $definition;
        }
    }

    public function has(string $name): bool
    {
        return isset($this->permissions[$name]);
    }

    public function get(string $name): ?PermissionDefinition
    {
        return $this->permissions[$name] ?? null;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->permissions);
    }

    /**
     * Los permisos que no se otorgan desde la matriz.
     *
     * Las capacidades de desarrollo tampoco se otorgan, pero tienen su
     * propia regla —el prefijo `dev.`— y su propia guarda en la base.
     *
     * @return list<string>
     */
    public function nonDelegable(): array
    {
        return array_values(array_map(
            fn (PermissionDefinition $definition): string => $definition->name,
            array_filter($this->permissions, fn (PermissionDefinition $definition): bool => ! $definition->delegable),
        ));
    }

    /**
     * Lo que recibe un rol en la matriz de fábrica.
     *
     * @return list<string>
     */
    public function defaultsFor(SystemRole $role): array
    {
        return array_values(array_map(
            fn (PermissionDefinition $definition): string => $definition->name,
            array_filter(
                $this->permissions,
                fn (PermissionDefinition $definition): bool => in_array($role->value, $definition->roles, true),
            ),
        ));
    }

    /**
     * Los permisos agrupados, en el orden del circuito y, dentro de cada
     * grupo, en el orden en que se declararon.
     *
     * Las capacidades de desarrollo quedan afuera salvo que se pidan: la
     * pantalla de roles no las ofrece, porque ningún rol editable puede
     * recibirlas.
     *
     * @return list<array{key: string, label: string, permissions: list<PermissionDefinition>}>
     */
    public function grouped(bool $includeDeveloperAbilities = false): array
    {
        $buckets = [];

        foreach ($this->permissions as $definition) {
            if (! $includeDeveloperAbilities && SystemRole::isDeveloperAbility($definition->name)) {
                continue;
            }

            $buckets[$definition->group][] = $definition;
        }

        uksort($buckets, fn (string $a, string $b): int => $this->groups[$a]['order'] <=> $this->groups[$b]['order']);

        $result = [];

        foreach ($buckets as $key => $definitions) {
            $result[] = [
                'key' => $key,
                'label' => $this->groups[$key]['label'],
                'permissions' => $definitions,
            ];
        }

        return $result;
    }

    private function guard(PermissionDefinition $definition): void
    {
        $name = $definition->name;

        if (isset($this->permissions[$name])) {
            throw new LogicException("El permiso «{$name}» ya está registrado.");
        }

        if (! isset($this->groups[$definition->group])) {
            throw new LogicException("El permiso «{$name}» usa el grupo «{$definition->group}», que no está registrado.");
        }

        if (trim($definition->label) === '' || trim($definition->description) === '') {
            throw new LogicException("El permiso «{$name}» necesita un rótulo y una descripción.");
        }

        foreach ($definition->roles as $role) {
            if (SystemRole::tryFrom($role) === null) {
                throw new LogicException("El permiso «{$name}» se otorga a «{$role}», que no es un rol del sistema.");
            }
        }

        if (! $definition->delegable) {
            foreach ($definition->roles as $role) {
                if (SystemRole::from($role)->permissionsAreEditable()) {
                    throw new LogicException(
                        "El permiso «{$name}» no se delega y no puede darse de fábrica a «{$role}», que se edita desde la matriz.",
                    );
                }
            }
        }

        if (
            SystemRole::isDeveloperAbility($name)
            && array_diff($definition->roles, [SystemRole::SuperAdmin->value]) !== []
        ) {
            throw new LogicException("La capacidad de desarrollo «{$name}» solo puede otorgarse a super-admin.");
        }
    }
}
