<?php

declare(strict_types=1);

namespace Tests;

use App\Models\User;
use App\Modules\Ledger\Actions\RecordCashCount;
use App\Modules\Ledger\Actions\ReviewCashCount;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Models\CashCount;
use App\Modules\Ledger\Models\CashCountLine;
use App\Modules\Ledger\Support\CashBalance;
use App\Support\Ui\ToastType;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Inertia\Support\SessionKey;
use Laravel\Fortify\Features;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * El catálogo se siembra **una sola vez por proceso**, no por test.
     *
     * Laravel lo corre dentro del `migrate:fresh` de `RefreshDatabase`, que
     * está protegido por `RefreshDatabaseState::$migrated`; de ahí en
     * adelante cada test hereda permisos, cajas, series y etiquetas por la
     * transacción que lo envuelve, sin volver a escribirlos.
     *
     * Antes los rehacía cada test —617 veces solo los permisos— y eran unos
     * tres minutos de los ocho que tardaba la suite.
     *
     * @var class-string<Seeder>
     */
    protected $seeder = CatalogosSeeder::class;

    /**
     * La confirmación que quedó flasheada, después de una redirección.
     *
     * `Inertia::flash()` no escribe una prop: deja el payload en la sesión
     * bajo su propia clave y lo emite en la respuesta siguiente. Sobre una
     * redirección todavía no hay página que assertear con `hasFlash()`, así
     * que la sesión es el único lugar donde mirar —y el nombre de esa clave
     * es un detalle de Inertia que conviene que viva en un solo archivo—.
     */
    protected function assertToast(
        string $message,
        ?string $description = null,
        ToastType $type = ToastType::Success,
    ): void {
        $flasheado = session(SessionKey::FLASH_DATA);

        $this->assertIsArray($flasheado, 'No quedó ninguna confirmación flasheada.');
        $this->assertArrayHasKey('toast', $flasheado, 'Lo flasheado no es un toast.');

        $esperado = array_filter([
            'type' => $type->value,
            'message' => $message,
            'description' => $description,
        ], static fn (?string $valor): bool => $valor !== null);

        $this->assertSame($esperado, $flasheado['toast']);
    }

    /**
     * Un usuario con rol, listo para atravesar las rutas de los módulos.
     *
     * Las rutas están detrás de `can:`, así que un usuario recién creado
     * recibe 403 en todas. El seeder es idempotente y se puede llamar las
     * veces que haga falta.
     */
    protected function operador(string $role = 'administrativo'): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /**
     * Un importe desarmado en billetes de verdad.
     *
     * La apertura exige el detalle del cajón, y a la mayoría de los tests
     * la composición no les importa —les importa el total—. Repartirlo de
     * mayor a menor evita el `[1 => 9852300]` que dejaba escenarios con
     * nueve millones de billetes de un peso: un dato falso que después
     * alguien lee como si fuera el caso real.
     *
     * @return array<int, int>
     */
    protected function billetesPara(string $importe): array
    {
        $centavos = (int) bcmul($importe, '100', 0);
        $billetes = [];

        foreach (CashCountLine::suggestedDenominations(Currency::Ars) as $denominacion) {
            $cuantos = intdiv($centavos, $denominacion * 100);

            if ($cuantos > 0) {
                $billetes[$denominacion] = $cuantos;
                $centavos -= $cuantos * $denominacion * 100;
            }
        }

        if ($centavos !== 0) {
            throw new RuntimeException(sprintf(
                'El importe %s no se puede pagar con los billetes que circulan.',
                $importe,
            ));
        }

        return $billetes;
    }

    /** Un arqueo real y revisado para los tests cuyo objeto principal es el cierre. */
    protected function arqueoListoParaCerrar(
        int $cashBoxId,
        string $date,
        ?User $reviewer = null,
    ): CashCount {
        $reviewer ??= User::factory()->create();
        $fecha = CarbonImmutable::parse($date);
        $saldo = app(CashBalance::class)->of(
            LedgerAccount::CashOnHand,
            $cashBoxId,
            Currency::Ars,
            $fecha,
        );

        /*
         * El arqueo tiene que cuadrar, porque el cierre ya no acepta una
         * diferencia sin imputar. Se cuenta un billete de $1 cuando hay con
         * qué y el resto se declara sin recontar: entre los dos dan el saldo
         * del libro, que es lo que el cierre exige.
         */
        if (bccomp($saldo, '0.00', 2) < 0) {
            throw new RuntimeException(sprintf(
                'El escenario deja el efectivo en %s: un cajón no puede tener menos de cero. '
                .'Revisá las fechas de los asientos antes de pedir un arqueo.',
                $saldo,
            ));
        }

        $cuenta = bccomp($saldo, '1.00', 2) >= 0;
        $noRecontado = $cuenta ? bcsub($saldo, '1.00', 2) : $saldo;

        $arqueo = app(RecordCashCount::class)->handle(
            cashBoxId: $cashBoxId,
            countedOn: $fecha,
            denominations: $cuenta ? [1 => 1] : [],
            actorId: $reviewer->id,
            uncountedAmount: $noRecontado,
            uncountedReason: bccomp($noRecontado, '0.00', 2) === 0
                ? null
                : 'Saldo declarado en el armado del escenario de prueba.',
        );

        return app(ReviewCashCount::class)->handle($arqueo, $reviewer->id);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
