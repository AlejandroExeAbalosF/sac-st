<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Permissions;

use App\Modules\Shared\Permissions\PermissionCatalog;
use App\Modules\Shared\Permissions\PermissionCatalogContributor;
use App\Modules\Shared\Permissions\PermissionDefinition;

/**
 * Lo que declara Haberes: el circuito entero, desde que entra el
 * expediente hasta que el dinero le llega a su dueño.
 *
 * Usa además grupos que registraron módulos de más abajo: «Personas» para
 * la verificación del CBU y «Caja» para los traslados y el saldo del
 * sistema anterior, porque es Haberes quien exige esos permisos.
 */
final class HaberesPermissionCatalog implements PermissionCatalogContributor
{
    public function register(PermissionCatalog $catalog): void
    {
        $catalog->registerGroup('expedientes', 'Expedientes', 10);
        $catalog->registerGroup('depositos', 'Depósitos', 30);
        $catalog->registerGroup('recepciones', 'Recepciones', 50);
        $catalog->registerGroup('recibos', 'Recibos de ingreso', 60);
        $catalog->registerGroup('ordenes', 'Órdenes de Pago', 70);
        $catalog->registerGroup('egresos', 'Egresos al beneficiario', 80);

        $this->expedientes($catalog);
        $this->ingresos($catalog);
        $this->egresos($catalog);
        $this->caja($catalog);
    }

    private function expedientes(PermissionCatalog $catalog): void
    {
        $catalog->register(
            new PermissionDefinition(
                'expedientes.ver',
                'Ver expedientes',
                'Consultar los expedientes con sus haberes, cuotas e historial de cambios.',
                'expedientes',
                ['administrador', 'contador', 'administrativo', 'consulta'],
            ),
            new PermissionDefinition(
                'expedientes.crear',
                'Cargar expedientes',
                'Dar de alta un expediente y agregarle haberes.',
                'expedientes',
                ['administrador', 'administrativo'],
            ),
            new PermissionDefinition(
                'expedientes.editar',
                'Editar expedientes',
                'Modificar un expediente y sus haberes, agregar o corregir cuotas y volver a habilitar la edición de una cuota.',
                'expedientes',
                ['administrador', 'administrativo'],
            ),
            // Anular deshace el expediente entero y arrastra sus haberes:
            // no lo alcanza quien carga todos los dias.
            new PermissionDefinition(
                'expedientes.anular',
                'Anular expedientes y haberes',
                'Anular o reactivar un expediente o uno de sus haberes, con todas sus cuotas, y anular el registro de un pago histórico.',
                'expedientes',
                ['administrador', 'contador'],
                sensitive: true,
            ),
            // Es parte de cargar el expediente, así que lo tiene quien carga;
            // anular ese registro va con `expedientes.anular`.
            new PermissionDefinition(
                'expedientes.registrar-historico',
                'Registrar pagos históricos',
                'Dejar constancia de que una cuota de un expediente del sistema anterior ya se pagó fuera de este sistema.',
                'expedientes',
                ['administrador', 'administrativo'],
            ),

            // Verificar un CBU es la puerta de la Orden: sin cuenta verificada
            // el organismo no transfiere (§2.2.9). No es carga de datos sino
            // cotejo contra la foja del expediente, y el caso que lo justifica
            // es real: un CVU de billetera informado como CBU volvió con el
            // expediente observado.
            new PermissionDefinition(
                'personas.verificar-cbu',
                'Cargar y verificar CBU',
                'Agregar cuentas bancarias a una persona y verificarlas, rechazarlas o darlas de baja cotejándolas con la foja del expediente. Sin una cuenta verificada no se emite la Orden de Pago.',
                'personas',
                ['administrador', 'contador'],
                sensitive: true,
            ),
            /*
             * `Gate::before` lee el prefijo `dev.` para dejar estas
             * capacidades **fuera del comodín del administrador**. Las tiene
             * `super-admin` y nadie más: son atajos para atravesar el
             * circuito con datos inventados, no funciones del área.
             */
            new PermissionDefinition(
                'dev.forzar-cbu',
                'Forzar la verificación de un CBU',
                'Dar por verificado un CBU sin el cotejo, para probar el circuito con datos inventados. Solo lo tiene super-admin.',
                'desarrollo',
                ['super-admin'],
                sensitive: true,
            ),
        );
    }

