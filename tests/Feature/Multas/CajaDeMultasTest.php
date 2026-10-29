<?php

declare(strict_types=1);

namespace Tests\Feature\Multas;

use App\Modules\Banking\Actions\RegisterBankFundReceipt;
use App\Modules\Banking\Actions\RollbackBankStatementImport;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Models\CashBookOpening;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineHistoricalMovement;
use App\Modules\Shared\Models\CashBox;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\Concerns\BuildsSharedBankAccount;
use Tests\TestCase;

/**
 * La caja de Multas: su apertura sin saldo bancario y los créditos viejos
 * de su cuenta que se identifican como pagos anteriores.
 */
class CajaDeMultasTest extends TestCase
{
    use BuildsSharedBankAccount;
    use RefreshDatabase;

    // ── Apertura ────────────────────────────────────────────────────────

    public function test_the_box_opens_with_cash_cheques_and_its_account_but_no_bank_balance(): void
    {
        $cuenta = $this->cuenta();
        $this->movimiento($cuenta, '2026-06-03', '80000.00', saldo: '1580000.00');

        $this->actingAs($this->operador('administrador'))
            ->post(route('multas.caja.apertura.store'), [
                'date' => '2026-06-01',
                'bankAccountId' => $cuenta->id,
                'balances' => ['CASH_ON_HAND' => '15000.00', 'CHEQUES_IN_CUSTODY' => '25.000,50'],
                'denominations' => $this->billetesPara('15000.00'),
                'cheques' => [[
                    'number' => '12345', 'bank' => 'Macro', 'issueDate' => '2026-05-20', 'amount' => '25000.50',
                    'expediente' => '169607/24', 'company' => 'CRISTOMAX S.A.S',
                ]],
                'notes' => 'Arqueo del cajón de Multas',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('multas.caja.dia', ['fecha' => '2026-06-01']));

        $apertura = CashBookOpening::for($this->cajaDe(CashBox::MULTAS), Currency::Ars);
        expect($apertura?->bank_account_id)->toBe($cuenta->id)
            ->and($apertura?->declared_total)->toBe('40000.50')
            ->and(FundReceipt::query()->where('cash_box_id', $this->cajaDe(CashBox::MULTAS))->sole()->cheque_number)->toBe('12345')
            ->and(DB::table('journal_lines')->where('cash_box_id', $this->cajaDe(CashBox::MULTAS))->where('account_code', 'BANK_ACCOUNT')->exists())->toBeFalse();

        // La apertura hecha, con la cuenta con la que opera.
        $this->actingAs($this->operador('consulta'))
            ->get(route('multas.caja.apertura.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('multas/apertura')
                ->where('opening.date', '2026-06-01')
                ->where('bankAccountLabel', $cuenta->label)
                ->where('canOpen', false));

        // Los dos números de la cuenta, separados: el del banco, en Banco ›
        // Movimientos; el del libro, en el calendario de la caja.
        $this->actingAs($this->operador('consulta'))
            ->get(route('banco.movimientos.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('balances.0.accountId', $cuenta->id)
                ->where('balances.0.balance', '1580000.00'));

        $this->actingAs($this->operador('consulta'))
            ->get(route('multas.caja.calendario', ['anio' => 2026]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('bankLedger.label', 'Reconocido en el libro de Multas')
                ->where('bankLedger.year', '0.00'));
    }

    public function test_the_box_does_not_bring_the_bank_balance_and_needs_its_account(): void
    {
        $cuenta = $this->cuenta();
        $admin = $this->operador('administrador');

        $this->actingAs($admin)
            ->post(route('multas.caja.apertura.store'), [
                'date' => '2026-06-01', 'bankAccountId' => $cuenta->id,
                'balances' => ['BANK_ACCOUNT' => '1000.00'], 'denominations' => [],
            ])
            ->assertSessionHasErrors('balances');

        $this->actingAs($admin)
            ->post(route('multas.caja.apertura.store'), [
                'date' => '2026-06-01', 'balances' => [], 'denominations' => [], 'declaredEmpty' => true,
            ])
            ->assertSessionHasErrors(['bankAccountId' => 'Elegí la cuenta bancaria con la que opera la caja de Multas.']);

        $this->actingAs($this->operador('contador'))
            ->post(route('multas.caja.apertura.store'), [
                'date' => '2026-06-01', 'bankAccountId' => $cuenta->id, 'balances' => [], 'denominations' => [], 'declaredEmpty' => true,
            ])
            ->assertForbidden();

        expect(CashBookOpening::for($this->cajaDe(CashBox::MULTAS), Currency::Ars))->toBeNull();
    }

    // ── Créditos viejos ─────────────────────────────────────────────────

    public function test_an_old_credit_becomes_a_previous_payment_for_its_whole_amount(): void
    {
        [$cuenta, $multa] = $this->escenario();
        $credito = $this->movimiento($cuenta, '2026-05-20', '280000.00');

        // Los créditos no viajan con la ficha: llegan al buscar.
        $version = $this->actingAs($this->operador())
            ->get(route('multas.show', [...$multa->showParameters(), 'credito' => '280.000,00']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.linkBankCredit', true)
                ->missing('creditosDelBanco'))
            ->viewData('page')['version'];

        $this->actingAs($this->operador())
            ->get(route('multas.show', [...$multa->showParameters(), 'credito' => '280.000,00']), [
                'X-Inertia' => 'true',
                'X-Inertia-Partial-Component' => 'multas/show',
                'X-Inertia-Partial-Data' => 'creditosDelBanco',
                'X-Inertia-Version' => $version,
            ])
            ->assertJsonPath('props.creditosDelBanco.0.id', $credito->id)
            ->assertJsonCount(1, 'props.creditosDelBanco');

        $this->actingAs($this->operador())
            ->post(route('multas.antecedentes.credito.store', $multa), [
                'bankTransactionId' => $credito->id,
                'evidence' => 'Nota de la empresa con el comprobante',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('multas.show', $multa->showParameters()));

        $antecedente = FineHistoricalMovement::query()->sole();
        expect($antecedente->amount)->toBe('280000.00')
            ->and($antecedente->occurred_on?->toDateString())->toBe('2026-05-20')
            ->and($antecedente->bank_transaction_id)->toBe($credito->id)
            ->and(DB::table('financial_events')->where('cash_box_id', $this->cajaDe(CashBox::MULTAS))->count())->toBe(0);

        $this->actingAs($this->operador('contador'))
            ->post(route('multas.antecedentes.review', $antecedente), ['status' => 'validated'])
            ->assertSessionHasNoErrors();

        expect((string) DB::table('fine_balances')->where('fine_id', $multa->id)->value('debt'))->toBe('1120000.00');

        // Un crédito, un pago: ya no se ofrece ni se acepta otra vez.
        $this->actingAs($this->operador())
            ->post(route('multas.antecedentes.credito.store', $multa), ['bankTransactionId' => $credito->id, 'evidence' => 'Otra vez'])
            ->assertSessionHasErrors('bankTransactionId');
    }

    public function test_only_an_old_free_credit_of_the_multas_account_qualifies(): void
    {
        [$cuenta, $multa] = $this->escenario();
        $operador = $this->operador();
        $otraCuenta = $this->cuenta('310000111222333');

        $casos = [
            'posterior a la apertura' => $this->movimiento($cuenta, '2026-06-05', '1000.00'),
            'débito' => $this->movimiento($cuenta, '2026-05-20', '1000.00', TransactionDirection::Debit),
            'de otra cuenta' => $this->movimiento($otraCuenta, '2026-05-20', '1000.00'),
        ];

        foreach ($casos as $caso => $movimiento) {
            $this->actingAs($operador)
                ->post(route('multas.antecedentes.credito.store', $multa), ['bankTransactionId' => $movimiento->id, 'evidence' => $caso])
                ->assertSessionHasErrors('bankTransactionId');
        }

        expect(FineHistoricalMovement::query()->count())->toBe(0);

        // La base, con lo que el Action no deja pasar.
        $credito = $this->movimiento($cuenta, '2026-05-20', '500.00');
        $this->rechaza('credito entero', fn () => $this->antecedenteDirecto($multa, $credito, '400.00'));
        $this->rechaza('la fecha del pago es la del credito', fn () => $this->antecedenteDirecto($multa, $credito, '500.00', '2026-05-21'));
        $this->rechaza('posterior a la apertura', fn () => $this->antecedenteDirecto($multa, $casos['posterior a la apertura'], '1000.00', '2026-06-05'));
        $this->rechaza('no es de la cuenta', fn () => $this->antecedenteDirecto($multa, $casos['de otra cuenta'], '1000.00'));
    }

    public function test_a_linked_credit_is_not_booked_and_its_date_is_the_banks(): void
    {
        /*
         * Un crédito anterior a la apertura de la caja (10/06), imputado en
         * otro libro —Haberes, que abrió mucho antes—: la regla de la
         * apertura no lo frena; lo frena el pago anterior que lo usa.
         */
        [$cuenta, $multa] = $this->escenario(apertura: '2026-06-10');
        $credito = $this->movimiento($cuenta, '2026-06-05', '500.00');
        $this->antecedenteDirecto($multa, $credito, '500.00');
        $antecedente = FineHistoricalMovement::query()->sole();

        // Imputarlo en un libro: lo frena el pago anterior que lo usa.
        $this->abrirLibrosSinSaldo();
        $otro = $this->movimiento($cuenta, '2026-06-10', '100.00');
        $recepcion = app(RegisterBankFundReceipt::class)->handle($otro, '100.00', 'test-otro', $this->cajaDe(CashBox::HABERES), actorId: $this->quienAbre());
        $this->rechaza('respalda un pago anterior', fn () => DB::table('bank_transaction_allocations')->insert([
            'bank_transaction_id' => $credito->id, 'financial_event_id' => $recepcion->financial_event_id,
            'allocation_role' => 'funds_received', 'amount' => '500.00', 'allocated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]));

        // La fecha no se revisa, ni por pantalla ni en la base.
        $this->actingAs($this->operador('contador'))
            ->post(route('multas.antecedentes.revisiones.store', $antecedente), [
                'date' => '2026-06-06', 'reasonCode' => 'misread_paper', 'reason' => 'Fecha mal leída', 'expected' => ['date' => '2026-06-05'],
            ])
            ->assertSessionHasErrors('date');
        $this->rechaza('es la del banco', fn () => DB::table('fine_identifier_revisions')->insert([
            'fine_historical_movement_id' => $antecedente->id, 'kind' => 'correction', 'reason_code' => 'other', 'reason' => 'x',
            'old_values' => json_encode(['occurred_on' => '2026-06-05', 'occurred_precision' => 'day']),
            'new_values' => json_encode(['occurred_on' => '2026-06-06', 'occurred_precision' => 'day']),
        ]));

        // Descartado, el crédito queda libre para el pago correcto.
        $this->actingAs($this->operador('contador'))
            ->post(route('multas.antecedentes.review', $antecedente), ['status' => 'rejected', 'reasonCode' => 'wrong_fine', 'note' => 'Era de otra multa'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->operador())
            ->post(route('multas.antecedentes.credito.store', $multa), ['bankTransactionId' => $credito->id, 'evidence' => 'Ahora sí'])
            ->assertSessionHasNoErrors();
    }

    /**
     * La cola de créditos sin reconocer arranca en la apertura de la caja: lo
     * anterior ya estaba en el saldo que el área traía, y solo puede
     * respaldar un pago anterior de una multa.
     */
    public function test_the_unclassified_queue_starts_when_the_box_opened(): void
    {
        [$cuenta, $multa] = $this->escenario(apertura: '2026-06-10');
        $anterior = $this->movimiento($cuenta, '2026-06-05', '500.00');
        $nuevo = $this->movimiento($cuenta, '2026-06-12', '300.00');

        // En Banco › Movimientos solo el posterior a la apertura se identifica.
        $this->actingAs($this->operador('contador'))
            ->get(route('banco.movimientos.index', ['cuenta' => $cuenta->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where("creditActions.{$nuevo->id}.label", 'Identificar')
                ->where("creditActions.{$nuevo->id}.href", route('multas.creditos.identificar', $nuevo, absolute: false))
                ->where("creditActions.{$anterior->id}.label", 'Anterior a la apertura de Multas')
                ->where("creditActions.{$anterior->id}.href", null));

        // Quien no concilia no identifica: el posterior queda sin acción.
        $this->actingAs($this->operador('consulta'))
            ->get(route('banco.movimientos.index', ['cuenta' => $cuenta->id]))
            ->assertInertia(fn (Assert $page) => $page->missing("creditActions.{$nuevo->id}"));

        // El anterior sigue a mano para respaldar un pago anterior.
        $this->actingAs($this->operador())
            ->post(route('multas.antecedentes.credito.store', $multa), ['bankTransactionId' => $anterior->id, 'evidence' => 'Nota de la empresa'])
            ->assertSessionHasNoErrors();
    }

    public function test_without_the_box_opened_no_credit_is_old_yet(): void
    {
        $cuenta = $this->cuenta();
        $multa = $this->multa();
        $credito = $this->movimiento($cuenta, '2026-05-20', '500.00');

        $this->actingAs($this->operador())
            ->get(route('multas.show', $multa->showParameters()))
            ->assertInertia(fn (Assert $page) => $page->where('can.linkBankCredit', false));

        $this->actingAs($this->operador())
            ->post(route('multas.antecedentes.credito.store', $multa), ['bankTransactionId' => $credito->id, 'evidence' => 'Nota'])
            ->assertSessionHasErrors(['bankTransactionId' => 'La caja de Multas todavía no se abrió con su cuenta bancaria: sin eso no se sabe qué créditos son anteriores.']);
    }

    public function test_rolling_back_the_statement_of_a_linked_credit_says_why(): void
    {
        [$cuenta, $multa] = $this->escenario();
        $importacion = $this->importacion($cuenta);
        $credito = $this->movimiento($cuenta, '2026-05-20', '500.00', importacion: $importacion);
        $this->antecedenteDirecto($multa, $credito, '500.00');

        try {
            app(RollbackBankStatementImport::class)->handle($importacion, $this->quienAbre());
            $this->fail('Revirtió un extracto con un crédito vinculado a un pago anterior.');
        } catch (RuntimeException $e) {
            expect($e->getMessage())->toContain('pago anterior de una multa');
        }

        expect(BankTransaction::query()->whereKey($credito->id)->exists())->toBeTrue();
    }

    // ── Andamiaje ───────────────────────────────────────────────────────

    /**
     * La cuenta de Multas, la caja abierta con ella —el 01/06 si no se dice
     * otra cosa— y una multa de 1.400.000.
     *
     * @return array{0: BankAccount, 1: Fine}
     */
    private function escenario(string $apertura = '2026-06-01'): array
    {
        $cuenta = $this->cuenta();
        $this->abrirMultas($cuenta, $apertura);

        return [$cuenta, $this->multa()];
    }

    private function multa(): Fine
    {
        $this->actingAs($this->operador())->post(route('multas.store'), [
            'caseNumber' => '169607/24-0',
            'resolution' => ['text' => 'N°0649/26', 'issuedOn' => '2026-05-07'],
            'acts' => [['actType' => 'HYS', 'text' => 'N°1089657', 'issuedOn' => null]],
            'caption' => 'CRISTOMAX S.A.S',
            'registeredOn' => '2026-05-07',
            'amount' => '1400000.00',
        ])->assertSessionHasNoErrors();

        return Fine::query()->latest('id')->firstOrFail();
    }

    private function antecedenteDirecto(Fine $multa, BankTransaction $credito, string $importe, ?string $fecha = null): void
    {
        DB::table('fine_historical_movements')->insert([
            'fine_id' => $multa->id, 'kind' => 'payment', 'amount' => $importe,
            'occurred_on' => $fecha ?? $credito->transaction_date?->toDateString(), 'occurred_precision' => 'day',
            'evidence_text' => 'Crédito del banco', 'status' => 'pending_review', 'provenance' => 'manual',
            'bank_transaction_id' => $credito->id,
        ]);
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
        } catch (ValidationException $e) {
            $this->fail("Rechazó el Action y no la base: {$e->getMessage()}");
        }

        $this->fail("La base aceptó lo que tenía que rechazar ({$fragmento}).");
    }
}
