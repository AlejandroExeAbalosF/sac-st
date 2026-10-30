<?php

declare(strict_types=1);

namespace Tests\Feature\Multas;

use App\Modules\Banking\Actions\RegisterBankFundReceipt;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\CashBoxBankAccount;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Multas\Models\FinePaymentReceipt;
use App\Modules\Shared\Models\AuditEvent;
use App\Modules\Shared\Models\CashBox;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsSharedBankAccount;
use Tests\TestCase;

/**
 * Las cuentas habilitadas de la caja de Multas: dos a la vez, el reemplazo
 * de una por otra y la vigencia de cada una, en el Action y en la base.
 */
class CuentasDeLaCajaDeMultasTest extends TestCase
{
    use BuildsSharedBankAccount;
    use RefreshDatabase;

    private BankAccount $a;

    private BankAccount $b;

    private Fine $multa;

    public function test_the_opening_account_is_the_first_one_with_unknown_start(): void
    {
        $this->escenario();

        $vinculo = CashBoxBankAccount::query()->sole();
        expect($vinculo->bank_account_id)->toBe($this->a->id)
            ->and($vinculo->valid_from)->toBeNull()
            ->and($vinculo->valid_until)->toBeNull()
            ->and($vinculo->is_default)->toBeTrue();
    }

