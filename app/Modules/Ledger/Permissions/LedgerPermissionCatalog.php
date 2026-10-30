<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Permissions;

use App\Modules\Shared\Permissions\PermissionCatalog;
use App\Modules\Shared\Permissions\PermissionCatalogContributor;
use App\Modules\Shared\Permissions\PermissionDefinition;

/**
 * Lo que declara Ledger: la caja, sus arqueos y los cierres de período.
 *
 * Registra el grupo «Caja», que Haberes también usa para los traslados al
 * banco y el saldo del sistema anterior.
 */
final class LedgerPermissionCatalog implements PermissionCatalogContributor
{
    public function register(PermissionCatalog $catalog): void
    {
        $catalog->registerGroup('caja', 'Caja', 90);
        $catalog->registerGroup('cierres', 'Cierres', 100);

        $catalog->register(
            // El saldo de caja y el libro del día los mira cualquiera que ya
            // mire expedientes: es la misma información, ordenada por fecha en
            // vez de por trámite.
            new PermissionDefinition(
                'caja.ver',
                'Ver la caja',
                'Consultar el saldo de caja, el libro del día, los arqueos, los cheques en custodia y el saldo del sistema anterior.',
                'caja',
                ['administrador', 'contador', 'administrativo', 'consulta'],
            ),
            // Contar los billetes es trabajo de mostrador: lo hace quien tiene
            // el cajón adelante.
            new PermissionDefinition(
                'caja.arquear',
                'Registrar arqueos',
                'Cargar el conteo del efectivo que hay en el cajón para compararlo con el saldo del sistema.',
                'caja',
                ['administrador', 'contador', 'administrativo'],
            ),
            // Revisarlo queda en el contador. Lo ideal es una segunda firma;
            // si el equipo chico obliga a autorrevisar, el sistema lo admite
            // pero lo deja visible en el arqueo y en la planilla.
            new PermissionDefinition(
                'caja.revisar-arqueo',
                'Revisar arqueos',
                'Dar por revisado un arqueo. Si lo revisa la misma persona que lo contó, queda a la vista en el arqueo y en la planilla.',
                'caja',
                ['administrador', 'contador'],
            ),
            // Imputar la diferencia mueve plata contra `CASH_DIFFERENCE` sin
            // que haya entrado ni salido nada del cajón. Es la operación más
            // delicada del circuito y queda en el contador.
            new PermissionDefinition(
                'caja.ajustar-diferencia',
                'Imputar diferencias de arqueo',
                'Registrar como sobrante o faltante la diferencia entre lo contado y el saldo del sistema. Mueve el saldo sin que entre ni salga dinero del cajón.',
                'caja',
                ['administrador', 'contador'],
                sensitive: true,
            ),
            // Abrir los libros con el saldo que ya está en el cajón. Se hace
            // una sola vez en la vida del sistema y define todos los saldos
            // posteriores: solo el administrador.
            new PermissionDefinition(
                'caja.abrir-saldo-inicial',
                'Abrir la caja con el saldo inicial',
                'Registrar el saldo con el que arranca la caja en el sistema. Se hace una sola vez y define todos los saldos posteriores.',
                'caja',
                ['administrador'],
                sensitive: true,
            ),
            // Con qué cuentas opera la caja: dónde se deposita y qué créditos se identifican.
            new PermissionDefinition(
                'caja.cuentas',
                'Administrar las cuentas de la caja',
                'Habilitar, dar de baja y elegir la predeterminada de las cuentas bancarias con las que opera la caja de Haberes, y cambiar desde cuándo vale cada una.',
                'caja',
                ['administrador'],
                sensitive: true,
            ),

            // Cerrar congela los totales del día y traba las operaciones
            // retroactivas. El rol de contador ya se describe como quien
            // «cierra períodos».
            new PermissionDefinition(
                'cierres.ver',
                'Ver cierres',
                'Consultar los cierres de caja y el calendario de días cerrados y abiertos.',
                'cierres',
                ['administrador', 'contador', 'administrativo', 'consulta'],
            ),
            new PermissionDefinition(
                'cierres.cerrar',
                'Cerrar períodos',
                'Cerrar un día o un período de caja: congela sus totales e impide registrar movimientos con fecha dentro de él.',
                'cierres',
                ['administrador', 'contador'],
            ),
            // Reabrir deshace un cierre que el área pudo haber archivado en
            // papel. Va más arriba que cerrar: el administrador y el contador,
            // con motivo obligatorio y rastro en `audit_events`.
            new PermissionDefinition(
                'cierres.reabrir',
                'Reabrir períodos cerrados',
                'Deshacer un cierre para volver a admitir movimientos en esas fechas. Exige un motivo y queda en la auditoría.',
                'cierres',
                ['administrador', 'contador'],
                sensitive: true,
            ),
            // Bajar la planilla del día es leer un cierre, no modificarlo.
            new PermissionDefinition(
                'cierres.exportar',
                'Descargar la planilla del cierre',
                'Bajar la planilla de un cierre ya hecho. Es solo lectura: no modifica el cierre.',
                'cierres',
                ['administrador', 'contador', 'administrativo', 'consulta'],
            ),
            // No cambia un solo número —el snapshot del cierre está
            // congelado— pero reemplaza un documento oficial que pudo
            // imprimirse y firmarse. El anterior queda, y el motivo también.
            //
            // Va acá y no detrás de una comprobación de entorno: el día que
            // sirve de verdad es cuando se arregla el generador y ya hay
            // cierres reales, o sea en producción.
            new PermissionDefinition(
                'cierres.regenerar-planilla',
                'Rehacer la planilla de un cierre',
                'Generar de nuevo la planilla de un cierre ya emitido. Los números no cambian, pero reemplaza un documento que pudo haberse firmado; el anterior se conserva.',
                'cierres',
                ['administrador'],
                sensitive: true,
            ),
        );
    }
}