    private function ingresos(PermissionCatalog $catalog): void
    {
        $catalog->register(
            // El comprobante que llega con el expediente y espera aparecer en
            // el extracto. Lo carga quien recibe el expediente.
            new PermissionDefinition(
                'depositos.ver',
                'Ver depósitos',
                'Consultar los tickets de depósito, su planilla y los movimientos del extracto que podrían corresponderles.',
                'depositos',
                ['administrador', 'contador', 'administrativo', 'consulta'],
            ),
            new PermissionDefinition(
                'depositos.registrar',
                'Cargar tickets de depósito',
                'Cargar y corregir el comprobante de depósito que llega con el expediente.',
                'depositos',
                ['administrador', 'contador', 'administrativo'],
            ),
            // Vincular es afirmar que ese papel y ese crédito son el mismo
            // hecho. Todavía no mueve plata —eso es la recepción— pero es la
            // lectura sobre la que después se va a imputar.
            new PermissionDefinition(
                'depositos.vincular',
                'Vincular depósitos con el extracto',
                'Indicar qué crédito del extracto corresponde a un ticket de depósito, o deshacer ese vínculo.',
                'depositos',
                ['administrador', 'contador', 'administrativo'],
            ),
            // Descartar afirma que ese depósito no va a aparecer nunca.
            new PermissionDefinition(
                'depositos.descartar',
                'Descartar depósitos',
                'Declarar que un depósito no va a aparecer nunca en el extracto, o reabrir uno descartado.',
                'depositos',
                ['administrador', 'contador'],
                sensitive: true,
            ),

            // Acá empieza a moverse plata de verdad. Registrar una recepción
            // asienta en los libros que ese dinero entró; asignarla dice de
            // quién es y deja la cuota financiada. Las dos son trabajo diario
            // del administrativo: pedirle el contador para cada depósito
            // frenaría el circuito sin agregar control, porque nada de esto
            // sale del organismo todavía.
            new PermissionDefinition(
                'recepciones.ver',
                'Ver recepciones',
                'Consultar el dinero recibido y a qué cuotas se asignó.',
                'recepciones',
                ['administrador', 'contador', 'administrativo', 'consulta'],
            ),
            new PermissionDefinition(
                'recepciones.registrar',
                'Registrar recepciones',
                'Registrar que un dinero entró al organismo. Queda asentado en los libros.',
                'recepciones',
                ['administrador', 'contador', 'administrativo'],
            ),
            new PermissionDefinition(
                'recepciones.asignar',
                'Asignar recepciones a cuotas',
                'Indicar a qué cuotas corresponde un dinero recibido, lo que las deja financiadas.',
                'recepciones',
                ['administrador', 'contador', 'administrativo'],
            ),
            // Revertir sí pesa distinto: deshace un asiento y devuelve dinero
            // a la cola de no identificados.
            new PermissionDefinition(
                'recepciones.revertir',
                'Revertir recepciones',
                'Deshacer una recepción o quitarle una asignación a una cuota. Anula el asiento y devuelve el dinero a la cola de no identificados.',
                'recepciones',
                ['administrador', 'contador'],
                sensitive: true,
            ),
            // Reconocer un excedente lo saca de la cola de pendientes sin que
            // nadie lo haya identificado: es una decisión, no una carga.
            new PermissionDefinition(
                'recepciones.reconocer-excedente',
                'Reconocer excedentes',
                'Reservado para dar por reconocido un excedente que nadie identificó. La pantalla todavía no existe.',
                'recepciones',
                ['administrador', 'contador'],
                sensitive: true,
            ),

            // El recibo de ingreso es el papel que el empleador se lleva, y
            // se emite en el mostrador: lo confecciona quien atiende, que es
            // el mismo que registra la recepción.
            new PermissionDefinition(
                'recibos.ver',
                'Ver recibos de ingreso',
                'Consultar, ver e imprimir los recibos de ingreso ya emitidos.',
                'recibos',
                ['administrador', 'contador', 'administrativo', 'consulta'],
            ),
            new PermissionDefinition(
                'recibos.emitir',
                'Emitir recibos de ingreso',
                'Emitir el recibo que se lleva el empleador cuando paga en el mostrador.',
                'recibos',
                ['administrador', 'contador', 'administrativo'],
            ),
            // Anular pesa distinto: deja sin respaldo un documento ya
            // entregado, y si esa cuota llegó a tener Orden de Pago arrastra
            // un procedimiento escalonado (§2.7).
            new PermissionDefinition(
                'recibos.anular',
                'Anular cobros y recibos',
                'Anular el cobro de una cuota junto con su recibo. Si la cuota ya tenía Orden de Pago, sigue un procedimiento escalonado.',
                'recibos',
                ['administrador', 'contador'],
                sensitive: true,
            ),
        );
    }

