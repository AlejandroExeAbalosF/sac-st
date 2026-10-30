<?php

declare(strict_types=1);

namespace Tests\Feature\Multas;

use App\Modules\Banking\Actions\LinkBankAccountToCashBox;
use App\Modules\Banking\Actions\RegisterBankFundReceipt;
use App\Modules\Banking\Actions\RetireCashBoxBankAccount;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\CashBoxBankAccount;
use App\Modules\Multas\Actions\CollectFinePayment;
use App\Modules\Multas\Actions\RegisterFine;
use App\Modules\Multas\Enums\FinePaymentMedium;
use App\Modules\Shared\Models\CashBox;
use App\Support\BusinessDate;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsSharedBankAccount;
use Tests\TestCase;

/**
 * Dar de baja una cuenta mientras otro la usa: el segundo espera al primero
 * y, al seguir, ve lo que confirmó (`lock_cash_box_account`).
 *
 * Sin el candado común, los dos terminaban bien y lo registrado quedaba fuera
 * de la vigencia. Sin `RefreshDatabase`, como `DepositoConcurrenteDeMultasTest`:
 * el segundo proceso necesita ver los datos confirmados.
 */
class CuentasConcurrentesDeMultasTest extends TestCase
{
    use BuildsSharedBankAccount;

    /** @var list<Process> */
    private array $workers = [];

    private BankAccount $a;

    private BankAccount $b;

    private int $caja;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('sacst_test', DB::connection()->getDatabaseName());
        $this->assertSame(0, DB::transactionLevel());
        RefreshDatabaseState::$migrated = false;
        $this->artisan('migrate:fresh', ['--seed' => true, '--seeder' => CatalogosSeeder::class])->assertExitCode(0);

