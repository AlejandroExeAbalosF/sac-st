<?php

declare(strict_types=1);

namespace App\Modules\Banking\Permissions;

use App\Modules\Shared\Permissions\PermissionCatalog;
use App\Modules\Shared\Permissions\PermissionCatalogContributor;
use App\Modules\Shared\Permissions\PermissionDefinition;

/**
 * Lo que declara Banking: las cuentas del organismo, los extractos y sus
 * movimientos.
 */
final class BankingPermissionCatalog implements PermissionCatalogContributor
{
    public function register(PermissionCatalog $catalog): void
    {
        $catalog->registerGroup('banco', 'Banco', 40);

        $catalog->register(
            new PermissionDefinition(
                'banco.cuentas.ver',
                'Ver cuentas bancarias del organismo',
                'Consultar las cuentas bancarias del organismo en las que se reciben los depósitos.',
                'banco',
                ['administrador', 'contador', 'administrativo', 'consulta'],
            ),
            // La cuenta del organismo es un maestro que casi no cambia y del
            // que cuelga todo lo bancario: darla de alta mal desvía un
            // extracto entero, así que no la toca quien opera todos los días.
            new PermissionDefinition(
                'banco.cuentas.gestionar',
                'Gestionar cuentas bancarias del organismo',
                'Dar de alta y modificar las cuentas del organismo. Un dato mal cargado desvía todos los extractos de esa cuenta.',
                'banco',
                ['administrador', 'contador'],
            ),
            // Como la apertura de una caja: define qué créditos ya no entran
            // a ningún libro, y no se corrige.
            new PermissionDefinition(
                'banco.cuentas.declarar-corte',
                'Declarar el corte de una cuenta compartida',
                'Cargar, una sola vez, el saldo que informa el banco para una cuenta que usan varias cajas, sin repartirlo entre ellas.',
                'banco',
                ['administrador'],
                sensitive: true,
            ),
            new PermissionDefinition(
                'banco.extractos.ver',
                'Ver extractos y movimientos',
                'Consultar los extractos importados y la lista de movimientos bancarios con su estado de conciliación.',
                'banco',
                ['administrador', 'contador', 'administrativo', 'consulta'],
            ),
            new PermissionDefinition(
                'banco.extractos.importar',
                'Importar extractos',
                'Subir el archivo del banco, revisar la vista previa y registrar sus movimientos en el sistema.',
                'banco',
                ['administrador', 'contador', 'administrativo'],
            ),
            // Los dos siguientes declaran que algo no entra en la
            // contabilidad, y eso pesa distinto que cargar el archivo:
            // «fuera del circuito» saca un movimiento de la cola de
            // pendientes, y revertir borra movimientos ya registrados.
            new PermissionDefinition(
                'banco.movimientos.ignorar',
                'Marcar movimientos fuera del circuito',
                'Sacar un movimiento bancario de la cola de pendientes declarando que no pertenece a Haberes.',
                'banco',
                ['administrador', 'contador'],
                sensitive: true,
            ),
            new PermissionDefinition(
                'banco.extractos.revertir',
                'Revertir la importación de un extracto',
                'Deshacer una importación y borrar los movimientos que había registrado.',
                'banco',
                ['administrador', 'contador'],
                sensitive: true,
            ),
        );
    }
}
