<?php

declare(strict_types=1);

namespace Tests\Feature\Banking;

use App\Modules\Banking\Actions\RegisterBankFundReceipt;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankAccountOpening;
use App\Modules\Banking\Support\AccountCutoffBalance;
use App\Modules\Ledger\Actions\RegisterOpeningBalance;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Models\Attachment;
use App\Modules\Shared\Models\AuditEvent;
use App\Modules\Shared\Models\CashBox;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsSharedBankAccount;
use Tests\TestCase;

/**
 * El corte de una cuenta que usan varias cajas.
 *
 * Su saldo se declara una vez, para la cuenta, sin repartir (M08, M12).
 * Cada regla se prueba en los dos lados: el mensaje del Action y el
 * rechazo de la base.
 */
class CuentaCompartidaTest extends TestCase
{
    use BuildsSharedBankAccount;
    use RefreshDatabase;

    // ── El corte ────────────────────────────────────────────────────────

    public function test_the_cutoff_is_declared_once_with_its_evidence(): void
    {
        $cuenta = $this->cuenta();

        $this->actingAs($this->operador('administrador'))
            ->post(route('banco.cuentas.corte.store', $cuenta), [
                'cutoffOn' => '2026-05-31',
                'statementBalance' => '-1500.50',
                'notes' => 'Saldo informado por el banco',
                'evidence' => UploadedFile::fake()->create('saldo.pdf', 20, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('banco.cuentas.index'));

        $corte = BankAccountOpening::query()->sole();
        expect($corte->cutoff_on->toDateString())->toBe('2026-05-31')
            ->and($corte->statement_balance)->toBe('-1500.50')
            ->and(Attachment::query()->where('subject_type', AttachmentSubject::BankAccountOpening)->where('subject_id', $corte->id)->exists())->toBeTrue()
            ->and(AuditEvent::query()->where('action', 'banco.cuenta.corte-declarado')->exists())->toBeTrue();

        $this->actingAs($this->operador('administrador'))
            ->post(route('banco.cuentas.corte.store', $cuenta), ['cutoffOn' => '2026-06-30', 'statementBalance' => '1.00'])
            ->assertSessionHasErrors(['cutoffOn' => 'Esta cuenta ya tiene su corte declarado, y un corte no se rehace.']);

        $this->actingAs($this->operador('consulta'))
            ->get(route('banco.cuentas.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('cutoffs', 1)
                ->where('cutoffs.0.statementBalance', '-1500.50')
                ->where('canDeclareCutoff', false));

        $this->expectException(QueryException::class);
        DB::table('bank_account_openings')->where('id', $corte->id)->update(['statement_balance' => '0']);
    }

    public function test_only_the_administrator_declares_a_cutoff(): void
    {
        $cuenta = $this->cuenta();

        $this->actingAs($this->operador('contador'))
            ->post(route('banco.cuentas.corte.store', $cuenta), ['cutoffOn' => '2026-05-31', 'statementBalance' => '1.00'])
            ->assertForbidden();
    }

    public function test_the_declared_balance_is_the_one_in_the_statement(): void
    {
        $cuenta = $this->cuenta();
        // Un archivo que abarca el corte: al 31/05 había 800.
        $this->extracto($cuenta, [
            ['2026-05-30', '300.00', TransactionDirection::Credit, '800.00'],
            ['2026-06-02', '200.00', TransactionDirection::Credit, '1000.00'],
        ]);

        try {
            $this->cortar($cuenta, '2026-05-31', '900.00');
            $this->fail('Aceptó un saldo distinto del extracto.');
        } catch (ValidationException $e) {
            expect($e->errors()['statementBalance'][0])->toContain('$ 800,00');
        }

        expect($this->cortar($cuenta, '2026-05-31', '800.00')->statement_balance)->toBe('800.00');
    }

    /** Revisión de la etapa 3, R1: un extracto que empieza después no dice nada del corte. */
    public function test_a_statement_that_starts_after_the_cutoff_does_not_decide_it(): void
    {
        $cuenta = $this->cuenta();
        /*
         * Solo julio: el saldo anterior al 01/07 no es el del 31/05, falta
         * junio. Aunque la importación diga cubrir el año: lo que cuenta son
         * sus filas.
         */
        $this->movimiento($cuenta, '2026-07-01', '100.00', saldo: '1600.00', importacion: $this->importacion($cuenta, '2026-01-01', '2026-12-31'));

        expect($this->cortar($cuenta, '2026-05-31', '1000.00')->statement_balance)->toBe('1000.00');

        $this->actingAs($this->operador('consulta'))
            ->get(route('banco.cuentas.index'))
            ->assertInertia(fn (Assert $page) => $page->where('cutoffs.0.statementAtCutoff', null));

        // Cuando llega el archivo que abarca el corte, la verificación se completa.
        $this->extracto($cuenta, [
            ['2026-05-29', '50.00', TransactionDirection::Credit, '1000.00'],
            ['2026-06-03', '500.00', TransactionDirection::Credit, '1500.00'],
        ]);

        $this->actingAs($this->operador('consulta'))
            ->get(route('banco.cuentas.index'))
            ->assertInertia(fn (Assert $page) => $page->where('cutoffs.0.statementAtCutoff', '1000.00'));
    }

    /** Revisión de la etapa 3, R2: se compara el saldo con lo movido hasta ese mismo punto. */
    public function test_the_reconciliation_stops_at_the_last_reported_balance(): void
    {
        $cuenta = $this->cuenta();
        $corte = $this->cortar($cuenta, '2026-05-31', '1000.00');
        $this->movimiento($cuenta, '2026-06-01', '100.00', saldo: '1100.00');
        $this->movimiento($cuenta, '2026-06-02', '50.00', TransactionDirection::Debit);

        expect(app(AccountCutoffBalance::class)->reconcile($corte))->toBe([
            'expected' => '1100.00',
            'latest' => '1100.00',
            'latestDate' => '2026-06-01',
            'difference' => '0.00',
            'unverifiedCount' => 1,
        ]);

        // Dentro de un mismo día manda el orden del archivo: el débito sin saldo va después.
        $otra = $this->cuenta('310000111222333');
        $corteOtra = $this->cortar($otra, '2026-05-31', '1000.00');
        $this->extracto($otra, [
            ['2026-06-05', '100.00', TransactionDirection::Credit, '1100.00'],
            ['2026-06-05', '50.00', TransactionDirection::Debit, null],
        ]);

        expect(app(AccountCutoffBalance::class)->reconcile($corteOtra))
            ->toMatchArray(['expected' => '1100.00', 'difference' => '0.00', 'unverifiedCount' => 1]);
    }

    /**
     * Seguimiento de la revisión de la etapa 3, R4: un extracto viejo importado
     * después no hace retroceder el último saldo.
     */
    public function test_an_older_statement_imported_later_does_not_move_the_balance_back(): void
    {
        $cuenta = $this->cuenta();
        [$a] = $this->extracto($cuenta, [
            ['2026-07-22', '100.00', TransactionDirection::Credit, '1100.00'],
            ['2026-07-22', '200.00', TransactionDirection::Credit, '1300.00'],
        ]);

        // El archivo anterior trae el 21/07 y repite A: A comparte, B ya se sabía posterior.
        $viejo = $this->importacion($cuenta, '2026-07-21', '2026-07-22');
        $this->movimiento($cuenta, '2026-07-21', '50.00', saldo: '1000.00', importacion: $viejo, fila: 2);
        $this->filaRepetida($viejo, $a, 1);

        expect(app(AccountCutoffBalance::class)->latest($cuenta->id))
            ->toBe(['balance' => '1300.00', 'date' => '2026-07-22']);
    }

    /** Dos archivos del mismo día sin nada en común: el día no da saldo. */
    public function test_a_day_whose_order_nobody_proves_gives_no_balance(): void
    {
        $cuenta = $this->cuenta();
        $this->extracto($cuenta, [
            ['2026-07-21', '50.00', TransactionDirection::Credit, '1000.00'],
            ['2026-07-22', '100.00', TransactionDirection::Credit, '1100.00'],
        ]);
        $this->movimiento($cuenta, '2026-07-22', '30.00', saldo: '1130.00');

        $corte = $this->cortar($cuenta, '2026-07-20', '950.00');

        expect(app(AccountCutoffBalance::class)->latest($cuenta->id))
            ->toBe(['balance' => '1000.00', 'date' => '2026-07-21'])
            ->and(app(AccountCutoffBalance::class)->reconcile($corte))
            ->toMatchArray(['expected' => '1000.00', 'difference' => '0.00', 'unverifiedCount' => 2]);
    }

    public function test_an_account_whose_balance_a_cash_box_declared_is_not_shared(): void
    {
        $cuenta = $this->cuenta();
        app(RegisterOpeningBalance::class)->handle(
            cashBoxId: $this->cajaDe(CashBox::HABERES),
            balances: [LedgerAccount::BankAccount->value => '500.00'],
            date: CarbonImmutable::parse('2026-06-01'),
            actorId: $this->quienAbre(),
            bankAccountId: $cuenta->id,
        );

        try {
            $this->cortar($cuenta, '2026-05-31');
            $this->fail('Aceptó un corte sobre una cuenta cuyo saldo ya declaró una caja.');
        } catch (ValidationException $e) {
            expect($e->errors()['cutoffOn'][0])->toContain('ya declaró el saldo de esta cuenta');
        }

        $this->rechaza('ya declaro el saldo', fn () => DB::table('bank_account_openings')->insert([
            'bank_account_id' => $cuenta->id, 'cutoff_on' => '2026-05-31', 'statement_balance' => '0', 'declared_by' => $this->quienAbre(),
        ]));
    }

    public function test_an_opening_does_not_bring_the_shared_balance(): void
    {
        $cuenta = $this->cuenta();
        $this->cortar($cuenta);

        try {
            app(RegisterOpeningBalance::class)->handle(
                cashBoxId: $this->cajaDe(CashBox::HABERES),
                balances: [LedgerAccount::BankAccount->value => '500.00'],
                date: CarbonImmutable::parse('2026-06-01'),
                actorId: $this->quienAbre(),
                bankAccountId: $cuenta->id,
            );
            $this->fail('Aceptó el saldo compartido en una apertura.');
        } catch (ValidationException $e) {
            expect($e->errors()['balances'][0])->toContain('es compartida');
        }

        $this->rechaza('corte compartido', function () use ($cuenta): void {
            $evento = (int) DB::table('financial_events')->insertGetId([
                'public_id' => (string) Str::ulid(), 'cash_box_id' => $this->cajaDe(CashBox::HABERES),
                'event_type' => 'opening_balance', 'event_date' => '2026-06-01', 'status' => 'posted',
                'posted_at' => now(), 'idempotency_key' => 'test-apertura-compartida', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('journal_lines')->insert([
                'financial_event_id' => $evento, 'account_code' => 'BANK_ACCOUNT', 'debit' => '500.00', 'credit' => '0',
                'cash_box_id' => $this->cajaDe(CashBox::HABERES), 'currency' => 'ARS', 'bank_account_id' => $cuenta->id,
            ]);
        });
    }

    public function test_the_cutoff_goes_before_every_book_that_uses_the_account(): void
    {
        $cuenta = $this->cuenta();
        $this->cortar($cuenta, '2026-05-31');

        try {
            $this->abrirMultas($cuenta, '2026-05-31');
            $this->fail('Abrió la caja el día del corte.');
        } catch (ValidationException $e) {
            expect($e->errors()['date'][0])->toContain('31/05/2026');
        }

        $this->rechaza('el libro se abre despues', fn () => DB::table('cash_book_openings')->insert([
            'cash_box_id' => $this->cajaDe(CashBox::MULTAS), 'currency' => 'ARS', 'opened_on' => '2026-05-15',
            'declared_total' => '0', 'opened_by' => $this->quienAbre(), 'bank_account_id' => $cuenta->id,
        ]));

        // Al revés: con el libro ya abierto, el corte no puede caer en su vida.
        $otra = $this->cuenta('310000111222333');
        $this->abrirMultas($otra, '2026-06-01');

        try {
            $this->cortar($otra, '2026-06-01');
            $this->fail('Aceptó un corte dentro de la vida de un libro.');
        } catch (ValidationException $e) {
            expect($e->errors()['cutoffOn'][0])->toContain('desde el 01/06/2026');
        }

        $this->rechaza('el corte tiene que ser anterior', fn () => DB::table('bank_account_openings')->insert([
            'bank_account_id' => $otra->id, 'cutoff_on' => '2026-06-15', 'statement_balance' => '0', 'declared_by' => $this->quienAbre(),
        ]));
    }

    public function test_a_book_and_its_account_share_the_currency(): void
    {
        $dolares = $this->cuenta('310000444555666', 'USD');

        $this->rechaza('cash_book_openings_bank_account_fk', fn () => DB::table('cash_book_openings')->insert([
            'cash_box_id' => $this->cajaDe(CashBox::MULTAS), 'currency' => 'ARS', 'opened_on' => '2026-06-01',
            'declared_total' => '0', 'opened_by' => $this->quienAbre(), 'bank_account_id' => $dolares->id,
        ]));
    }

    // ── Lo que entra a los libros ──────────────────────────────────────

    public function test_a_credit_up_to_the_cutoff_is_not_new_money_for_any_box(): void
    {
        $this->abrirLibrosSinSaldo();
        $cuenta = $this->cuenta();
        $viejo = $this->movimiento($cuenta, '2026-05-10', '700.00');
        $nuevo = $this->movimiento($cuenta, '2026-06-05', '300.00');
        $this->cortar($cuenta, '2026-05-31', '700.00');

        $registrar = app(RegisterBankFundReceipt::class);

        // Haberes abrió en el 2000: sin el corte de la cuenta, el crédito entraba.
        try {
            $registrar->handle($viejo, '700.00', 'test-viejo', $this->cajaDe(CashBox::HABERES), actorId: $this->quienAbre());
            $this->fail('Imputó un crédito incluido en el saldo al corte.');
        } catch (ValidationException $e) {
            expect($e->errors()['bankTransactionId'][0])->toContain('saldo declarado al 31/05/2026');
        }

        $recepcion = $registrar->handle($nuevo, '300.00', 'test-nuevo', $this->cajaDe(CashBox::HABERES), actorId: $this->quienAbre());

        $this->rechaza('dentro del saldo informado al corte', fn () => DB::table('bank_transaction_allocations')->insert([
            'bank_transaction_id' => $viejo->id, 'financial_event_id' => $recepcion->financial_event_id,
            'allocation_role' => 'funds_received', 'amount' => '700.00', 'allocated_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    public function test_a_cutoff_cannot_swallow_a_credit_already_in_a_book(): void
    {
        $this->abrirLibrosSinSaldo();
        $cuenta = $this->cuenta();
        $credito = $this->movimiento($cuenta, '2026-05-10', '700.00');
        app(RegisterBankFundReceipt::class)->handle($credito, '700.00', 'test-imputado', $this->cajaDe(CashBox::HABERES), actorId: $this->quienAbre());

        try {
            $this->cortar($cuenta, '2026-05-31');
            $this->fail('El corte se tragó un crédito imputado.');
        } catch (ValidationException $e) {
            expect($e->errors()['cutoffOn'][0])->toContain('10/05/2026');
        }

        $this->rechaza('ya imputado en un libro', fn () => DB::table('bank_account_openings')->insert([
            'bank_account_id' => $cuenta->id, 'cutoff_on' => '2026-05-31', 'statement_balance' => '0', 'declared_by' => $this->quienAbre(),
        ]));
    }

    public function test_the_same_credit_is_not_consumed_twice_by_two_boxes(): void
    {
        $this->abrirLibrosSinSaldo();
        $cuenta = $this->cuenta();
        $this->cortar($cuenta, '2026-05-31');
        $this->abrirMultas($cuenta, '2026-06-01');
        $credito = $this->movimiento($cuenta, '2026-06-10', '1000.00');
        $registrar = app(RegisterBankFundReceipt::class);

        $registrar->handle($credito, '1000.00', 'test-haberes', $this->cajaDe(CashBox::HABERES), actorId: $this->quienAbre());

        $this->expectException(ValidationException::class);
        $registrar->handle($credito, '1000.00', 'test-multas', $this->cajaDe(CashBox::MULTAS), actorId: $this->quienAbre());
    }

    private function rechaza(string $fragmento, callable $accion): void
    {
        try {
            DB::transaction(function () use ($accion): void {
                $accion();
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
        } catch (QueryException $e) {
            expect($e->getMessage())->toContain($fragmento);

            return;
        }

        $this->fail("La base aceptó lo que tenía que rechazar ({$fragmento}).");
    }
}
