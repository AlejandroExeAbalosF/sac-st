<?php

declare(strict_types=1);

use App\Modules\Banking\Models\BankStatementImport;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\DepositTicket;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\Haber;
use App\Modules\Ledger\Models\FundReceipt;
use App\Support\Navigation\Breadcrumbs;
use App\Support\Navigation\Crumb;

/*
| El árbol de navegación del sistema.
|
| Un camino por pantalla, contra el nombre de su ruta. El orden es el de
| `routes/web.php` —Shared → Ledger → Banking → Haberes— para que este archivo
| y el de rutas se lean en paralelo.
|
| Reglas que se sostienen acá:
|
| 1. **El último eslabón nombra el caso**, no el tipo de pantalla. «EXP-1234/2026»
|    y no «Detalle del expediente»: si el operador tiene tres pestañas abiertas,
|    la miga es lo único que las distingue.
| 2. **El penúltimo eslabón es el «volver»**. Por eso apunta siempre a la ruta
|    padre concreta y no al historial: se comporta igual se haya llegado por el
|    menú, por un enlace directo o después de guardar.
| 3. **Sin `href` cuando el eslabón no es una pantalla.** «Configuración» agrupa
|    dos pantallas y no es ninguna de las dos.
|
| Ojo con el modo estricto de Eloquent (`Model::shouldBeStrict`): cualquier
| relación que se lea acá tiene que pedirse con `loadMissing`, o revienta por
| lazy loading. En los controladores ya vienen cargadas; acá no se puede asumir.
*/

// ── Shared ───────────────────────────────────────────────────────────────

Breadcrumbs::for('inicio', fn (): array => [
    Crumb::make('Inicio'),
]);

Breadcrumbs::for('personas.index', fn (): array => [
    Crumb::make('Personas'),
]);

// ── Ledger ───────────────────────────────────────────────────────────────

Breadcrumbs::for('caja.dia', fn (): array => [
    Crumb::make('Caja del día'),
]);

Breadcrumbs::for('caja.apertura.index', fn (): array => [
    Crumb::make('Caja del día', route('caja.dia')),
    Crumb::make('Apertura'),
]);

Breadcrumbs::for('caja.pagos-anteriores.index', fn (): array => [
    Crumb::make('Caja del día', route('caja.dia')),
    Crumb::make('Haberes anteriores'),
]);

Breadcrumbs::for('caja.arqueos.index', fn (): array => [
    Crumb::make('Arqueos'),
]);

Breadcrumbs::for('caja.cierres.index', fn (): array => [
    Crumb::make('Cierres'),
]);

Breadcrumbs::for('caja.calendario', fn (): array => [
    Crumb::make('Calendario'),
]);

// ── Banking ──────────────────────────────────────────────────────────────

/*
 * «Banco» es la pantalla de movimientos y no un rótulo suelto: es la decisión
 * que ya está tomada en la barra lateral —se entra por movimientos, y extractos
 * y cuentas cuelgan de ahí—, y las migas no pueden contar otra jerarquía.
 */
Breadcrumbs::for('banco.movimientos.index', fn (): array => [
    Crumb::make('Banco'),
]);

Breadcrumbs::for('banco.extractos.index', fn (): array => [
    Crumb::make('Banco', route('banco.movimientos.index')),
    Crumb::make('Extractos'),
]);

Breadcrumbs::for('banco.extractos.create', fn (): array => [
    Crumb::make('Banco', route('banco.movimientos.index')),
    Crumb::make('Extractos', route('banco.extractos.index')),
    Crumb::make('Importar'),
]);

/*
 * La previsualización y el alta fallida vuelven a dibujar la pantalla de
 * importar: mismo lugar, mismo camino.
 */
Breadcrumbs::alias('banco.extractos.preview', 'banco.extractos.create');
Breadcrumbs::alias('banco.extractos.store', 'banco.extractos.create');

Breadcrumbs::for('banco.extractos.show', fn (BankStatementImport $import): array => [
    Crumb::make('Banco', route('banco.movimientos.index')),
    Crumb::make('Extractos', route('banco.extractos.index')),
    Crumb::make($import->original_filename),
]);

Breadcrumbs::for('banco.cuentas.index', fn (): array => [
    Crumb::make('Banco', route('banco.movimientos.index')),
    Crumb::make('Cuentas bancarias'),
]);

// ── Haberes ──────────────────────────────────────────────────────────────

Breadcrumbs::for('expedientes.index', fn (): array => [
    Crumb::make('Haberes'),
]);

Breadcrumbs::for('expedientes.create', fn (): array => [
    Crumb::make('Haberes', route('expedientes.index')),
    Crumb::make('Nuevo expediente'),
]);

Breadcrumbs::for('expedientes.show', fn (Expediente $expediente): array => [
    Crumb::make('Haberes', route('expedientes.index')),
    Crumb::make($expediente->display_number),
]);

Breadcrumbs::for('expedientes.edit', fn (Expediente $expediente): array => [
    Crumb::make('Haberes', route('expedientes.index')),
    Crumb::make($expediente->display_number, route('expedientes.show', $expediente)),
    Crumb::make('Editar'),
]);

Breadcrumbs::for('haberes.haber.create', fn (Expediente $expediente): array => [
    Crumb::make('Haberes', route('expedientes.index')),
    Crumb::make($expediente->display_number, route('expedientes.show', $expediente)),
    Crumb::make('Nuevo haber'),
]);

