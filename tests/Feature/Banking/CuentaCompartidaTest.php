<?php

declare(strict_types=1);

namespace Tests\Feature\Banking;

use App\Modules\Banking\Actions\RegisterBankFundReceipt;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Support\LatestStatementBalance;
use App\Modules\Shared\Models\CashBox;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsSharedBankAccount;
use Tests\TestCase;

/**
 * Una cuenta que usan varias cajas.
 *
 * Su saldo no se reparte (M08, M12): el banco informa el de la cuenta
 * entera, y cada caja reconoce solo lo que identifica desde su apertura.
 */
class CuentaCompartidaTest extends TestCase
{
    use BuildsSharedBankAccount;
    use RefreshDatabase;

    // ── El saldo según el banco ────────────────────────────────────────

    /** Un extracto viejo importado después no hace retroceder el último saldo. */
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

        expect(app(LatestStatementBalance::class)->of($cuenta->id))
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

        expect(app(LatestStatementBalance::class)->of($cuenta->id))
            ->toBe(['balance' => '1000.00', 'date' => '2026-07-21']);
    }

    // ── Los libros que la usan ─────────────────────────────────────────

    public function test_a_book_and_its_account_share_the_currency(): void
    {
        $dolares = $this->cuenta('310000444555666', 'USD');

        $this->rechaza('cash_book_openings_bank_account_fk', fn () => DB::table('cash_book_openings')->insert([
            'cash_box_id' => $this->cajaDe(CashBox::MULTAS), 'currency' => 'ARS', 'opened_on' => '2026-06-01',
            'declared_total' => '0', 'opened_by' => $this->quienAbre(), 'bank_account_id' => $dolares->id,
        ]));
    }

    /** Lo anterior a la apertura de la caja no entra a su libro como dinero nuevo. */
    public function test_a_credit_before_the_box_opened_is_not_new_money_for_it(): void
    {
        $this->abrirLibrosSinSaldo();
        $cuenta = $this->cuenta();
        $this->abrirMultas($cuenta, '2026-06-01');
        $viejo = $this->movimiento($cuenta, '2026-05-10', '700.00');

        try {
            app(RegisterBankFundReceipt::class)->handle($viejo, '700.00', 'test-viejo', $this->cajaDe(CashBox::MULTAS), actorId: $this->quienAbre());
            $this->fail('Imputó un crédito anterior a la apertura de la caja.');
        } catch (ValidationException $e) {
            expect($e->errors()['bankTransactionId'][0])->toContain('anterior a la apertura');
        }
    }

    public function test_the_same_credit_is_not_consumed_twice_by_two_boxes(): void
    {
        $this->abrirLibrosSinSaldo();
        $cuenta = $this->cuenta();
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
