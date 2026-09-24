<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use App\Modules\Ledger\Actions\RegisterOpeningBalance;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Support\CashBalance;
use App\Modules\Shared\Enums\ReceiptStatus;
use App\Modules\Shared\Enums\ReceiptType;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Models\Person;
use App\Modules\Shared\Models\Receipt;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * El pago de un haber anterior al sistema.
 *
 * **Es la única salida de `LEGACY_FUNDS`.** Sin ella la apertura crea una
 * cuenta que sube y no baja jamás, y los expedientes viejos se siguen
 * pagando por planilla en paralelo — lo que la apertura vino a evitar.
 */
class HaberAnteriorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

    }

    public function test_el_pago_baja_lo_que_se_debia_y_saca_el_dinero_del_cajon(): void
    {
        $this->abrirLibros('1000000.00');

        $this->actingAs($this->operador('contador'))
            ->post('/caja/pagos-anteriores', $this->pago('300000.00'))
            ->assertRedirect();

        $saldos = app(CashBalance::class);

        $this->assertSame('700000.00', $saldos->of(LedgerAccount::LegacyFunds, $this->caja()));
        $this->assertSame('700000.00', $saldos->of(LedgerAccount::CashOnHand, $this->caja()));
    }

    /** El beneficiario se lleva su papel, con número de la serie de egresos. */
    public function test_el_pago_emite_su_recibo_de_egreso_sin_cuota(): void
    {
        $this->abrirLibros('1000000.00');

        $this->actingAs($this->operador('contador'))
            ->post('/caja/pagos-anteriores', $this->pago('300000.00'));

        $recibo = Receipt::query()->firstOrFail();

        $this->assertSame(ReceiptType::Expense, $recibo->receipt_type);
        $this->assertSame(ReceiptStatus::Issued, $recibo->status);
        $this->assertSame('300000.00', $recibo->amount);
        $this->assertStringStartsWith('0020/', (string) $recibo->formatted_number);

        // Sin cuota: no existe ninguna para estos casos.
        $this->assertNull($recibo->beneficiary_installment_id);

        // Y la referencia al registro manual, que es todo el respaldo.
        $this->assertSame('131010/2023', $recibo->expediente_number_snapshot);
    }

    /**
     * El libro del día transporta el puntero, aunque acá venga vacío.
     *
     * La fila lleva el id del recibo —para abrir su panel— y el de la
     * cuota que lo originó. En un pago de haber anterior no hay cuota, y
     * ese `null` es lo que la pantalla usa para no ofrecer un enlace que
     * no lleva a ninguna parte.
     *
     * `CashDayBook` vive en Ledger y por eso lo pasa como un entero y nada
     * más: este módulo no puede saber qué es una cuota.
     */
    public function test_la_fila_del_libro_lleva_el_recibo_y_su_cuota(): void
    {
        $this->abrirLibros('1000000.00');

        $this->actingAs($this->operador('contador'))
            ->post('/caja/pagos-anteriores', $this->pago('300000.00'));

        $recibo = Receipt::query()->firstOrFail();

        $this->actingAs($this->operador('contador'))
            ->get('/caja/dia?fecha=2026-06-10')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('book.expense.0.receiptId', $recibo->id)
                ->where('book.expense.0.installmentId', null)
            );
    }

    /**
     * El recibo queda ligado a su asiento.
     *
     * Es lo que permite llegar del papel al libro y al revés. Sin el
     * pivote, el comprobante y el movimiento serían dos hechos sueltos.
     */
    public function test_el_recibo_queda_ligado_a_su_asiento(): void
    {
        $this->abrirLibros('1000000.00');

        $this->actingAs($this->operador('contador'))
            ->post('/caja/pagos-anteriores', $this->pago('300000.00'));

        $recibo = Receipt::query()->firstOrFail();

        $tipo = DB::table('receipt_financial_events')
            ->join('financial_events', 'financial_events.id', '=', 'receipt_financial_events.financial_event_id')
            ->where('receipt_financial_events.receipt_id', $recibo->id)
            ->value('financial_events.event_type');

        $this->assertSame('legacy_disbursement', $tipo);
    }

    /**
     * No se paga del sistema anterior más de lo que se declaró al abrir.
     *
     * Si el saldo no alcanza, o la apertura quedó corta o este pago no
     * corresponde a esta cuenta. Las dos cosas hay que mirarlas antes de
     * que salga el dinero.
     */
    public function test_no_se_puede_pagar_mas_de_lo_que_quedaba(): void
    {
        $this->abrirLibros('100000.00');

        $this->actingAs($this->operador('contador'))
            ->post('/caja/pagos-anteriores', $this->pago('300000.00'))
            ->assertSessionHasErrors('amount');

        $this->assertSame(
            '100000.00',
            app(CashBalance::class)->of(LedgerAccount::LegacyFunds, $this->caja()),
        );
    }

    /**
     * Y tampoco se entrega lo que no está en el cajón.
     *
     * El cajón guarda plata de los dos circuitos, así que el control es
     * sobre el total disponible: si el efectivo se gastó en pagos del
     * circuito nuevo, no hay con qué pagar el viejo aunque se lo deba.
     */
    public function test_no_se_puede_entregar_efectivo_que_no_esta(): void
    {
        // Se declara la deuda vieja en cheques, y el cajón queda sin efectivo.
        $this->abrirLibros(null, cheques: '500000.00');

        $this->actingAs($this->operador('contador'))
            ->post('/caja/pagos-anteriores', $this->pago('100000.00'))
            ->assertSessionHasErrors('amount');
    }

    public function test_el_pago_con_cheque_sale_de_los_cheques_en_custodia(): void
    {
        $this->abrirLibros(null, cheques: '500000.00');

        $this->actingAs($this->operador('contador'))
            ->post('/caja/pagos-anteriores', [
                ...$this->pago('100000.00'),
                'medium' => 'cheque',
            ])
            ->assertRedirect();

        $saldos = app(CashBalance::class);

        $this->assertSame('400000.00', $saldos->of(LedgerAccount::ChequesInCustody, $this->caja()));
        $this->assertSame('400000.00', $saldos->of(LedgerAccount::LegacyFunds, $this->caja()));
    }

    /**
     * El caso viejo que llegó por «DEPOSITOS DIRECTOS».
     *
     * La empresa depositó derecho en la cuenta de la Secretaría y el
     * beneficiario todavía no cobró. Ese pago no sale del cajón: sale del
     * banco, por transferencia, y baja la tercera columna de la planilla.
     */
    public function test_el_pago_por_transferencia_sale_de_los_depositos_directos(): void
    {
        $cuenta = $this->cuentaBancaria();
        $this->abrirLibros(null, banco: '1902907.01', cuentaBancaria: $cuenta);

        $this->actingAs($this->operador('contador'))
            ->post('/caja/pagos-anteriores', [
                ...$this->pago('902907.01'),
                'medium' => 'bank',
                'bankAccountId' => $cuenta,
            ])
            ->assertRedirect();

        $saldos = app(CashBalance::class);

        $this->assertSame('1000000.00', $saldos->of(LedgerAccount::BankAccount, $this->caja()));
        $this->assertSame('1000000.00', $saldos->of(LedgerAccount::LegacyFunds, $this->caja()));
    }

    /** Y la línea bancaria dice de qué cuenta salió, o no se escribe. */
    public function test_el_pago_por_transferencia_exige_la_cuenta(): void
    {
        $this->abrirLibros(null, banco: '1902907.01', cuentaBancaria: $this->cuentaBancaria());

        $this->actingAs($this->operador('contador'))
            ->post('/caja/pagos-anteriores', [
                ...$this->pago('100000.00'),
                'medium' => 'bank',
            ])
            ->assertSessionHasErrors('bankAccountId');

        $this->assertSame(
            '1902907.01',
            app(CashBalance::class)->of(LedgerAccount::BankAccount, $this->caja()),
        );
    }

    /**
     * Con la caja cerrada, la pantalla lo explica y ofrece la salida.
     *
     * **Es el caso que motivó el diálogo.** Antes esto salía como
     * `QueryException` del trigger y el operador veía una traza de
     * PostgreSQL; ahora vuelve a donde estaba con lo necesario para
     * entenderlo y llegar hasta el cierre que lo frenó.
     */
    public function test_la_caja_cerrada_vuelve_a_la_pantalla_con_el_aviso(): void
    {
        $this->abrirLibros('1000000.00');
        $this->arqueoListoParaCerrar($this->caja(), '2026-06-10');

        DB::table('period_closings')->insert([
            'cash_box_id' => $this->caja(),
            'currency' => 'ARS',
            'period_type' => 'daily',
            'period_from' => '2026-06-10',
            'period_to' => '2026-06-10',
            'status' => 'closed',
            'closed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->operador('contador'))
            ->from('/caja/pagos-anteriores')
            ->post('/caja/pagos-anteriores', $this->pago('100000.00'))
            ->assertRedirect('/caja/pagos-anteriores')
            ->assertSessionHas('closedPeriod', fn (array $aviso): bool => $aviso['from'] === '2026-06-10'
                && $aviso['attemptedOn'] === '2026-06-10'
                && str_contains($aviso['message'], 'cerrado'));

        // Y nada se registró.
        $this->assertSame(0, Receipt::query()->where('receipt_type', ReceiptType::Expense)->count());
    }

    /** Sin referencia al registro manual, el pago sale sin respaldo. */
    public function test_la_referencia_al_expediente_anterior_es_obligatoria(): void
    {
        $this->abrirLibros('1000000.00');

        $this->actingAs($this->operador('contador'))
            ->post('/caja/pagos-anteriores', [
                ...$this->pago('100000.00'),
                'legacyReference' => '',
            ])
            ->assertSessionHasErrors('legacyReference');
    }

    /**
     * El administrativo no llega.
     *
     * En un egreso normal el sistema tiene el expediente, la cuota y el
     * recibo de ingreso contra los cuales verificar. Acá no hay nada de
     * eso, y por eso el pago exige el criterio del contador.
     */
    public function test_el_administrativo_no_paga_haberes_anteriores(): void
    {
        $this->abrirLibros('1000000.00');

        $this->actingAs($this->operador('administrativo'))
            ->get('/caja/pagos-anteriores')
            ->assertForbidden();

        $this->actingAs($this->operador('administrativo'))
            ->post('/caja/pagos-anteriores', $this->pago('100000.00'))
            ->assertForbidden();
    }

    public function test_la_pantalla_muestra_cuanto_queda_del_sistema_anterior(): void
    {
        $this->abrirLibros('1000000.00');

        $this->actingAs($this->operador('contador'))
            ->post('/caja/pagos-anteriores', $this->pago('300000.00'));

        $this->actingAs($this->operador('contador'))
            ->get('/caja/pagos-anteriores')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('caja/pagos-anteriores')
                ->where('balances.pending', '700000.00')
                ->has('payments', 1)
                ->where('payments.0.reference', '131010/2023')
                ->where('payments.0.amount', '300000.00')
            );
    }

    /** @return array<string, mixed> */
    private function pago(string $importe): array
    {
        return [
            'cashBoxId' => $this->caja(),
            'personId' => $this->beneficiario()->id,
            'amount' => $importe,
            'legacyReference' => '131010/2023',
            'paymentDate' => '2026-06-10',
            'medium' => 'cash',
            'currency' => 'ARS',
        ];
    }

    private function beneficiario(): Person
    {
        return Person::query()->firstOrCreate(
            ['document' => '18455233'],
            [
                'type' => 'individual',
                'first_name' => 'Héctor',
                'last_name' => 'Tintilay Tolaba',
                'is_active' => true,
            ],
        );
    }

    private function caja(): int
    {
        return (int) CashBox::query()->where('code', CashBox::HABERES)->value('id');
    }

    private function abrirLibros(
        ?string $efectivo,
        ?string $cheques = null,
        ?string $banco = null,
        ?int $cuentaBancaria = null,
    ): void {
        $saldos = [];

        if ($efectivo !== null) {
            $saldos[LedgerAccount::CashOnHand->value] = $efectivo;
        }

        if ($cheques !== null) {
            $saldos[LedgerAccount::ChequesInCustody->value] = $cheques;
        }

        if ($banco !== null) {
            $saldos[LedgerAccount::BankAccount->value] = $banco;
        }

        app(RegisterOpeningBalance::class)->handle(
            cashBoxId: $this->caja(),
            balances: $saldos,
            // Sin efectivo declarado no hay fajo que contar.
            denominations: $efectivo === null ? [] : $this->billetesPara($efectivo),
            date: CarbonImmutable::parse('2026-06-01'),
            bankAccountId: $cuentaBancaria,
        );
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
}