Breadcrumbs::for('haberes.haber.show', function (Expediente $expediente, Haber $haber): array {
    $haber->loadMissing('beneficiary');

    return [
        Crumb::make('Haberes', route('expedientes.index')),
        Crumb::make($expediente->display_number, route('expedientes.show', $expediente)),
        Crumb::make($haber->beneficiary->name),
    ];
});

/*
 * Las pantallas de cuota cuelgan del haber, no del listado: el parámetro de la
 * ruta es la cuota, así que el expediente y el beneficiario se reconstruyen
 * desde ella. El tramo común va en una variable y no en una función global
 * para que este archivo se pueda volver a cargar sin redeclarar nada.
 *
 * @var Closure(BeneficiaryInstallment): list<Crumb> $hastaElHaber
 */
$hastaElHaber = static function (BeneficiaryInstallment $installment): array {
    $installment->loadMissing(['haber.beneficiary', 'haber.expediente']);

    $haber = $installment->haber;
    $expediente = $haber->expediente;

    return [
        Crumb::make('Haberes', route('expedientes.index')),
        Crumb::make($expediente->display_number, route('expedientes.show', $expediente)),
        Crumb::make(
            $haber->beneficiary->name,
            route('haberes.haber.show', [$expediente, $haber]),
        ),
    ];
};

Breadcrumbs::for(
    'haberes.installments.order.create',
    fn (BeneficiaryInstallment $installment): array => [
        ...$hastaElHaber($installment),
        Crumb::make("Orden de pago · cuota {$installment->installment_number}"),
    ],
);

/*
 * Cargar el comprobante es un rodeo dentro del trabajo sobre la cuota, no
 * un destino: el camino vuelve al haber, que es de donde vino el operador.
 */
Breadcrumbs::for(
    'haberes.installments.ticket.create',
    fn (BeneficiaryInstallment $installment): array => [
        ...$hastaElHaber($installment),
        Crumb::make("Comprobante · cuota {$installment->installment_number}"),
    ],
);

Breadcrumbs::for(
    'haberes.installments.transfer.create',
    fn (BeneficiaryInstallment $installment): array => [
        ...$hastaElHaber($installment),
        Crumb::make('Depósito en el banco'),
    ],
);

Breadcrumbs::for('depositos.index', fn (): array => [
    Crumb::make('Comprobantes'),
]);

/*
 * Las dos colas del egreso. Una sola miga para las dos solapas: la solapa
 * es un filtro de la misma pantalla, no un lugar distinto adonde se llegó.
 */
Breadcrumbs::for('planillas.index', fn (): array => [
    Crumb::make('Planillas'),
]);

Breadcrumbs::for('depositos.search', function (DepositTicket $ticket): array {
    $ticket->loadMissing('expediente');

    return [
        Crumb::make('Comprobantes', route('depositos.index')),
        Crumb::make($ticket->expediente->display_number),
    ];
});

Breadcrumbs::for('recepciones.index', fn (): array => [
    Crumb::make('Recepciones'),
]);

Breadcrumbs::for('recepciones.show', fn (FundReceipt $receipt): array => [
    Crumb::make('Recepciones', route('recepciones.index')),
    Crumb::make('Recepción del '.$receipt->received_date->format('d/m/Y')),
]);

// ── Mi cuenta ────────────────────────────────────────────────────────────

/*
 * «Mi cuenta» no lleva enlace: agrupa sus tres secciones y no es ninguna de
 * las tres. Llevarla a «Perfil» sería un eslabón que apunta a su propio
 * hermano.
 */
Breadcrumbs::for('mi-cuenta.perfil', fn (): array => [
    Crumb::make('Mi cuenta'),
    Crumb::make('Perfil'),
]);

Breadcrumbs::for('mi-cuenta.seguridad', fn (): array => [
    Crumb::make('Mi cuenta'),
    Crumb::make('Seguridad'),
]);

Breadcrumbs::for('mi-cuenta.actividad', fn (): array => [
    Crumb::make('Mi cuenta'),
    Crumb::make('Actividad'),
]);

/*
 * La confirmacion de contrasena la sirve Fortify, pero la pantalla es de esta
 * seccion: sin miga quedaria sin camino y pareceria de otro lado.
 */
Breadcrumbs::for('password.confirm', fn (): array => [
    Crumb::make('Mi cuenta'),
    Crumb::make('Confirmar la contraseña'),
]);

// ── Configuración ────────────────────────────────────────────────────────

/*
 * Tampoco lleva enlace, y por lo mismo: agrupa las pantallas que administran
 * el sistema, que es otra cosa que la cuenta propia.
 */
Breadcrumbs::for('configuracion.usuarios.index', fn (): array => [
    Crumb::make('Configuración'),
    Crumb::make('Usuarios'),
]);

Breadcrumbs::for('configuracion.roles.index', fn (): array => [
    Crumb::make('Configuración'),
    Crumb::make('Roles'),
]);

Breadcrumbs::for('configuracion.accesos.index', fn (): array => [
    Crumb::make('Configuración'),
    Crumb::make('Accesos y sesiones'),
]);

Breadcrumbs::for('configuracion.auditoria.index', fn (): array => [
    Crumb::make('Configuración'),
    Crumb::make('Auditoría'),
]);
