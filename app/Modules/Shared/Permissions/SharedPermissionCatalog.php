<?php

declare(strict_types=1);

namespace App\Modules\Shared\Permissions;

/**
 * Lo que declara Shared: personas, adjuntos, auditoría, usuarios y la
 * configuración del sistema.
 *
 * Registra también los grupos de los conceptos que viven acá aunque los
 * usen otros módulos —«Personas» lo comparte Haberes con la verificación
 * del CBU— y el de las capacidades de desarrollo.
 *
 * Los comentarios dicen **por qué** un permiso va a quien va; la
 * descripción dice **qué hace**, y es lo que lee el área en la pantalla.
 */
final class SharedPermissionCatalog implements PermissionCatalogContributor
{
    public function register(PermissionCatalog $catalog): void
    {
        $catalog->registerGroup('personas', 'Personas', 20);
        $catalog->registerGroup('adjuntos', 'Adjuntos', 110);
        $catalog->registerGroup('auditoria', 'Auditoría', 120);
        $catalog->registerGroup('usuarios', 'Usuarios', 130);
        $catalog->registerGroup('configuracion', 'Configuración', 140);
        $catalog->registerGroup('desarrollo', 'Desarrollo', 900);

        $catalog->register(
            new PermissionDefinition(
                'personas.ver',
                'Ver personas',
                'Consultar el maestro de personas —trabajadores, empleadores y representantes— y buscarlas desde los formularios.',
                'personas',
                ['administrador', 'contador', 'administrativo', 'consulta'],
            ),
            // El alta ocurre a mitad de otro trámite, desde el buscador del
            // formulario, así que no la puede disparar cualquiera: es un dato
            // que después sale impreso en los comprobantes.
            new PermissionDefinition(
                'personas.crear',
                'Dar de alta personas',
                'Agregar una persona al maestro, también desde el buscador de un formulario. Sus datos salen impresos en los comprobantes.',
                'personas',
                ['administrador', 'administrativo'],
            ),
            // Corregir una ficha cambia lo que sale impreso en los
            // comprobantes, así que pesa igual que crearla.
            new PermissionDefinition(
                'personas.editar',
                'Corregir fichas de personas',
                'Modificar los datos de una persona ya cargada. Cambia lo que se imprime en los comprobantes que se emitan desde ese momento.',
                'personas',
                ['administrador', 'administrativo'],
            ),

            // Toda la evidencia del sistema pasa por acá: el ticket del
            // expediente, el extracto importado, el Pase firmado.
            new PermissionDefinition(
                'adjuntos.ver',
                'Ver adjuntos',
                'Abrir y descargar la documentación adjunta: tickets de depósito, extractos importados, Pases firmados.',
                'adjuntos',
                ['administrador', 'contador', 'administrativo', 'consulta'],
            ),
            new PermissionDefinition(
                'adjuntos.cargar',
                'Cargar adjuntos sueltos',
                'Reservado para una carga de adjuntos independiente, que todavía no existe. Hoy cada adjunto se sube con el permiso de la operación que documenta.',
                'adjuntos',
                ['administrador', 'contador', 'administrativo'],
            ),
            // Lo reservado no circula: una nota médica, un dato sensible del
            // trabajador. Quien consulta expedientes todo el día no lo abre.
            new PermissionDefinition(
                'adjuntos.ver-reservados',
                'Ver adjuntos reservados',
                'Abrir los adjuntos marcados como reservados, como una nota médica o un dato sensible del trabajador.',
                'adjuntos',
                ['administrador', 'contador'],
                sensitive: true,
            ),

            new PermissionDefinition(
                'auditoria.accesos.ver',
                'Ver el historial de accesos',
                'Consultar los ingresos al sistema, los intentos fallidos y los bloqueos de cuenta.',
                'auditoria',
                ['administrador', 'contador'],
            ),
            new PermissionDefinition(
                'auditoria.sesiones.ver',
                'Ver sesiones abiertas',
                'Ver, dentro del historial de accesos, qué sesiones siguen abiertas, de quién son y desde dónde se iniciaron.',
                'auditoria',
                ['administrador', 'contador'],
            ),
            new PermissionDefinition(
                'auditoria.sesiones.revocar',
                'Cerrar sesiones de otros usuarios',
                'Cerrar a distancia una sesión abierta, por ejemplo la de un equipo perdido o compartido.',
                'auditoria',
                ['administrador'],
            ),
            // La auditoría de operaciones: quién anuló, revirtió, reabrió o
            // forzó qué. La mira quien controla, no quien opera.
            new PermissionDefinition(
                'auditoria.operaciones.ver',
                'Ver la auditoría de operaciones',
                'Consultar quién anuló, revirtió, reabrió, forzó o configuró qué, con el detalle de cada cambio.',
                'auditoria',
                ['administrador', 'contador'],
            ),
            // Exportar saca del sistema datos personales —nombres, CBU— en un
            // archivo que después nadie controla adónde va.
            new PermissionDefinition(
                'auditoria.operaciones.exportar',
                'Exportar la auditoría de operaciones',
                'Descargar la auditoría en Excel. El archivo sale del sistema con datos personales, como nombres y CBU.',
                'auditoria',
                ['administrador', 'contador'],
                sensitive: true,
            ),

            // Ver la lista es solo lectura y puede delegarse. Lo que sigue no:
            // con cualquiera de esos cuatro, un rol de la matriz se vuelve
            // administrador —crea una cuenta con ese rol y entra con la clave
            // temporal, o asciende a otro—, entra como un compañero
            // restableciéndole la clave, o deja afuera a los demás. La guarda
            // de usuarios cuida al super-admin y a los administradores entre
            // sí, pero no impide que quien no lo es asigne el rol de
            // administrador: por eso estos permisos son solo de él.
            new PermissionDefinition(
                'usuarios.ver',
                'Ver usuarios',
                'Consultar las cuentas de usuario del sistema, con su rol y su estado.',
                'usuarios',
                ['administrador'],
            ),
            new PermissionDefinition(
                'usuarios.crear',
                'Crear usuarios',
                'Dar de alta una cuenta de usuario y asignarle un rol.',
                'usuarios',
                ['administrador'],
                delegable: false,
            ),
            new PermissionDefinition(
                'usuarios.editar',
                'Editar usuarios',
                'Modificar los datos de una cuenta de usuario y cambiarle el rol.',
                'usuarios',
                ['administrador'],
                delegable: false,
            ),
            new PermissionDefinition(
                'usuarios.desactivar',
                'Desactivar y reactivar usuarios',
                'Impedir que una cuenta ingrese al sistema sin borrarla, o volver a habilitarla.',
                'usuarios',
                ['administrador'],
                delegable: false,
            ),
            new PermissionDefinition(
                'usuarios.restablecer-password',
                'Restablecer contraseñas',
                'Asignarle una contraseña nueva a otro usuario. Queda en la auditoría como operación crítica.',
                'usuarios',
                ['administrador'],
                sensitive: true,
                delegable: false,
            ),

            // No se delega: quien edita la matriz puede editar la de su propio
            // rol, y con eso darse cualquier otro permiso. Otorgarlo a un rol
            // de la matriz equivaldría a otorgarle todo. Lo impiden también
            // `UpdateRolePermissions` y un trigger sobre `role_has_permissions`.
            new PermissionDefinition(
                'roles.gestionar',
                'Gestionar roles y permisos',
                'Entrar a esta pantalla y cambiar qué puede hacer cada rol.',
                'configuracion',
                ['administrador'],
                sensitive: true,
                delegable: false,
            ),
            new PermissionDefinition(
                'numeracion.gestionar',
                'Configurar la numeración de comprobantes',
                'Cambiar el prefijo, los dígitos, el separador y el próximo número de los recibos y de las Órdenes de Pago.',
                'configuracion',
                ['administrador'],
                sensitive: true,
            ),
            // El catálogo de series no se edita desde la interfaz: lo siembra
            // un seeder. El permiso existe para consultarlo.
            new PermissionDefinition(
                'series.ver',
                'Ver series de numeración',
                'Reservado para la pantalla de talonarios, que todavía no existe.',
                'configuracion',
                ['administrador', 'contador'],
            ),
        );
    }
}
