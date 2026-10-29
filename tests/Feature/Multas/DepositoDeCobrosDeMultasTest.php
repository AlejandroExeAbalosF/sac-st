<?php

declare(strict_types=1);

namespace Tests\Feature\Multas;

use App\Modules\Banking\Actions\RegisterBankFundReceipt;
use App\Modules\Banking\Enums\CashTransferStatus;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Enums\ChequeStatus;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Shared\Models\AuditEvent;
use App\Modules\Shared\Models\CashBox;
use App\Support\BusinessDate;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsSharedBankAccount;
use Tests\TestCase;

/**
 * Lo cobrado en la caja de Multas, camino al banco: cada cobro entero, en
 * su propio depósito, a la cuenta del libro, y acreditado con un crédito
 * entero del extracto (M06).
 */
class DepositoDeCobrosDeMultasTest extends TestCase
{
    use BuildsSharedBankAccount;
    use RefreshDatabase;

    private BankAccount $laCuenta;

    // ── Depositar y acreditar ───────────────────────────────────────────

    public function test_a_cash_collection_goes_to_the_bank_whole_and_is_credited(): void
    {
        $multa = $this->escenario();
        $pago = $this->cobrar($multa, '300000.00');

        $this->actingAs($this->operador())
            ->get(route('multas.pagos.deposito.create', [$pago, 'desde' => 'pendientes']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('multas/depositos/create')
                ->where('cobro.amount', '300000.00')
                ->where('cuenta', $this->laCuenta->label)
                ->where('desde', 'pendientes'));

        $this->post(route('multas.pagos.deposito.store', $pago), [...$this->ticket(), 'desde' => 'pendientes'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('multas.pendientes.index', ['pestana' => 'transito']));

        // Depositado, ya no hay nada que depositar.
        $this->get(route('multas.pagos.deposito.create', $pago))->assertNotFound();

        $traslado = CashToBankTransfer::query()->sole();

        expect($traslado->status)->toBe(CashTransferStatus::Deposited)
            ->and($traslado->amount)->toBe('300000.00')
            ->and($traslado->bank_account_id)->toBe($this->laCuenta->id)
            ->and($this->libro())->toMatchArray(['CASH_ON_HAND' => '0.00', 'CASH_IN_TRANSIT' => '300000.00'])
            // Depositar no es recaudar otra vez: la deuda no se mueve.
            ->and($this->saldo($multa, 'debt'))->toBe('1100000.00');

        $this->get(route('multas.pendientes.index', ['pestana' => 'transito']))->assertInertia(fn (Assert $page) => $page
            ->where('tab', 'transito')
            ->has('paraDepositar', 0)
            ->has('enTransito', 1)
            ->where('enTransito.0.payment.deposit.place', 'in_transit'));

        $credito = $this->movimiento($this->laCuenta, BusinessDate::today()->toDateString(), '300000.00');
        $quienConcilia = $this->operador('contador');

        $this->actingAs($quienConcilia)
            ->getJson(route('multas.traslados.candidates', $traslado))
            ->assertOk()
            ->assertJsonPath('candidates.0.id', $credito->id);

        $this->post(route('multas.traslados.confirm', $traslado), [
            'bankTransactionId' => $credito->id, 'idempotencyKey' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();

        expect($traslado->refresh()->status)->toBe(CashTransferStatus::BankConfirmed)
            ->and($this->libro())->toMatchArray(['CASH_IN_TRANSIT' => '0.00', 'BANK_ACCOUNT' => '300000.00'])
            ->and(AuditEvent::query()->where('action', 'multa.cobro-depositado')->count())->toBe(1)
            ->and(AuditEvent::query()->where('action', 'multa.deposito-acreditado')->count())->toBe(1);

        $this->get(route('multas.show', $multa->showParameters()))->assertInertia(fn (Assert $page) => $page
            ->where('multa.payments.0.deposit.place', 'in_bank')
            ->where('multa.payments.0.deposit.creditedOn', BusinessDate::today()->toDateString())
            ->where('multa.payments.0.deposit.ticket.title', 'Ticket del depósito'));
    }

    /** El cheque viaja entero y sale de custodia; cancelado, vuelve y se deposita de nuevo. */
    public function test_a_cheque_leaves_custody_and_a_cancelled_deposit_stays_in_the_history(): void
    {
        $multa = $this->escenario();
        $pago = $this->cobrar($multa, '20000.00', cheque: true);
        $recepcion = $pago->application()->firstOrFail()->fundReceipt;

        $this->actingAs($this->operador())
            ->post(route('multas.pagos.deposito.store', $pago), $this->ticket())
            ->assertSessionHasNoErrors();
        $traslado = CashToBankTransfer::query()->sole();

        expect($recepcion->refresh()->cheque_status)->toBe(ChequeStatus::Deposited)
            ->and($this->libro())->toMatchArray(['CHEQUES_IN_CUSTODY' => '0.00', 'CASH_IN_TRANSIT' => '20000.00']);

        $this->actingAs($this->operador('contador'))
            ->post(route('multas.traslados.cancel', $traslado), [
                'reason' => 'Se cargó el depósito de otro cheque', 'idempotencyKey' => (string) Str::uuid(),
            ])->assertSessionHasNoErrors();

        expect($recepcion->refresh()->cheque_status)->toBe(ChequeStatus::InCustody)
            ->and($this->libro())->toMatchArray(['CHEQUES_IN_CUSTODY' => '20000.00', 'CASH_IN_TRANSIT' => '0.00']);

        $this->actingAs($this->operador())
            ->post(route('multas.pagos.deposito.store', $pago), $this->ticket())
            ->assertSessionHasNoErrors();

        $this->get(route('multas.show', $multa->showParameters()))->assertInertia(fn (Assert $page) => $page
            ->where('multa.payments.0.deposit.place', 'in_transit')
            ->where('multa.payments.0.deposit.cancelled.0.transferId', $traslado->id)
            ->where('multa.payments.0.deposit.cancelled.0.reason', 'Se cargó el depósito de otro cheque'));
    }

    public function test_a_collection_is_deposited_once_and_not_before_it_was_collected(): void
    {
        $multa = $this->escenario();
        $pago = $this->cobrar($multa, '300000.00');

        $this->actingAs($this->operador())
            ->post(route('multas.pagos.deposito.store', $pago), [
                ...$this->ticket(), 'depositDate' => BusinessDate::today()->subDay()->toDateString(),
            ])->assertSessionHasErrors('depositDate');

        $this->post(route('multas.pagos.deposito.store', $pago), $this->ticket())->assertSessionHasNoErrors();
        $this->post(route('multas.pagos.deposito.store', $pago), $this->ticket())->assertSessionHasErrors('receiptId');

        // Un reintento del mismo formulario no deposita dos veces ni deja dos rastros.
        $mismo = $this->ticket();
        $otro = $this->cobrar($multa, '1000.00');
        $this->post(route('multas.pagos.deposito.store', $otro), $mismo)->assertSessionHasNoErrors();
        $this->post(route('multas.pagos.deposito.store', $otro), $mismo)->assertSessionHasNoErrors();

        expect(CashToBankTransfer::query()->count())->toBe(2)
            ->and(AuditEvent::query()->where('action', 'multa.cobro-depositado')->count())->toBe(2);
    }

    public function test_who_can_deposit_and_confirm(): void
    {
        $multa = $this->escenario();
        $pago = $this->cobrar($multa, '300000.00');

        $this->actingAs($this->operador('contador'))
            ->post(route('multas.pagos.deposito.store', $pago), $this->ticket())
            ->assertForbidden();

        $this->actingAs($this->operador())
            ->post(route('multas.pagos.deposito.store', $pago), $this->ticket())
            ->assertSessionHasNoErrors();

        $this->post(route('multas.traslados.cancel', CashToBankTransfer::query()->sole()), [
            'reason' => 'No corresponde', 'idempotencyKey' => (string) Str::uuid(),
        ])->assertForbidden();
    }

    // ── Crédito entero ──────────────────────────────────────────────────

    /**
     * Un crédito de $ 30.000 con $ 10.000 ya imputados tiene libres justo
     * $ 20.000, pero no es la acreditación de un depósito de $ 20.000.
     */
    public function test_the_remainder_of_a_bigger_credit_is_not_the_deposit_credit(): void
    {
        $multa = $this->escenario();
        $pago = $this->cobrar($multa, '20000.00');

        $this->actingAs($this->operador())
            ->post(route('multas.pagos.deposito.store', $pago), $this->ticket())
            ->assertSessionHasNoErrors();
        $traslado = CashToBankTransfer::query()->sole();

        $credito = $this->movimiento($this->laCuenta, BusinessDate::today()->toDateString(), '30000.00');
        app(RegisterBankFundReceipt::class)->handle($credito, '10000.00', (string) Str::uuid(), $this->cajaDe(CashBox::MULTAS), actorId: $this->quienAbre());

        $this->actingAs($this->operador('contador'))
            ->getJson(route('multas.traslados.candidates', $traslado).'?todos=1')
            ->assertOk()
            ->assertJsonCount(0, 'candidates');

        $this->post(route('multas.traslados.confirm', $traslado), [
            'bankTransactionId' => $credito->id, 'idempotencyKey' => (string) Str::uuid(),
        ])->assertSessionHasErrors('bankTransactionId');

        expect($traslado->refresh()->status)->toBe(CashTransferStatus::Deposited);

        $this->rechaza('crédito entero', fn () => DB::table('bank_transaction_allocations')->insert([
            'bank_transaction_id' => $credito->id,
            'financial_event_id' => $this->eventoDeAcreditacion('20000.00'),
            'allocation_role' => 'cash_deposit_confirmation',
            'amount' => '20000.00',
            'allocated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    public function test_a_credited_deposit_is_not_cancelled(): void
    {
        $multa = $this->escenario();
        $pago = $this->cobrar($multa, '20000.00');

        $this->actingAs($this->operador())
            ->post(route('multas.pagos.deposito.store', $pago), $this->ticket())
            ->assertSessionHasNoErrors();
        $traslado = CashToBankTransfer::query()->sole();
        $credito = $this->movimiento($this->laCuenta, BusinessDate::today()->toDateString(), '20000.00');

        $this->actingAs($this->operador('contador'))
            ->post(route('multas.traslados.confirm', $traslado), [
                'bankTransactionId' => $credito->id, 'idempotencyKey' => (string) Str::uuid(),
            ])->assertSessionHasNoErrors();

        $this->post(route('multas.traslados.cancel', $traslado), [
            'reason' => 'Ya no corresponde', 'idempotencyKey' => (string) Str::uuid(),
        ])->assertSessionHasErrors('transferId');

        $this->rechaza('no pasa a', fn () => DB::table('cash_to_bank_transfers')
            ->where('id', $traslado->id)
            ->update(['status' => 'cancelled']));
    }

    // ── Cada caja, lo suyo ──────────────────────────────────────────────

    public function test_a_transfer_of_another_cash_box_does_not_exist_for_the_other_screens(): void
    {
        $multa = $this->escenario();
        $pago = $this->cobrar($multa, '20000.00');

        $this->actingAs($this->operador())
            ->post(route('multas.pagos.deposito.store', $pago), $this->ticket())
            ->assertSessionHasNoErrors();
        $traslado = CashToBankTransfer::query()->sole();

        $this->actingAs($this->operador('administrador'))
            ->getJson(route('traslados.candidates', $traslado))
            ->assertNotFound();
        $this->post(route('traslados.cancel', $traslado), ['reason' => 'Prueba de caja', 'idempotencyKey' => 'x'])
            ->assertNotFound();
    }

    // ── La base ─────────────────────────────────────────────────────────

    /** Fraccionar, agrupar, repetir o cambiar de cuenta: la base lo rechaza sin pasar por el Action. */
    public function test_the_database_keeps_one_whole_collection_per_transfer_to_the_book_account(): void
    {
        $multa = $this->escenario();
        $grande = $this->cobrar($multa, '20000.00')->application()->firstOrFail()->fund_receipt_id;
        $chico = $this->cobrar($multa, '5000.00')->application()->firstOrFail()->fund_receipt_id;
        $otraCuenta = $this->cuenta('310000111222333');

        $this->rechaza('viaja entero', fn () => $this->trasladoCrudo([[$grande, '10000.00']], '10000.00'));
        $this->rechaza('un solo cobro', fn () => $this->trasladoCrudo([[$grande, '20000.00'], [$chico, '5000.00']], '25000.00'));
        $this->rechaza('cuenta con la que opera', fn () => $this->trasladoCrudo([[$grande, '20000.00']], '20000.00', $otraCuenta->id));

        // Válido: la base lo comprueba al chequear el siguiente.
        $this->trasladoCrudo([[$grande, '20000.00']], '20000.00');

        $this->rechaza('traslado vigente', fn () => $this->trasladoCrudo([[$grande, '20000.00']], '20000.00'));
    }

    // ── Escenario ───────────────────────────────────────────────────────

    private function escenario(): Fine
    {
        $this->laCuenta = $this->cuenta();
        $this->abrirMultas($this->laCuenta, '2026-06-01');

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

    private function cobrar(Fine $multa, string $importe, bool $cheque = false): FinePayment
    {
        $this->actingAs($this->operador())->post(route('multas.cobros.store', $multa), [
            'medium' => $cheque ? 'cheque' : 'cash',
            'amount' => $importe,
            'cheque' => $cheque ? ['number' => '00012345', 'bank' => 'Banco Macro', 'issueDate' => null] : null,
            'idempotencyKey' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();

        return FinePayment::query()->latest('id')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function ticket(): array
    {
        return [
            'depositDate' => BusinessDate::today()->toDateString(),
            'depositTime' => '10:15',
            'operationNumber' => '445566',
            'ticket' => UploadedFile::fake()->image('ticket.jpg'),
            'idempotencyKey' => (string) Str::uuid(),
        ];
    }

    /**
     * Un traslado escrito directo en la base, con su asiento, para probar
     * lo que la base rechaza aunque nadie pase por el Action.
     *
     * @param  list<array{int, string}>  $items  recepción e importe
     */
    private function trasladoCrudo(array $items, string $importe, ?int $cuenta = null): void
    {
        $cuenta ??= $this->laCuenta->id;
        $caja = $this->cajaDe(CashBox::MULTAS);

        $evento = app(PostJournalEntry::class)->handle(
            type: FinancialEventType::CashDepositedToBank,
            idempotencyKey: (string) Str::uuid(),
            lines: [
                EntryLine::debit(LedgerAccount::CashInTransit, $importe)->in(Currency::Ars)->onBankAccount($cuenta)->onCashBox($caja),
                EntryLine::credit(LedgerAccount::CashOnHand, $importe)->in(Currency::Ars)->onCashBox($caja),
            ],
            date: BusinessDate::today(),
            cashBoxId: $caja,
            actorId: $this->quienAbre(),
        );

        $traslado = DB::table('cash_to_bank_transfers')->insertGetId([
            'deposit_event_id' => $evento->id, 'cash_box_id' => $caja, 'bank_account_id' => $cuenta,
            'amount' => $importe, 'deposit_date' => BusinessDate::today()->toDateString(), 'status' => 'deposited',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($items as [$recepcion, $parte]) {
            DB::table('cash_to_bank_transfer_items')->insert([
                'cash_to_bank_transfer_id' => $traslado, 'fund_receipt_id' => $recepcion, 'amount' => $parte,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** Un asiento de acreditación de la caja de Multas, sin pasar por el Action. */
    private function eventoDeAcreditacion(string $importe): int
    {
        $caja = $this->cajaDe(CashBox::MULTAS);

        return app(PostJournalEntry::class)->handle(
            type: FinancialEventType::CashDepositCredited,
            idempotencyKey: (string) Str::uuid(),
            lines: [
                EntryLine::debit(LedgerAccount::BankAccount, $importe)->in(Currency::Ars)->onBankAccount($this->laCuenta->id)->onCashBox($caja),
                EntryLine::credit(LedgerAccount::CashInTransit, $importe)->in(Currency::Ars)->onBankAccount($this->laCuenta->id)->onCashBox($caja),
            ],
            date: BusinessDate::today(),
            cashBoxId: $caja,
            actorId: $this->quienAbre(),
        )->id;
    }

    private function saldo(Fine $multa, string $columna): string
    {
        return (string) DB::table('fine_balances')->where('fine_id', $multa->id)->value($columna);
    }

    /** @return array<string, string> */
    private function libro(): array
    {
        return DB::table('journal_lines')
            ->where('cash_box_id', $this->cajaDe(CashBox::MULTAS))
            ->groupBy('account_code')
            ->selectRaw('account_code, SUM(debit - credit)::numeric(19,2)::text AS saldo')
            ->pluck('saldo', 'account_code')
            ->all();
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