        $this->a = $this->cuenta();
        $this->b = $this->cuenta('310000111222333');
        $this->abrirMultas($this->a, '2026-06-01');
        $this->caja = $this->cajaDe(CashBox::MULTAS);
        app(LinkBankAccountToCashBox::class)->handle($this->caja, $this->b->id, null, false, $this->quienAbre());
    }

    protected function tearDown(): void
    {
        try {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($this->workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
            RefreshDatabaseState::$migrated = false;
        } finally {
            parent::tearDown();
        }
    }

    /** Mientras se identifica un crédito de hoy, la baja a ayer espera y después lo ve. */
    public function test_retiring_while_a_credit_is_being_identified_waits_and_is_refused(): void
    {
        $hoy = BusinessDate::today();
        $credito = $this->movimiento($this->a, $hoy->toDateString(), '300.00');

        DB::beginTransaction();
        app(RegisterBankFundReceipt::class)->handle($credito, '300.00', (string) Str::uuid(), $this->caja, actorId: $this->quienAbre());

        $worker = $this->esperando([
            'mode' => 'retire', 'link' => $this->vinculoDeA()->id, 'until' => $hoy->subDay()->toDateString(),
            'newDefault' => $this->b->id, 'actor' => $this->quienAbre(),
        ]);
        DB::commit();
        $resultado = $this->resultado($worker);

        expect($resultado['status'])->toBe('validation')
            ->and($resultado['errors'])->toHaveKey('until')
            ->and($this->vinculoDeA()->valid_until)->toBeNull();
    }

    /** Mientras se da de baja la cuenta, un depósito de hoy espera y después la encuentra cerrada. */
    public function test_depositing_while_the_account_is_being_retired_waits_and_is_refused(): void
    {
        $recepcion = $this->cobroEnCaja();

        DB::beginTransaction();
        app(RetireCashBoxBankAccount::class)->handle($this->vinculoDeA(), BusinessDate::today()->subDay(), $this->b->id, $this->quienAbre());

        $worker = $this->esperando([
            'mode' => 'raw-deposit', 'receipt' => $recepcion, 'amount' => '20000.00',
            'account' => $this->a->id, 'box' => $this->caja, 'actor' => $this->quienAbre(),
        ]);
        DB::commit();
        $resultado = $this->resultado($worker);

        expect($resultado['status'])->toBe('database')
            ->and($resultado['message'])->toContain('no está habilitada para esta caja')
            ->and(DB::table('cash_to_bank_transfers')->count())->toBe(0);
    }

    /** Mientras la cuenta pasa a dólares, vincularla a la caja en pesos espera y después ve la moneda nueva. */
    public function test_linking_while_the_currency_is_being_changed_waits_and_is_refused(): void
    {
        $nueva = $this->cuenta('310000444555666');

        DB::beginTransaction();
        DB::table('bank_accounts')->where('id', $nueva->id)->update(['currency' => 'USD']);

        $worker = $this->esperando([
            'mode' => 'link', 'box' => $this->caja, 'account' => $nueva->id, 'actor' => $this->quienAbre(),
        ]);
        DB::commit();
        $resultado = $this->resultado($worker);

        expect($resultado['status'])->toBe('validation')
            ->and($resultado['errors'])->toHaveKey('bankAccountId')
            ->and(CashBoxBankAccount::query()->where('bank_account_id', $nueva->id)->exists())->toBeFalse();
    }

    /**
     * Sin pasar por el Action, la clave foránea compuesta hace lo mismo: el
     * vínculo que nació leyendo pesos no se confirma contra una cuenta en
     * dólares.
     */
    public function test_the_database_keeps_link_and_account_in_the_same_currency_under_concurrency(): void
    {
        $nueva = $this->cuenta('310000444555666');

        DB::beginTransaction();
        DB::table('bank_accounts')->where('id', $nueva->id)->update(['currency' => 'USD']);

        $worker = $this->esperando([
            'mode' => 'raw-link', 'box' => $this->caja, 'account' => $nueva->id, 'actor' => $this->quienAbre(),
        ]);
        DB::commit();
        $resultado = $this->resultado($worker);

        expect($resultado['status'])->toBe('database')
            ->and($resultado['message'])->toContain('cash_box_bank_accounts_bank_account_fk')
            ->and(CashBoxBankAccount::query()->where('bank_account_id', $nueva->id)->exists())->toBeFalse();
    }

    private function vinculoDeA(): CashBoxBankAccount
    {
        return CashBoxBankAccount::query()->where('bank_account_id', $this->a->id)->sole();
    }

    private function cobroEnCaja(): int
    {
        $multa = app(RegisterFine::class)->handle([
            'caseNumber' => '169607/24-0',
            'resolution' => ['text' => 'N°0649/26', 'issuedOn' => '2026-05-07'],
            'acts' => [['actType' => 'HYS', 'text' => 'N°1089657', 'issuedOn' => null]],
            'caption' => 'CRISTOMAX S.A.S',
            'registeredOn' => '2026-05-07',
            'amount' => '1400000.00',
        ], $this->quienAbre());

        $pago = app(CollectFinePayment::class)->handle(
            $multa, FinePaymentMedium::Cash, '20000.00', (string) Str::uuid(), $this->quienAbre(),
        );

        return $pago->application()->firstOrFail()->fund_receipt_id;
    }

    /**
     * Lanza el worker y vuelve cuando PostgreSQL lo muestra esperando un bloqueo.
     *
     * @param  array<string, mixed>  $datos
     */
    private function esperando(array $datos): Process
    {
        $tag = 'cuentas-'.Str::uuid();
        $worker = new Process([PHP_BINARY, base_path('tests/Support/transfer-worker.php')], base_path());
        $worker->setTimeout(30);
        $worker->setInput(json_encode([
            'database' => config('database.connections.pgsql'), 'tag' => $tag, ...$datos,
        ], JSON_THROW_ON_ERROR));
        $this->workers[] = $worker;
        $worker->start();

        $deadline = microtime(true) + 10;

        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $espera = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE application_name = ?', [$tag]);

            if ($espera?->wait_event_type === 'Lock') {
                return $worker;
            }

            if (! $worker->isRunning()) {
                $this->fail('El worker terminó sin esperar el bloqueo: '.$worker->getOutput().$worker->getErrorOutput());
            }

            usleep(20000);
        } while (microtime(true) < $deadline);

        $this->fail('No se observó la espera real en PostgreSQL: '.$worker->getOutput().$worker->getErrorOutput());
    }

    /** @return array<string, mixed> */
    private function resultado(Process $worker): array
    {
        $worker->wait();
        $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
        $lineas = explode("\n", trim($worker->getOutput()));

        return json_decode((string) end($lineas), true, 512, JSON_THROW_ON_ERROR);
    }
}