    /** Dos cuentas: se deposita en la elegida y se identifican créditos de las dos. */
    public function test_two_accounts_receive_deposits_and_offer_their_credits(): void
    {
        $this->escenario();
        $this->habilitarB('2026-06-01');

        $pago = $this->cobrar('5000.00');
        $this->actingAs($this->operador())
            ->get(route('multas.pagos.deposito.create', $pago))
            ->assertInertia(fn (Assert $page) => $page->has('cuentas', 2)->where('cuentas.0.isDefault', true));
        $this->depositar($pago, $this->b->id)->assertSessionHasNoErrors();
        expect(CashToBankTransfer::query()->sole()->bank_account_id)->toBe($this->b->id);

        $deA = $this->movimiento($this->a, BusinessDate::today()->toDateString(), '700.00');
        $deB = $this->movimiento($this->b, BusinessDate::today()->toDateString(), '800.00');
        $this->actingAs($this->operador('contador'))
            ->get(route('banco.movimientos.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where("creditActions.{$deA->id}.label", 'Identificar')
                ->where("creditActions.{$deB->id}.label", 'Identificar'));

        $this->identificar($deB)->assertSessionHasNoErrors();
        // El recibo cita la cuenta del crédito.
        $recibo = FinePaymentReceipt::query()->latest('id')->firstOrFail();
        expect($recibo->snapshot['bankAccountNumber'])->toBe($this->b->account_number);
    }

    /**
     * Reemplazo: la cuenta dada de baja no recibe depósitos nuevos, pero lo
     * que estaba en tránsito se acredita después; un crédito llegado antes de
     * la baja se identifica, uno posterior no.
     */
    public function test_a_retired_account_keeps_what_was_done_with_it(): void
    {
        $this->escenario();
        $this->travelTo(CarbonImmutable::parse('2026-09-10 10:00'));
        $this->habilitarB('2026-09-10');

        $enTransito = $this->cobrar('5000.00');
        $this->depositar($enTransito, $this->a->id)->assertSessionHasNoErrors();
        $antes = $this->movimiento($this->a, '2026-09-10', '900.00');

        $this->travelTo(CarbonImmutable::parse('2026-09-12 10:00'));
        $vinculoA = CashBoxBankAccount::query()->where('bank_account_id', $this->a->id)->sole();
        $this->actingAs($this->operador('administrador'))
            ->post(route('multas.caja.cuentas.retire', $vinculoA), ['until' => '2026-09-11'])
            ->assertSessionHasErrors(['newDefaultBankAccountId' => 'Es la cuenta predeterminada: elegí cuál de las otras habilitadas la reemplaza.']);
        $this->actingAs($this->operador('administrador'))
            ->post(route('multas.caja.cuentas.retire', $vinculoA), ['until' => '2026-09-11', 'newDefaultBankAccountId' => $this->b->id])
            ->assertSessionHasNoErrors();

        expect(CashBoxBankAccount::query()->where('bank_account_id', $this->b->id)->sole()->is_default)->toBeTrue();

        // Depositar en A ya no: la elegida no vale, y sin elegir va a B.
        $nuevo = $this->cobrar('3000.00');
        $this->depositar($nuevo, $this->a->id)->assertSessionHasErrors(['bankAccountId' => 'Esa cuenta no está habilitada para la caja en la fecha del depósito.']);
        $this->depositar($nuevo, null)->assertSessionHasNoErrors();
        expect(CashToBankTransfer::query()->latest('id')->firstOrFail()->bank_account_id)->toBe($this->b->id);

        // El depósito en A, hecho mientras valía, se acredita después de la baja.
        $acreditacion = $this->movimiento($this->a, '2026-09-12', '5000.00');
        $this->actingAs($this->operador('contador'))
            ->post(route('multas.traslados.confirm', CashToBankTransfer::query()->oldest('id')->firstOrFail()), [
                'bankTransactionId' => $acreditacion->id, 'idempotencyKey' => (string) Str::uuid(),
            ])->assertSessionHasNoErrors();

        // El crédito de A anterior a la baja se identifica; uno posterior, no.
        $despues = $this->movimiento($this->a, '2026-09-12', '1100.00');
        $this->identificar($antes)->assertSessionHasNoErrors();
        $this->identificar($despues)->assertSessionHasErrors('bankTransactionId');
        $this->actingAs($this->operador('contador'))
            ->get(route('banco.movimientos.index'))
            ->assertInertia(fn (Assert $page) => $page->where("creditActions.{$despues->id}.href", null));
    }

    /**
     * Una cuenta que empieza después de la apertura no ofrece lo anterior a
     * su inicio ni respalda pagos anteriores; la de la apertura, sin inicio,
     * sí respalda lo viejo.
     */
    public function test_a_new_account_does_not_reach_back_before_its_start(): void
    {
        $this->escenario();
        $this->habilitarB('2026-09-01');

        $antesDelInicio = $this->movimiento($this->b, '2026-08-20', '400.00');
        $this->identificar($antesDelInicio)->assertSessionHasErrors('bankTransactionId');

        $viejoDeB = $this->movimiento($this->b, '2026-05-20', '600.00');
        $viejoDeA = $this->movimiento($this->a, '2026-05-21', '700.00');
        $this->actingAs($this->operador())
            ->post(route('multas.antecedentes.credito.store', $this->multa), ['bankTransactionId' => $viejoDeB->id, 'evidence' => 'Nota'])
            ->assertSessionHasErrors('bankTransactionId');
        $this->actingAs($this->operador())
            ->post(route('multas.antecedentes.credito.store', $this->multa), ['bankTransactionId' => $viejoDeA->id, 'evidence' => 'Nota'])
            ->assertSessionHasNoErrors();
    }

    /**
     * Una cuenta dada de baja que vuelve abre un período nuevo: el tiempo en
     * que no correspondía queda afuera, y los períodos no se superponen.
     */
    public function test_a_returning_account_opens_a_new_period_and_keeps_the_gap_out(): void
    {
        $this->escenario();
        $this->travelTo(CarbonImmutable::parse('2026-09-10 10:00'));
        $this->habilitarB('2026-09-10');
        $delPrimero = $this->movimiento($this->a, '2026-09-05', '500.00');
        $this->identificar($delPrimero)->assertSessionHasNoErrors();

        $this->travelTo(CarbonImmutable::parse('2026-09-12 10:00'));
        $admin = $this->operador('administrador');
        $this->actingAs($admin)
            ->post(route('multas.caja.cuentas.retire', CashBoxBankAccount::query()->where('bank_account_id', $this->a->id)->sole()), ['until' => '2026-09-11', 'newDefaultBankAccountId' => $this->b->id])
            ->assertSessionHasNoErrors();

        $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00'));
        $mensaje = 'La cuenta operó en la caja hasta el 11/09/2026: el período nuevo empieza después.';
        $this->actingAs($admin)->post(route('multas.caja.cuentas.store'), ['bankAccountId' => $this->a->id])
            ->assertSessionHasErrors(['validFrom' => $mensaje]);
        $this->actingAs($admin)->post(route('multas.caja.cuentas.store'), ['bankAccountId' => $this->a->id, 'validFrom' => '2026-09-11'])
            ->assertSessionHasErrors(['validFrom' => $mensaje]);
        $this->actingAs($admin)->post(route('multas.caja.cuentas.store'), ['bankAccountId' => $this->a->id, 'validFrom' => '2026-09-20'])
            ->assertSessionHasNoErrors();

        $periodos = CashBoxBankAccount::query()->where('bank_account_id', $this->a->id)->orderBy('id')->get();
        expect($periodos)->toHaveCount(2)
            ->and($periodos[0]->valid_until?->toDateString())->toBe('2026-09-11')
            ->and($periodos[1]->valid_from?->toDateString())->toBe('2026-09-20')
            ->and($periodos[1]->valid_until)->toBeNull();

        // El hueco no vuelve: lo del 15/09 no se identifica; lo del 22/09, sí.
        $this->identificar($this->movimiento($this->a, '2026-09-15', '1500.00'))->assertSessionHasErrors('bankTransactionId');
        $this->identificar($this->movimiento($this->a, '2026-09-22', '2200.00'))->assertSessionHasNoErrors();
        $this->rechaza('dejaría afuera', fn () => DB::table('cash_box_bank_accounts')->where('id', $periodos[1]->id)->update(['valid_until' => '2026-09-21']));

        // El segundo período no alcanza al primero, ni en el Action ni en la base.
        $this->actingAs($admin)
            ->post(route('multas.caja.cuentas.start', $periodos[1]), ['validFrom' => '2026-09-10', 'reason' => 'Se cargó mal la fecha'])
            ->assertSessionHasErrors(['validFrom' => 'La cuenta tuvo otro período en la caja hasta el 11/09/2026: este empieza después.']);
        $this->rechaza('no se superponen', fn () => DB::table('cash_box_bank_accounts')->where('id', $periodos[1]->id)->update(['valid_from' => '2026-09-05']));
        $this->rechaza('no se superponen', fn () => DB::table('cash_box_bank_accounts')->insert([
            'cash_box_id' => $periodos[0]->cash_box_id, 'bank_account_id' => $this->a->id, 'currency' => 'ARS',
            'valid_from' => '2026-09-08', 'valid_until' => '2026-09-09', 'is_default' => false,
        ]));

        // Lo del primer período no frena mover el inicio del segundo: lo sigue cubriendo el primero.
        $this->actingAs($admin)
            ->post(route('multas.caja.cuentas.start', $periodos[1]), ['validFrom' => '2026-09-21', 'reason' => 'Volvió un día después'])
            ->assertSessionHasNoErrors();
    }

    /**
     * Un depósito hecho antes de la baja se carga después contra la cuenta
     * dada de baja, aunque la caja ya no tenga ninguna vigente hoy.
     */
    public function test_a_deposit_made_before_the_retirement_can_still_go_to_that_account(): void
    {
        $this->escenario();
        $this->travelTo(CarbonImmutable::parse('2026-09-10 10:00'));
        $pago = $this->cobrar('5000.00');

        $this->travelTo(CarbonImmutable::parse('2026-09-14 10:00'));
        $this->actingAs($this->operador('administrador'))
            ->post(route('multas.caja.cuentas.retire', CashBoxBankAccount::query()->where('bank_account_id', $this->a->id)->sole()), ['until' => '2026-09-11'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->operador())
            ->get(route('multas.pagos.deposito.create', $pago))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('cuentas', 1)
                ->where('cuentas.0.id', $this->a->id)
                ->where('cuentas.0.validUntil', '2026-09-11'));

        $this->depositar($pago, $this->a->id, '2026-09-13')
            ->assertSessionHasErrors(['bankAccountId' => 'Esa cuenta no está habilitada para la caja en la fecha del depósito.']);
        $this->depositar($pago, $this->a->id, '2026-09-11')->assertSessionHasNoErrors();
        expect(CashToBankTransfer::query()->sole()->bank_account_id)->toBe($this->a->id);
    }

    /** Adelantar el inicio pide motivo y queda auditado; atrasarlo no deja afuera lo registrado. */
    public function test_moving_the_start_needs_a_reason_and_cannot_drop_what_was_registered(): void
    {
        $this->escenario();
        $this->travelTo(CarbonImmutable::parse('2026-09-10 10:00'));
        $this->habilitarB('2026-09-10');
        $vinculoB = CashBoxBankAccount::query()->where('bank_account_id', $this->b->id)->sole();
        $admin = $this->operador('administrador');

        $this->actingAs($admin)
            ->post(route('multas.caja.cuentas.start', $vinculoB), ['validFrom' => '2026-08-01', 'reason' => ''])
            ->assertSessionHasErrors('reason');
        $this->actingAs($admin)
            ->post(route('multas.caja.cuentas.start', $vinculoB), ['validFrom' => '2026-08-01', 'reason' => 'La empresa ya depositaba ahí'])
            ->assertSessionHasNoErrors();
        expect(AuditEvent::query()->where('action', 'caja.cuenta.vigencia-cambiada')->exists())->toBeTrue();

        $pago = $this->cobrar('5000.00');
        $this->depositar($pago, $this->b->id)->assertSessionHasNoErrors();
        $this->travelTo(CarbonImmutable::parse('2026-09-20 10:00'));

        $this->actingAs($admin)
            ->post(route('multas.caja.cuentas.start', $vinculoB), ['validFrom' => '2026-09-15', 'reason' => 'Se cargó mal'])
            ->assertSessionHasErrors(['validFrom' => 'Con esta cuenta se registró algo el 10/09/2026: el inicio no puede ser posterior.']);

        // La base lo repite.
        $this->rechaza('dejaría afuera', fn () => DB::table('cash_box_bank_accounts')->where('id', $vinculoB->id)->update(['valid_from' => '2026-09-15']));
    }

    /** La base: una predeterminada por moneda, sin fechas futuras y sin borrar. */
    public function test_the_database_guards_the_links(): void
    {
        $this->escenario();
        $this->habilitarB('2026-06-01');
        $vinculoB = CashBoxBankAccount::query()->where('bank_account_id', $this->b->id)->sole();

        $this->rechaza('cash_box_bank_accounts_one_default', fn () => DB::table('cash_box_bank_accounts')->where('id', $vinculoB->id)->update(['is_default' => true]));
        $this->rechaza('fechas futuras', fn () => DB::table('cash_box_bank_accounts')->where('id', $vinculoB->id)->update(['valid_until' => BusinessDate::today()->addDays(3)->toDateString()]));
        $this->rechaza('no se borra', fn () => DB::table('cash_box_bank_accounts')->where('id', $vinculoB->id)->delete());
        $this->rechaza('exactamente una cuenta predeterminada', fn () => DB::table('cash_box_bank_accounts')->update(['is_default' => false]));
    }

    /** La base: sin pasar por los Actions, un depósito o una identificación fuera de las cuentas de la caja se rechazan. */
    public function test_the_database_rejects_deposits_and_identifications_outside_the_accounts(): void
    {
        $this->escenario();
        $caja = $this->cajaDe(CashBox::MULTAS);

        $this->rechaza('no está habilitada para esta caja', function () use ($caja): void {
            $evento = app(PostJournalEntry::class)->handle(
                type: FinancialEventType::CashDepositedToBank,
                idempotencyKey: (string) Str::uuid(),
                lines: [
                    EntryLine::debit(LedgerAccount::CashInTransit, '100.00')->in(Currency::Ars)->onBankAccount($this->b->id)->onCashBox($caja),
                    EntryLine::credit(LedgerAccount::CashOnHand, '100.00')->in(Currency::Ars)->onCashBox($caja),
                ],
                date: BusinessDate::today(),
                cashBoxId: $caja,
                actorId: $this->quienAbre(),
            );
            DB::table('cash_to_bank_transfers')->insert([
                'deposit_event_id' => $evento->id, 'cash_box_id' => $caja, 'bank_account_id' => $this->b->id,
                'amount' => '100.00', 'deposit_date' => BusinessDate::today()->toDateString(), 'status' => 'deposited',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $ajeno = $this->movimiento($this->b, BusinessDate::today()->toDateString(), '300.00');
        $this->rechaza('no es de una cuenta habilitada', fn () => app(RegisterBankFundReceipt::class)->handle(
            $ajeno, '300.00', (string) Str::uuid(), $caja, actorId: $this->quienAbre(),
        ));
    }

    /** El recibo de efectivo cita la cuenta solo con una vigente, y no cambia después. */
    public function test_a_cash_receipt_names_the_account_only_when_there_is_one(): void
    {
        $this->escenario();
        $conUna = $this->cobrar('1000.00');
        expect($this->numeroDeCuentaDelRecibo($conUna))->toBe($this->a->account_number);

        $this->habilitarB('2026-06-01');
        $conDos = $this->cobrar('2000.00');
        expect($this->numeroDeCuentaDelRecibo($conDos))->toBeNull();

        // Reimprimir después de cambiar la predeterminada da el mismo papel.
        $this->actingAs($this->operador('administrador'))
            ->post(route('multas.caja.cuentas.default', CashBoxBankAccount::query()->where('bank_account_id', $this->b->id)->sole()))
            ->assertSessionHasNoErrors();
        expect($this->numeroDeCuentaDelRecibo($conUna))->toBe($this->a->account_number);
    }

    /** Mirar es de quien ve la caja; administrar, del administrador. */
    public function test_who_sees_and_manages_the_accounts(): void
    {
        $this->escenario();

        $this->actingAs($this->operador('consulta'))
            ->get(route('multas.caja.cuentas.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('caja/cuentas')
                ->has('accounts', 1)
                ->where('can.manage', false));

        $this->actingAs($this->operador('contador'))
            ->post(route('multas.caja.cuentas.store'), ['bankAccountId' => $this->b->id])
            ->assertForbidden();

        // Una cuenta de Multas no se toca desde las rutas de Haberes.
        $this->actingAs($this->operador('administrador'))
            ->post(route('caja.cuentas.default', CashBoxBankAccount::query()->sole()))
            ->assertNotFound();
    }

    private function escenario(): void
    {
        $this->a = $this->cuenta();
        $this->b = $this->cuenta('310000111222333');
        $this->abrirMultas($this->a, '2026-06-01');

        $this->actingAs($this->operador())->post(route('multas.store'), [
            'caseNumber' => '169607/24-0',
            'resolution' => ['text' => 'N°0649/26', 'issuedOn' => '2026-05-07'],
            'acts' => [['actType' => 'HYS', 'text' => 'N°1089657', 'issuedOn' => null]],
            'caption' => 'CRISTOMAX S.A.S',
            'registeredOn' => '2026-05-07',
            'amount' => '1400000.00',
        ])->assertSessionHasNoErrors();

        $this->multa = Fine::query()->latest('id')->firstOrFail();
    }

    private function habilitarB(string $desde): void
    {
        $this->actingAs($this->operador('administrador'))
            ->post(route('multas.caja.cuentas.store'), ['bankAccountId' => $this->b->id, 'validFrom' => $desde])
            ->assertSessionHasNoErrors();
    }

    private function cobrar(string $importe): FinePayment
    {
        $this->actingAs($this->operador())->post(route('multas.cobros.store', $this->multa), [
            'medium' => 'cash', 'amount' => $importe, 'idempotencyKey' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();

        return FinePayment::query()->latest('id')->firstOrFail();
    }

    private function depositar(FinePayment $pago, ?int $cuenta, ?string $fecha = null): TestResponse
    {
        return $this->actingAs($this->operador())->post(route('multas.pagos.deposito.store', $pago), array_filter([
            'depositDate' => $fecha ?? BusinessDate::today()->toDateString(),
            'ticket' => UploadedFile::fake()->image('ticket.jpg'),
            'idempotencyKey' => (string) Str::uuid(),
            'bankAccountId' => $cuenta,
        ], fn (mixed $v): bool => $v !== null));
    }

    private function identificar(BankTransaction $credito): TestResponse
    {
        return $this->actingAs($this->operador('contador'))->post(route('multas.pagos-bancarios.verificados.store', $this->multa), [
            'medium' => 'bank_transfer', 'bankTransactionId' => $credito->id, 'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    private function numeroDeCuentaDelRecibo(FinePayment $pago): ?string
    {
        $snapshot = FinePaymentReceipt::query()->where('fine_payment_id', $pago->id)->sole()->snapshot;

        return is_string($snapshot['bankAccountNumber'] ?? null) ? $snapshot['bankAccountNumber'] : null;
    }

    private function rechaza(string $fragmento, callable $accion): void
    {
        try {
            DB::transaction(function () use ($accion): void {
                $accion();
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
        } catch (QueryException $e) {
            expect(mb_strtolower($e->getMessage()))->toContain(mb_strtolower($fragmento));

            return;
        }

        $this->fail("La base aceptó lo que tenía que rechazar ({$fragmento}).");
    }
}
