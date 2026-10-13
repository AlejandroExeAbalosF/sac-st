<?php

declare(strict_types=1);

namespace App\Modules\Shared\Permissions;

/**
 * Qué es un permiso: su nombre técnico, cómo se le explica al área y a
 * quién se le otorga de fábrica.
 *
 * El nombre es lo que exige la ruta (`can:caja.arquear`) y no cambia. El
 * rótulo y la descripción son presentación: se corrigen sin tocar nada de
 * lo que ya se asignó.
 */
final readonly class PermissionDefinition
{
    /**
     * @param  string  $group  Un grupo registrado en el catálogo: un concepto del área, no un módulo.
     * @param  list<string>  $roles  Los roles que lo reciben en la matriz de fábrica.
     * @param  bool  $sensitive  Saca dinero del organismo, deshace o anula algo ya registrado, mueve
     *                           saldos sin dinero de por medio, cambia la configuración o abre datos
     *                           reservados. La pantalla lo distingue al otorgarlo.
     * @param  bool  $delegable  `false` si no se otorga desde la matriz: lo tienen solo los roles que reciben
     *                           todo por diseño. Es lo que impide que un rol se dé a sí mismo más permisos.
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $description,
        public string $group,
        public array $roles,
        public bool $sensitive = false,
        public bool $delegable = true,
    ) {}
}
