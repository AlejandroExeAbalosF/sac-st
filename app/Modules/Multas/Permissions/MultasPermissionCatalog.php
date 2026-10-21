<?php

declare(strict_types=1);

namespace App\Modules\Multas\Permissions;

use App\Modules\Shared\Permissions\PermissionCatalog;
use App\Modules\Shared\Permissions\PermissionCatalogContributor;
use App\Modules\Shared\Permissions\PermissionDefinition;

/**
 * Lo que declara Multas: registrar las multas, su obligación y sus
 * antecedentes, abrir la caja y cobrar. La verificación bancaria y el
 * permiso no delegable del 20 % llegan con su tanda (DER físico §8).
 */
final class MultasPermissionCatalog implements PermissionCatalogContributor
{
    public function register(PermissionCatalog $catalog): void
    {
        $catalog->registerGroup('multas', 'Multas', 85);

        $catalog->register(
            new PermissionDefinition(
                'multas.ver',
                'Ver multas',
                'Consultar la cuenta corriente de multas: expedientes, documentos, obligación, antecedentes y saldo.',
                'multas',
                ['administrador', 'contador', 'administrativo', 'consulta'],
            ),
            // Cargar es lo de todos los días, y completar un dato que
            // faltaba es parte de cargar.
            new PermissionDefinition(
                'multas.registrar',
                'Cargar multas',
                'Dar de alta expedientes, documentos y multas, completar los datos que faltan y registrar pagos anteriores al sistema.',
                'multas',
                ['administrador', 'administrativo'],
            ),
            // Cambia cuánto se debe, o corrige lo que ya se había cargado:
            // no lo alcanza quien carga todos los días.
            new PermissionDefinition(
                'multas.ajustar',
                'Ajustar multas',
                'Registrar los aumentos y reducciones que dispone una resolución, revertir un ajuste, corregir datos ya cargados y validar o descartar los pagos anteriores.',
                'multas',
                ['administrador', 'contador'],
                sensitive: true,
            ),
            // Cobrar en el mostrador y entregar el recibo: lo de todos los días.
            new PermissionDefinition(
                'multas.cobrar',
                'Cobrar multas en caja',
                'Cobrar multas en efectivo o con cheque y emitir su recibo.',
                'multas',
                ['administrador', 'administrativo'],
            ),
            // Como la apertura de Haberes: define todos los saldos posteriores
            // de la caja y no se rehace. Se adelanta de la Etapa 6 (§8).
            new PermissionDefinition(
                'multas.caja.abrir',
                'Abrir la caja de Multas',
                'Declarar, una sola vez, el efectivo y los cheques que hay en la caja de Multas el día que empieza a operar, y la cuenta bancaria con la que opera.',
                'multas',
                ['administrador'],
                sensitive: true,
            ),
        );
    }
}
