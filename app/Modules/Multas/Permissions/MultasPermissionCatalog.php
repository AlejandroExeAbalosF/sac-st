<?php

declare(strict_types=1);

namespace App\Modules\Multas\Permissions;

use App\Modules\Shared\Permissions\PermissionCatalog;
use App\Modules\Shared\Permissions\PermissionCatalogContributor;
use App\Modules\Shared\Permissions\PermissionDefinition;

/**
 * Lo que declara Multas: registrar las multas, su obligación y sus
 * antecedentes, abrir la caja, cobrar y verificar los pagos por el banco
 * (DER físico §8).
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
                'Consultar los expedientes y las multas: documentos, obligación, antecedentes y saldo.',
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
            // Llevar al banco lo cobrado: cambia de lugar, no de dueño. Lo hace
            // quien atiende la caja, como en Haberes (`caja.trasladar`).
            new PermissionDefinition(
                'multas.depositar',
                'Depositar cobros de multas',
                'Llevar al banco un cobro de la caja de Multas, entero y en su propio depósito, con el ticket del cajero.',
                'multas',
                ['administrador', 'administrativo'],
            ),
            // Verificar es afirmar que un crédito del banco es el pago de una
            // multa: lo hace quien concilia, no quien atiende el mostrador.
            new PermissionDefinition(
                'multas.verificar-pagos',
                'Verificar pagos de multas por el banco',
                'Informar pagos por transferencia, depósito directo o cheque electrónico, verificarlos contra un crédito del extracto y descartar los que no corresponden. Confirmar o cancelar los depósitos de la caja.',
                'multas',
                ['administrador', 'contador'],
                sensitive: true,
            ),
            // Registrar un rechazo cambia lo que la caja y el banco dicen tener,
            // y lo que se imputa del extracto es conciliación: quien concilia.
            // Recibir el reemplazo es un cobro de mostrador (`multas.cobrar`).
            new PermissionDefinition(
                'multas.gestionar-rechazos',
                'Gestionar cheques rechazados de multas',
                'Registrar el rechazo de un cheque con su respaldo y completar el aviso a la empresa y qué se hizo con el cheque.',
                'multas',
                ['administrador', 'contador'],
                sensitive: true,
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

            /*
             * La caja del día, el arqueo y el cierre de Multas (Etapa 6). Son
             * las mismas pantallas que las de Haberes, con permisos propios:
             * cerrar una caja no habilita a cerrar la otra. Cada uno con el
             * reparto de roles de su par de Haberes (`caja.*`, `cierres.*`).
             */
            new PermissionDefinition(
                'multas.caja.ver',
                'Ver la caja de Multas',
                'Consultar la caja del día de Multas, sus arqueos, sus cierres y el calendario de días cerrados y abiertos.',
                'multas',
                ['administrador', 'contador', 'administrativo', 'consulta'],
            ),
            new PermissionDefinition(
                'multas.caja.arquear',
                'Registrar arqueos de Multas',
                'Cargar el conteo del efectivo del cajón de Multas para compararlo con el saldo del sistema.',
                'multas',
                ['administrador', 'contador', 'administrativo'],
            ),
            new PermissionDefinition(
                'multas.caja.revisar-arqueo',
                'Revisar arqueos de Multas',
                'Dar por revisado un arqueo de la caja de Multas. Si lo revisa quien lo contó, queda a la vista.',
                'multas',
                ['administrador', 'contador'],
            ),
            new PermissionDefinition(
                'multas.caja.ajustar-diferencia',
                'Imputar diferencias de arqueo de Multas',
                'Registrar como sobrante o faltante la diferencia del arqueo de Multas. Mueve el saldo sin que entre ni salga dinero del cajón.',
                'multas',
                ['administrador', 'contador'],
                sensitive: true,
            ),
            new PermissionDefinition(
                'multas.caja.cerrar',
                'Cerrar períodos de Multas',
                'Cerrar un día o un mes de la caja de Multas: congela sus totales e impide registrar movimientos con fecha dentro de él.',
                'multas',
                ['administrador', 'contador'],
            ),
            new PermissionDefinition(
                'multas.caja.reabrir',
                'Reabrir períodos de Multas',
                'Deshacer un cierre de la caja de Multas para volver a admitir movimientos en esas fechas. Exige un motivo y queda en la auditoría.',
                'multas',
                ['administrador', 'contador'],
                sensitive: true,
            ),
            new PermissionDefinition(
                'multas.caja.exportar',
                'Descargar la planilla de Multas',
                'Bajar la planilla de un cierre de la caja de Multas. Es solo lectura.',
                'multas',
                ['administrador', 'contador', 'administrativo', 'consulta'],
            ),
            new PermissionDefinition(
                'multas.caja.regenerar-planilla',
                'Rehacer la planilla de Multas',
                'Generar de nuevo la planilla de un cierre de Multas ya emitido. Los números no cambian; la anterior se conserva.',
                'multas',
                ['administrador'],
                sensitive: true,
            ),
        );
    }
}
