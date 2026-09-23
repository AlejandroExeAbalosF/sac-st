<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Support\CashBalance;
use App\Modules\Shared\Models\CashBox;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * La apertura de los libros, desde la pantalla.
 *
 * Es el primer acto de la vida del sistema y ocurre una sola vez. Hasta
 * ahora solo se podía invocar por consola, y un sistema que maneja dinero
 * de terceros no puede pedir que alguien abra un `tinker` en producción
 * para declarar ocho millones de pesos.
 */
class PantallaDeAperturaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

    }

    /**
     * Solo el administrador abre los libros.
     *
     * Define todos los saldos posteriores: contra ese número se compara
     * cada arqueo y cada cierre del sistema.
     */
    public function test_solo_el_administrador_abre_los_libros(): void
    {
        foreach (['administrativo', 'contador', 'consulta'] as $rol) {
            $this->actingAs($this->operador($rol))
                ->get('/caja/apertura')
                ->assertForbidden();
        }

        $this->actingAs($this->operador('administrador'))
            ->get('/caja/apertura')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('caja/apertura')
                ->where('existing', null)
            );
    }

    /**
     * El formulario ofrece dónde puede estar la plata, no de quién es.
     *
     * Las cuentas de atribución exigen el expediente y la cuota que el
     * sistema todavía no tiene: ese es justamente el trabajo que
     * `LEGACY_FUNDS` posterga.
     */
    public function test_el_formulario_solo_ofrece_cuentas_de_ubicacion(): void
    {
        $this->actingAs($this->operador('administrador'))
            ->get('/caja/apertura')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $codigos = array_column($page->toArray()['props']['accounts'], 'code');

                $this->assertContains(LedgerAccount::CashOnHand->value, $codigos);
                $this->assertContains(LedgerAccount::ChequesInCustody->value, $codigos);
                $this->assertContains(LedgerAccount::BankAccount->value, $codigos);

                $this->assertNotContains(LedgerAccount::UnassignedFunds->value, $codigos);
                $this->assertNotContains(LedgerAccount::LegacyFunds->value, $codigos);
            });
    }

    public function test_abrir_deja_el_saldo_y_su_contraasiento(): void
    {
        $this->actingAs($this->operador('administrador'))
            ->post('/caja/apertura', [
                'cashBoxId' => $this->caja(),
                'currency' => 'ARS',
                'date' => '2026-05-31',
                'balances' => [
                    LedgerAccount::CashOnHand->value => '9852300.00',
                    LedgerAccount::ChequesInCustody->value => '673804.70',
                    // Una cuenta en cero no es una pata del asiento.
                    LedgerAccount::BankAccount->value => '',
                ],
                'notes' => 'Efectivo existente al arranque.',
            ])
            ->assertRedirect('/caja/dia');

        $saldos = app(CashBalance::class);

        $this->assertSame('9852300.00', $saldos->of(LedgerAccount::CashOnHand, $this->caja()));
        $this->assertSame('673804.70', $saldos->of(LedgerAccount::ChequesInCustody, $this->caja()));
        $this->assertSame('0.00', $saldos->of(LedgerAccount::BankAccount, $this->caja()));

        // El contraasiento entero, que es lo que después drena.
        $this->assertSame('10526104.70', $saldos->of(LedgerAccount::LegacyFunds, $this->caja()));
    }

    /**
     * «DEPOSITOS DIRECTOS» es la tercera columna, y abre pata bancaria.
     *
     * Es lo que las empresas depositaron derecho en la cuenta de la
     * Secretaría. Sin decir en qué cuenta está, la línea no dice dónde
     * quedó el dinero — y `journal_lines` es append-only: no se corrige
     * después, se revierte.
     */
    public function test_el_saldo_de_depositos_directos_exige_su_cuenta(): void
    {
        $this->actingAs($this->operador('administrador'))
            ->post('/caja/apertura', [
                'cashBoxId' => $this->caja(),
                'currency' => 'ARS',
                'date' => '2026-05-31',
                'balances' => [LedgerAccount::BankAccount->value => '1902907.01'],
            ])
            ->assertSessionHasErrors('bankAccountId');

        $this->assertSame(0, DB::table('financial_events')->count());
    }

    /** Con la cuenta, la pata queda atribuida. */
    public function test_la_apertura_declara_los_tres_saldos_de_la_planilla(): void
    {
        $cuenta = $this->cuentaBancaria();

        $this->actingAs($this->operador('administrador'))
            ->post('/caja/apertura', [
                'cashBoxId' => $this->caja(),
                'currency' => 'ARS',
                'date' => '2026-05-31',
                'balances' => [
                    LedgerAccount::CashOnHand->value => '9852300.00',
                    LedgerAccount::ChequesInCustody->value => '673804.70',
                    LedgerAccount::BankAccount->value => '1902907.01',
                ],
                'bankAccountId' => $cuenta,
            ])
            ->assertRedirect('/caja/dia');

        $saldos = app(CashBalance::class);

        $this->assertSame('1902907.01', $saldos->of(LedgerAccount::BankAccount, $this->caja()));

        // El contraasiento suma las tres columnas del 01/06/2026.
        $this->assertSame('12429011.71', $saldos->of(LedgerAccount::LegacyFunds, $this->caja()));

        // Y solo la pata bancaria lleva cuenta: las otras dos no la tienen.
        $this->assertSame(
            [$cuenta],
            DB::table('journal_lines')
                ->whereNotNull('bank_account_id')
                ->pluck('bank_account_id')
                ->map(intval(...))
                ->all(),
        );
    }

    /** Abrir dos veces duplicaría todo el saldo histórico. */
    public function test_no_se_puede_abrir_dos_veces(): void
    {
        $datos = [
            'cashBoxId' => $this->caja(),
            'currency' => 'ARS',
            'date' => '2026-05-31',
            'balances' => [LedgerAccount::CashOnHand->value => '100000.00'],
        ];

        $admin = $this->operador('administrador');

        $this->actingAs($admin)->post('/caja/apertura', $datos)->assertRedirect();
        $this->actingAs($admin)->post('/caja/apertura', $datos)->assertSessionHasErrors('balances');

        $this->assertSame(
            '100000.00',
            app(CashBalance::class)->of(LedgerAccount::CashOnHand, $this->caja()),
        );
    }

    /** Con los libros abiertos no hay formulario: hay un asiento que leer. */
    public function test_la_pantalla_muestra_la_apertura_ya_hecha(): void
    {
        $admin = $this->operador('administrador');

        $this->actingAs($admin)->post('/caja/apertura', [
            'cashBoxId' => $this->caja(),
            'currency' => 'ARS',
            'date' => '2026-05-31',
            'balances' => [LedgerAccount::CashOnHand->value => '9852300.00'],
        ]);

        $this->actingAs($admin)
            ->get('/caja/apertura')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('existing.date', '2026-05-31')
                ->has('existing.lines', 1)
                ->where('existing.lines.0.amount', '9852300.00')
            );
    }

    public function test_el_saldo_inicial_no_puede_tener_fecha_futura(): void
    {
        $this->actingAs($this->operador('administrador'))
            ->post('/caja/apertura', [
                'cashBoxId' => $this->caja(),
                'currency' => 'ARS',
                'date' => BusinessDate::today()->addDay()->toDateString(),
                'balances' => [LedgerAccount::CashOnHand->value => '100.00'],
            ])
            ->assertSessionHasErrors('date');
    }

    /**
     * La caja del día avisa mientras los libros estén cerrados.
     *
     * Es lo único que importa de esa pantalla hasta que se abra: todos los
     * saldos son cero aunque el cajón esté lleno.
     */
    public function test_la_caja_avisa_que_falta_la_apertura(): void
    {
        $this->actingAs($this->operador('administrativo'))
            ->get('/caja/dia')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('needsOpening', true)
                // El administrativo lo ve, pero no puede resolverlo.
                ->where('can.open', false)
            );

        $this->actingAs($this->operador('administrador'))->post('/caja/apertura', [
            'cashBoxId' => $this->caja(),
            'currency' => 'ARS',
            'date' => '2026-05-31',
            'balances' => [LedgerAccount::CashOnHand->value => '100.00'],
        ]);

        $this->actingAs($this->operador('administrativo'))
            ->get('/caja/dia')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('needsOpening', false));
    }

    /**
     * El importe se escribe como está en la planilla.
     *
     * **La planilla del área usa `9.852.300,00`, y eso es lo que el
     * operador copia.** Para `numeric` eso no es un número: la respuesta
     * volvía con la clave `balances.CASH_ON_HAND`, que la pantalla no
     * pintaba en ningún lado, y el botón «Abrir los libros» parecía no
     * hacer nada.
     *
     * `Decimal::parse` ya lee ese formato —es el que interpreta los
     * archivos del banco—, así que la incoherencia era validar con una
     * regla más estricta que el parser del propio request.
     */
    #[DataProvider('formatosDeImporte')]
    public function test_el_importe_se_puede_escribir_como_en_la_planilla(string $escrito): void
    {
        $this->actingAs($this->operador('administrador'))
            ->post('/caja/apertura', [
                'cashBoxId' => $this->caja(),
                'currency' => 'ARS',
                'date' => '2026-05-31',
                'balances' => [LedgerAccount::CashOnHand->value => $escrito],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            '9852300.00',
            app(CashBalance::class)->of(
                LedgerAccount::CashOnHand,
                $this->caja(),
                Currency::Ars,
                CarbonImmutable::parse('2026-05-31'),
            ),
        );
    }

    /** @return array<string, array{0: string}> */
    public static function formatosDeImporte(): array
    {
        return [
            'como en la planilla' => ['9.852.300,00'],
            'sin decimales' => ['9.852.300'],
            'coma decimal sin miles' => ['9852300,00'],
            'con el signo pesos' => ['$ 9.852.300,00'],
            'plano' => ['9852300.00'],
        ];
    }

    /**
     * Lo que no es un importe falla, y falla con nombre.
     *
     * Aceptar el formato de la planilla no puede volverse tragar
     * cualquier cosa: si `Decimal::parse` no lo entiende, el valor llega
     * crudo a `numeric` y el error sale con la clave del renglón —para
     * que la pantalla lo muestre al lado de su campo— y con el nombre que
     * el operador ve, no con el código del plan de cuentas.
     */
    public function test_un_importe_ilegible_se_rechaza_con_el_nombre_del_renglon(): void
    {
        $respuesta = $this->actingAs($this->operador('administrador'))
            ->post('/caja/apertura', [
                'cashBoxId' => $this->caja(),
                'currency' => 'ARS',
                'date' => '2026-05-31',
                'balances' => [LedgerAccount::CashOnHand->value => 'ocho millones'],
            ]);

        $clave = 'balances.'.LedgerAccount::CashOnHand->value;

        $respuesta->assertSessionHasErrors($clave);

        $mensaje = $respuesta->getSession()->get('errors')->getBag('default')->first($clave);

        $this->assertStringContainsString('efectivo en caja', $mensaje);
        $this->assertStringNotContainsString('CASH_ON_HAND', $mensaje);

        $this->assertSame(0, DB::table('journal_lines')->count());
    }

    private function cuentaBancaria(): int
    {
        return (int) DB::table('bank_accounts')->insertGetId([
            'label' => 'Cta. Cte. 2693 — Haberes',
            'bank_name' => 'Banco Macro',
            'account_number' => '310000123456789',
            'currency' => 'ARS',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function caja(): int
    {
        return (int) CashBox::query()->where('code', CashBox::HABERES)->value('id');
    }
}
