<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Permissions;

use App\Modules\Shared\Permissions\PermissionCatalog;
use App\Modules\Shared\Permissions\PermissionCatalogContributor;
use App\Modules\Shared\Permissions\PermissionDefinition;

/**
 * Lo que declara Aranceles (DER físico §9). Las asignaciones de fábrica son un
 * punto de partida: el área las cambia desde Roles.
 */
final class ArancelesPermissionCatalog implements PermissionCatalogContributor
{
    public function register(PermissionCatalog $catalog): void
    {
        $catalog->registerGroup('aranceles', 'Aranceles', 86);

        $catalog->register(
            new PermissionDefinition(
                'aranceles.ver',
                'Ver aranceles',
                'Consultar el catálogo de aranceles y sus precios.',
                'aranceles',
                ['administrador', 'contador', 'administrativo', 'consulta'],
            ),
            // Cambia lo que se propone cobrar a partir de una fecha.
            new PermissionDefinition(
                'aranceles.catalogo',
                'Administrar el catálogo de aranceles',
                'Dar de alta, corregir y desactivar tipos de arancel, y agregar o anular sus precios.',
                'aranceles',
                ['administrador'],
                sensitive: true,
            ),
            new PermissionDefinition(
                'aranceles.cobrar',
                'Cobrar aranceles',
                'Cobrar aranceles en efectivo y emitir su recibo, y registrar los que se van a pagar por depósito.',
                'aranceles',
                ['administrador', 'administrativo'],
            ),
            // Decisión del usuario: lo hace quien atiende, y queda a la vista.
            new PermissionDefinition(
                'aranceles.modificar-importe',
                'Modificar el importe de un arancel',
                'Cobrar de más o de menos que lo calculado, o ajustar un cobro pendiente, con su motivo.',
                'aranceles',
                ['administrador', 'administrativo'],
                sensitive: true,
            ),
            new PermissionDefinition(
                'aranceles.anular',
                'Anular cobros de aranceles',
                'Anular un cobro cuyo dinero nunca entró, o un pendiente que no sigue. El recibo queda anulado con su número, y un crédito identificado vuelve a quedar libre.',
                'aranceles',
                ['administrador', 'contador'],
                sensitive: true,
            ),
            new PermissionDefinition(
                'aranceles.reemplazar-recibos',
                'Reemplazar recibos de aranceles',
                'Corregir el nombre, el CUIT o DNI o la redacción de un recibo con otro, sin mover dinero.',
                'aranceles',
                ['administrador', 'contador'],
                sensitive: true,
            ),
            new PermissionDefinition(
                'aranceles.corregir',
                'Corregir cobros de aranceles',
                'Cambiar el tipo, la cantidad o el importe de un cobro: el anterior queda anulado y nace otro.',
                'aranceles',
                ['administrador'],
                sensitive: true,
            ),
            new PermissionDefinition(
                'aranceles.depositar',
                'Depositar el efectivo de Aranceles',
                'Registrar el traspaso del efectivo al banco y cargar su comprobante.',
                'aranceles',
                ['administrador', 'administrativo'],
            ),
            // Decisión del usuario: la fecha la toma el sistema; registrar otra es una excepción.
            new PermissionDefinition(
                'aranceles.ajustar-traspaso',
                'Cambiar el importe o la fecha de un traspaso',
                'Registrar un traspaso por menos de todo lo disponible, o con una fecha pasada en vez de la del día.',
                'aranceles',
                ['administrador'],
                sensitive: true,
            ),
            // Decisión del usuario: lo hace quien traspasa, con motivo y auditado.
            new PermissionDefinition(
                'aranceles.cancelar-traspaso',
                'Cancelar traspasos de Aranceles',
                'Cancelar un traspaso sin acreditar, con su motivo. El efectivo vuelve a la caja.',
                'aranceles',
                ['administrador', 'contador', 'administrativo'],
                sensitive: true,
            ),
            new PermissionDefinition(
                'aranceles.verificar-pagos',
                'Verificar depósitos de Aranceles',
                'Identificar créditos del extracto como pagos de aranceles y acreditar un depósito de la caja.',
                'aranceles',
                ['administrador', 'contador'],
                sensitive: true,
            ),
            new PermissionDefinition(
                'aranceles.caja.abrir',
                'Abrir la caja de Aranceles',
                'Declarar el efectivo con el que empieza la caja, una sola vez.',
                'aranceles',
                ['administrador'],
                sensitive: true,
            ),
            new PermissionDefinition(
                'aranceles.caja.cuentas',
                'Administrar las cuentas de la caja de Aranceles',
                'Habilitar, dar de baja y cambiar la vigencia de las cuentas con las que opera.',
                'aranceles',
                ['administrador'],
                sensitive: true,
            ),
            new PermissionDefinition(
                'aranceles.caja.ver',
                'Ver la caja de Aranceles',
                'Consultar la caja del día, los arqueos, los cierres y el calendario.',
                'aranceles',
                ['administrador', 'contador', 'administrativo', 'consulta'],
            ),
            new PermissionDefinition(
                'aranceles.caja.arquear',
                'Arquear la caja de Aranceles',
                'Contar el efectivo y registrar el arqueo.',
                'aranceles',
                ['administrador', 'contador', 'administrativo'],
            ),
            new PermissionDefinition(
                'aranceles.caja.revisar-arqueo',
                'Revisar arqueos de Aranceles',
                'Revisar el arqueo que contó otra persona.',
                'aranceles',
                ['administrador', 'contador'],
            ),
            new PermissionDefinition(
                'aranceles.caja.ajustar-diferencia',
                'Imputar diferencias de arqueo de Aranceles',
                'Registrar una diferencia de arqueo con su explicación.',
                'aranceles',
                ['administrador', 'contador'],
                sensitive: true,
            ),
            new PermissionDefinition(
                'aranceles.caja.cerrar',
                'Cerrar la caja de Aranceles',
                'Cerrar el día y el mes.',
                'aranceles',
                ['administrador', 'contador'],
            ),
            new PermissionDefinition(
                'aranceles.caja.reabrir',
                'Reabrir la caja de Aranceles',
                'Reabrir un cierre, con su motivo.',
                'aranceles',
                ['administrador', 'contador'],
                sensitive: true,
            ),
            new PermissionDefinition(
                'aranceles.caja.exportar',
                'Exportar la planilla de Aranceles',
                'Descargar la planilla de caja de un cierre.',
                'aranceles',
                ['administrador', 'contador', 'administrativo', 'consulta'],
            ),
            new PermissionDefinition(
                'aranceles.caja.regenerar-planilla',
                'Rehacer la planilla de Aranceles',
                'Volver a generar la planilla de un cierre.',
                'aranceles',
                ['administrador'],
                sensitive: true,
            ),
        );
    }
}
