<?php

declare(strict_types=1);

namespace App\Modules\Shared\Audit;

use App\Modules\Shared\Audit\References\PersonReference;
use App\Modules\Shared\Audit\Subjects\PersonBankAccountSubject;
use App\Modules\Shared\Audit\Subjects\PersonSubject;
use App\Modules\Shared\Audit\Subjects\RoleSubject;
use App\Modules\Shared\Audit\Subjects\UserSubject;
use App\Modules\Shared\Enums\AuditSeverity;

/**
 * Lo que Shared registra: el maestro de personas, sus cuentas bancarias,
 * los usuarios y los roles.
 */
final class SharedAuditCatalog implements AuditCatalogContributor
{
    public function register(AuditCatalog $catalog): void
    {
        $catalog->registerCategory('Personas', 60);
        $catalog->registerCategory('Configuración', 70);

        $catalog->registerActions(
            new AuditActionDefinition('persona.corregida', 'Se corrigió la ficha de la persona', 'Personas'),
            new AuditActionDefinition(
                'persona.datos-completados',
                'Se completaron datos de la persona',
                'Personas',
                metadata: ['origen' => 'Origen'],
            ),
            new AuditActionDefinition('cuenta-bancaria.verificada', 'Se verificó el CBU', 'Personas'),
            // Verificar salteando el cotejo contra la foja es la puerta
            // más directa a transferirle a la cuenta equivocada.
            new AuditActionDefinition(
                'cuenta-bancaria.verificada-forzando',
                'Se forzó la verificación del CBU',
                'Personas',
                AuditSeverity::Critical,
            ),
            new AuditActionDefinition('cuenta-bancaria.rechazada', 'Se rechazó el CBU', 'Personas'),
            new AuditActionDefinition('cuenta-bancaria.dada-de-baja', 'Se dio de baja la cuenta bancaria', 'Personas'),
            new AuditActionDefinition('cuenta-bancaria.reactivada', 'Se reactivó la cuenta bancaria', 'Personas'),

            new AuditActionDefinition('usuario.creado', 'Se creó el usuario', 'Configuración'),
            new AuditActionDefinition('usuario.actualizado', 'Se modificó el usuario', 'Configuración'),
            new AuditActionDefinition('usuario.activado', 'Se reactivó el usuario', 'Configuración'),
            new AuditActionDefinition('usuario.desactivado', 'Se desactivó el usuario', 'Configuración'),
            new AuditActionDefinition(
                'usuario.password_restablecida',
                'Se restableció la contraseña',
                'Configuración',
                AuditSeverity::Critical,
            ),
            new AuditActionDefinition(
                'rol.permisos_actualizados',
                'Se cambiaron los permisos del rol',
                'Configuración',
                AuditSeverity::Critical,
                metadata: ['otorgados' => 'Permisos otorgados', 'retirados' => 'Permisos retirados'],
            ),
        );

        $catalog->registerSubject(new UserSubject);
        $catalog->registerSubject(new RoleSubject);
        $catalog->registerSubject(new PersonSubject);
        $catalog->registerSubject(new PersonBankAccountSubject);

        $catalog->registerReference(new PersonReference);
    }
}
