<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Shared\Enums\SystemRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles y permisos del área.
 *
 * Idempotente a propósito: se corre las veces que haga falta y en
 * producción. Esto NO va en una migración —como se hizo en Bitácora—
 * porque un permiso es un dato, no un cambio de esquema: obligar a migrar
 * para agregar un permiso es una fricción que después nadie respeta.
 *
 * Cada fase agrega sus permisos a este mismo archivo.
 *
 * **Dos responsabilidades distintas, desde que los roles se editan desde
 * Configuración:**
 *
 * 1. *El catálogo sigue siendo declarativo.* Un permiso existe porque hay
 *    una ruta que lo exige, así que el seeder crea los que faltan y borra
 *    los que se retiraron del mapa. Eso no se negocia con nadie.
 * 2. *La asignación es una línea de base, no una imposición.* Se siembra
 *    una sola vez, cuando el rol todavía no tiene ningún permiso. Antes
 *    acá había un `syncPermissions` incondicional, y con la pantalla de
 *    roles en el sistema eso significaba que el próximo `db:seed` borraba
 *    en silencio lo que el área había configurado.
 *
 * Para volver a la matriz de fábrica —a propósito, no de rebote— está
 * `php artisan roles:restaurar-predeterminados`.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * @var array<string, list<string>> permiso => roles que lo reciben
     */
    private const PERMISSIONS = [
        // Identidad y acceso — Fase 2
        'usuarios.ver' => ['administrador'],
        'usuarios.crear' => ['administrador'],
        'usuarios.editar' => ['administrador'],
        'usuarios.desactivar' => ['administrador'],
        'usuarios.restablecer-password' => ['administrador'],
        'roles.gestionar' => ['administrador'],

        // Auditoría — Fase 2
        'auditoria.accesos.ver' => ['administrador', 'contador'],
        'auditoria.sesiones.ver' => ['administrador', 'contador'],
        'auditoria.sesiones.revocar' => ['administrador'],

        // Maestro de personas — Fase 2
        // El alta ocurre a mitad de otro trámite, desde el buscador del
        // formulario, así que no la puede disparar cualquiera: es un dato
        // que después sale impreso en los comprobantes.
        'personas.ver' => ['administrador', 'contador', 'administrativo', 'consulta'],
        'personas.crear' => ['administrador', 'administrativo'],
        // Corregir una ficha cambia lo que sale impreso en los
        // comprobantes, así que pesa igual que crearla.
        'personas.editar' => ['administrador', 'administrativo'],

        // Haberes en consignación — Fase 2
        'expedientes.ver' => ['administrador', 'contador', 'administrativo', 'consulta'],
        'expedientes.crear' => ['administrador', 'administrativo'],
        'expedientes.editar' => ['administrador', 'administrativo'],
        // Anular deshace el expediente entero y arrastra sus haberes:
        // no lo alcanza quien carga todos los dias.
        'expedientes.anular' => ['administrador', 'contador'],

        // Banco — Fase 2
        // La cuenta del organismo es un maestro que casi no cambia y del
        // que cuelga todo lo bancario: darla de alta mal desvía un
        // extracto entero, así que no la toca quien opera todos los días.
        'banco.cuentas.ver' => ['administrador', 'contador', 'administrativo', 'consulta'],
        'banco.cuentas.gestionar' => ['administrador', 'contador'],
        'banco.extractos.ver' => ['administrador', 'contador', 'administrativo', 'consulta'],
        'banco.extractos.importar' => ['administrador', 'contador', 'administrativo'],
        // Los dos siguientes declaran que algo no entra en la
        // contabilidad, y eso pesa distinto que cargar el archivo:
        // «fuera del circuito» saca un movimiento de la cola de
        // pendientes, y revertir borra movimientos ya registrados.
        'banco.movimientos.ignorar' => ['administrador', 'contador'],
        'banco.extractos.revertir' => ['administrador', 'contador'],

        // Adjuntos — Fase 2
        // Toda la evidencia del sistema pasa por acá: el ticket del
        // expediente, el extracto importado, el Pase firmado.
        'adjuntos.ver' => ['administrador', 'contador', 'administrativo', 'consulta'],
        'adjuntos.cargar' => ['administrador', 'contador', 'administrativo'],
        // Lo reservado no circula: una nota médica, un dato sensible del
        // trabajador. Quien consulta expedientes todo el día no lo abre.
        'adjuntos.ver-reservados' => ['administrador', 'contador'],

        // Numeración — Fase 2
        // El catálogo de series no se edita desde la interfaz: lo siembra
        // un seeder. El permiso existe para consultarlo.
        'series.ver' => ['administrador', 'contador'],

        // Tickets de depósito — Fase 2
        // El comprobante que llega con el expediente y espera aparecer en
        // el extracto. Lo carga quien recibe el expediente.
        'depositos.ver' => ['administrador', 'contador', 'administrativo', 'consulta'],
        'depositos.registrar' => ['administrador', 'contador', 'administrativo'],
        // Vincular es afirmar que ese papel y ese crédito son el mismo
        // hecho. Todavía no mueve plata —eso es la recepción— pero es la
        // lectura sobre la que después se va a imputar.
        'depositos.vincular' => ['administrador', 'contador', 'administrativo'],
        // Descartar afirma que ese depósito no va a aparecer nunca.
        'depositos.descartar' => ['administrador', 'contador'],

        // Recepciones y asignaciones — Fase 2
        // Acá empieza a moverse plata de verdad. Registrar una recepción
        // asienta en los libros que ese dinero entró; asignarla dice de
        // quién es y deja la cuota financiada. Las dos son trabajo diario
        // del administrativo: pedirle el contador para cada depósito
        // frenaría el circuito sin agregar control, porque nada de esto
        // sale del organismo todavía.
        'recepciones.ver' => ['administrador', 'contador', 'administrativo', 'consulta'],
        'recepciones.registrar' => ['administrador', 'contador', 'administrativo'],
        'recepciones.asignar' => ['administrador', 'contador', 'administrativo'],
        // Revertir sí pesa distinto: deshace un asiento y devuelve dinero
        // a la cola de no identificados. Queda para la tanda siguiente,
        // pero el permiso se declara ahora para que el reparto de roles
        // se discuta entero y no de a pedazos.
        'recepciones.revertir' => ['administrador', 'contador'],
        // Reconocer un excedente lo saca de la cola de pendientes sin que
        // nadie lo haya identificado: es una decisión, no una carga.
        'recepciones.reconocer-excedente' => ['administrador', 'contador'],

        // Comprobantes — Fase 2
        // El recibo de ingreso es el papel que el empleador se lleva, y
        // se emite en el mostrador: lo confecciona quien atiende, que es
        // el mismo que registra la recepción.
        'recibos.ver' => ['administrador', 'contador', 'administrativo', 'consulta'],
        'recibos.emitir' => ['administrador', 'contador', 'administrativo'],
        // Anular pesa distinto: deja sin respaldo un documento ya
        // entregado, y si esa cuota llegó a tener Orden de Pago arrastra
        // un procedimiento escalonado (§2.7).
        'recibos.anular' => ['administrador', 'contador'],

        // Caja — Fase 2
        // Llevar al banco el efectivo que el beneficiario no retiró. Mueve
        // dinero pero no lo saca del organismo: cambia de la caja a la
        // cuenta, y sigue siendo del mismo beneficiario. Por eso va con el
        // mismo reparto que registrar una recepción.
        'caja.trasladar' => ['administrador', 'contador', 'administrativo'],
        // Confirmar contra el extracto es trabajo de conciliación, igual
        // que vincular un depósito.
        'caja.confirmar-traslado' => ['administrador', 'contador', 'administrativo'],

        // Órdenes de Pago y Pases — Fase 3
        // Acá el circuito sale del organismo: la Orden y su Pase le piden
        // al SAF que transfiera el dinero de un tercero. Emitirlas es
        // trabajo del área contable, no de quien atiende el mostrador, y
        // por eso el administrativo no las alcanza.
        'ordenes.ver' => ['administrador', 'contador', 'administrativo', 'consulta'],
        'ordenes.emitir' => ['administrador', 'contador'],
        // Anular deja sin efecto un documento que el organismo puede tener
        // en la mano, y arrastra su Pase.
        'ordenes.anular' => ['administrador', 'contador'],

        // Verificar un CBU es la puerta de la Orden: sin cuenta verificada
        // el organismo no transfiere (§2.2.9). No es carga de datos sino
        // cotejo contra la foja del expediente, y el caso que lo justifica
        // es real: un CVU de billetera informado como CBU volvió con el
        // expediente observado.
        'personas.verificar-cbu' => ['administrador', 'contador'],
        /*
         * ─── Capacidades de desarrollo ────────────────────────────────
         *
         * El prefijo `dev.` no es decorativo: `Gate::before` lo lee para
         * dejar estas capacidades **fuera del comodín del administrador**,
         * que recibe todo lo demás sin enumerarlo. Las tiene `super-admin`
         * y nadie más.
         *
         * Acá van las que no son del área: atajos para atravesar el
         * circuito con datos inventados, pantallas a medio construir,
         * funciones en prueba.
         */
        'dev.forzar-cbu' => ['super-admin'],

        // Egresos al beneficiario — Fase 3
        // El otro extremo del circuito: el dinero saliendo hacia su dueño.
        // Entregar por mostrador es trabajo del que atiende —el trabajador
        // está enfrente, firma y se lleva su plata— y va con el mismo
        // reparto que cobrar, que es el acto simétrico.
        'egresos.registrar' => ['administrador', 'contador', 'administrativo'],
        // Validar la transferencia es lo contrario: el contador coteja
        // Orden, informe y débito, y recién esa firma convierte el pago en
        // un hecho (§2.3.4). El permiso se declara ahora aunque su pantalla
        // llegue con `remisiones`, para que el reparto de roles se discuta
        // entero y no de a pedazos.
        'egresos.validar' => ['administrador', 'contador'],

        // Arqueo y cierre — Fase 4
        // El saldo de caja y el libro del día los mira cualquiera que ya
        // mire expedientes: es la misma información, ordenada por fecha en
        // vez de por trámite.
        'caja.ver' => ['administrador', 'contador', 'administrativo', 'consulta'],
        // Contar los billetes es trabajo de mostrador: lo hace quien tiene
        // el cajón adelante.
        'caja.arquear' => ['administrador', 'contador', 'administrativo'],
        // Revisarlo queda en el contador. Lo ideal es una segunda firma;
        // si el equipo chico obliga a autorrevisar, el sistema lo admite
        // pero lo deja visible en el arqueo y en la planilla.
        'caja.revisar-arqueo' => ['administrador', 'contador'],
        // Imputar la diferencia mueve plata contra `CASH_DIFFERENCE` sin
        // que haya entrado ni salido nada del cajón. Es la operación más
        // delicada del circuito y queda en el contador.
        'caja.ajustar-diferencia' => ['administrador', 'contador'],

        // Cerrar congela los totales del día y traba las operaciones
        // retroactivas. El rol de contador ya se describe como quien
        // «cierra períodos».
        'cierres.ver' => ['administrador', 'contador', 'administrativo', 'consulta'],
        'cierres.cerrar' => ['administrador', 'contador'],
        // Reabrir deshace un cierre que el área pudo haber archivado en
        // papel. Va más arriba que cerrar: el administrador y el contador,
        // con motivo obligatorio y rastro en `audit_events`.
        'cierres.reabrir' => ['administrador', 'contador'],
        // Bajar la planilla del día es leer un cierre, no modificarlo.
        'cierres.exportar' => ['administrador', 'contador', 'administrativo', 'consulta'],
        /*
         * Rehacer una planilla ya emitida: solo el administrador.
         *
         * No cambia un solo número --el snapshot del cierre está
         * congelado-- pero reemplaza un documento oficial que pudo
         * imprimirse y firmarse. El anterior queda, y el motivo también.
         *
         * Va acá y no detrás de una comprobación de entorno: el día que
         * sirve de verdad es cuando se arregla el generador y ya hay
         * cierres reales, o sea en producción.
         */
        'cierres.regenerar-planilla' => ['administrador'],

        // Pagar un haber que entró antes de que el sistema existiera.
        //
        // No va con `egresos.registrar` aunque sea el mismo mostrador: en un
        // egreso normal el sistema tiene el expediente, la cuota y el recibo
        // de ingreso contra los cuales verificar. Acá **no hay nada de eso**
        // —la única evidencia es la planilla manual— y por eso el pago exige
        // el criterio del contador, no el del que atiende.
        'caja.pagar-anterior' => ['administrador', 'contador'],

        // Abrir los libros con el saldo que ya está en el cajón. Se hace
        // una sola vez en la vida del sistema y define todos los saldos
        // posteriores: solo el administrador.
        'caja.abrir-saldo-inicial' => ['administrador'],

        // Las fases siguientes agregan acá sus propios permisos:
        // remisiones.*
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (SystemRole::cases() as $role) {
            Role::findOrCreate($role->value, 'web');
        }

        foreach (array_keys(self::PERMISSIONS) as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // Un permiso retirado del mapa desaparece también de los roles que
        // lo tenían: spatie lo saca en cascada al borrar la fila. Es la
        // única forma de que el catálogo converja sin tocar lo que el área
        // configuró sobre los permisos que sí siguen existiendo.
        Permission::query()
            ->where('guard_name', 'web')
            ->whereNotIn('name', array_keys(self::PERMISSIONS))
            ->delete();

        // Los permisos recién creados no están en la caché que el registrar
        // levantó al arrancar; sin este olvido, syncPermissions no los
        // encuentra y explota.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (SystemRole::cases() as $role) {
            $model = Role::findByName($role->value, 'web');

            // La línea de base se siembra una sola vez. Un rol que ya tiene
            // permisos fue configurado por alguien —acá la primera vez, o
            // desde la pantalla después— y esa decisión no se pisa.
            if ($model->permissions()->exists()) {
                continue;
            }

            $model->syncPermissions(self::defaultsFor($role));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Vuelve a dejar cada rol con la matriz declarada acá, descartando lo
     * que se haya configurado desde la pantalla.
     *
     * Lo usa `roles:restaurar-predeterminados`. Es destructivo y por eso
     * tiene su propio comando: nadie lo ejecuta sin haberlo escrito.
     */
    public function restoreDefaults(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (SystemRole::cases() as $role) {
            Role::findByName($role->value, 'web')->syncPermissions(self::defaultsFor($role));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return list<string>
     */
    private static function defaultsFor(SystemRole $role): array
    {
        return array_keys(array_filter(
            self::PERMISSIONS,
            fn (array $roles): bool => in_array($role->value, $roles, true),
        ));
    }
}