    private function egresos(PermissionCatalog $catalog): void
    {
        $catalog->register(
            // Acá el circuito sale del organismo: la Orden y su Pase le piden
            // al SAF que transfiera el dinero de un tercero. Emitirlas es
            // trabajo del área contable, no de quien atiende el mostrador, y
            // por eso el administrativo no las alcanza.
            new PermissionDefinition(
                'ordenes.ver',
                'Ver Órdenes de Pago',
                'Consultar, ver e imprimir las Órdenes de Pago y sus Pases.',
                'ordenes',
                ['administrador', 'contador', 'administrativo', 'consulta'],
            ),
            new PermissionDefinition(
                'ordenes.emitir',
                'Emitir Órdenes de Pago',
                'Emitir la Orden de Pago y el Pase que le piden al SAF transferir el dinero al beneficiario, y completar o corregir sus datos.',
                'ordenes',
                ['administrador', 'contador'],
                sensitive: true,
            ),
            // Anular deja sin efecto un documento que el organismo puede tener
            // en la mano, y arrastra su Pase.
            new PermissionDefinition(
                'ordenes.anular',
                'Anular Órdenes de Pago',
                'Dejar sin efecto una Orden de Pago ya emitida, junto con su Pase.',
                'ordenes',
                ['administrador', 'contador'],
                sensitive: true,
            ),

            // El otro extremo del circuito: el dinero saliendo hacia su dueño.
            // Entregar por mostrador es trabajo del que atiende —el trabajador
            // está enfrente, firma y se lleva su plata— y va con el mismo
            // reparto que cobrar, que es el acto simétrico.
            new PermissionDefinition(
                'egresos.registrar',
                'Registrar pagos al beneficiario',
                'Registrar la entrega del dinero al beneficiario, cargar el débito y el informe de la transferencia, emitir el recibo de egreso y ver las planillas de pago.',
                'egresos',
                ['administrador', 'contador', 'administrativo'],
            ),
            // Validar la transferencia es lo contrario: el contador coteja
            // Orden, informe y débito, y recién esa firma convierte el pago en
            // un hecho (§2.3.4).
            new PermissionDefinition(
                'egresos.validar',
                'Validar transferencias',
                'Cotejar la Orden, el informe y el débito de una transferencia. Recién con esta validación el pago queda firme.',
                'egresos',
                ['administrador', 'contador'],
                sensitive: true,
            ),
        );
    }

    private function caja(PermissionCatalog $catalog): void
    {
        $catalog->register(
            // Llevar al banco el efectivo que el beneficiario no retiró. Mueve
            // dinero pero no lo saca del organismo: cambia de la caja a la
            // cuenta, y sigue siendo del mismo beneficiario. Por eso va con el
            // mismo reparto que registrar una recepción.
            new PermissionDefinition(
                'caja.trasladar',
                'Trasladar efectivo al banco',
                'Llevar al banco el efectivo que el beneficiario no retiró. El dinero pasa de la caja a la cuenta y sigue siendo del mismo beneficiario.',
                'caja',
                ['administrador', 'contador', 'administrativo'],
            ),
            // Confirmar contra el extracto es trabajo de conciliación, igual
            // que vincular un depósito.
            new PermissionDefinition(
                'caja.confirmar-traslado',
                'Confirmar traslados',
                'Confirmar un traslado contra el crédito del extracto, o cancelarlo.',
                'caja',
                ['administrador', 'contador', 'administrativo'],
            ),
            // Es la única forma en que baja el saldo del sistema anterior —el
            // pago suelto, sin cuota, se retiró— y decide de quién es plata
            // que el sistema no recibió: el criterio del contador.
            new PermissionDefinition(
                'caja.apartar-anterior',
                'Apartar fondos del sistema anterior',
                'Reservar del saldo del sistema anterior el dinero de una cuota histórica que sigue en custodia, o anular un recibo de papel de ese saldo.',
                'caja',
                ['administrador', 'contador'],
                sensitive: true,
            ),
        );
    }
}
