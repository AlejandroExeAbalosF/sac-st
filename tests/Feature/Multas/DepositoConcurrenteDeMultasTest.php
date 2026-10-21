<?php

declare(strict_types=1);

namespace Tests\Feature\Multas;

use App\Modules\Banking\Actions\CancelCashToBankTransfer;
use App\Modules\Banking\Actions\ConfirmCashDepositCredit;
use App\Modules\Banking\Enums\CashTransferStatus;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Multas\Actions\CollectFinePayment;
use App\Modules\Multas\Actions\DepositFineCollection;
use App\Modules\Multas\Actions\RegisterFine;
use App\Modules\Multas\Enums\FinePaymentMedium;
use App\Support\BusinessDate;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsSharedBankAccount;
use Tests\TestCase;

/**
 * Acreditar y cancelar el mismo depósito a la vez deja un solo resultado.
 *
 * Sin `RefreshDatabase`: el segundo proceso necesita ver los datos
 * confirmados. La base se recrea al empezar y se marca para que el próximo
 * test la vuelva a recrear.
 */
class DepositoConcurrenteDeMultasTest extends TestCase
{
    use BuildsSharedBankAccount;

    /** @var list<Process> */
    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('sacst_test', DB::connection()->getDatabaseName());
        $this->assertSame(0, DB::transactionLevel());
        RefreshDatabaseState::$migrated = false;
        $this->artisan('migrate:fresh', ['--seed' => true, '--seeder' => CatalogosSeeder::class])->assertExitCode(0);
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

    /** Mientras se acredita, cancelar espera y después lo encuentra acreditado. */
    public function test_cancelling_while_it_is_being_credited_waits_and_is_refused(): void
    {
        [$traslado, $credito] = $this->depositoConCredito();

        DB::beginTransaction();
        app(ConfirmCashDepositCredit::class)->handle($traslado, $credito, (string) Str::uuid(), $this->quienAbre());

        $worker = $this->worker('cancel', $traslado, $credito);
        DB::commit();
        $resultado = $this->resultado($worker);

        expect($resultado['status'])->toBe('validation')
            ->and($resultado['errors'])->toHaveKey('transferId')
            ->and($traslado->refresh()->status)->toBe(CashTransferStatus::BankConfirmed);
    }

    /** Mientras se cancela, acreditar espera y después lo encuentra cancelado. */
    public function test_crediting_while_it_is_being_cancelled_waits_and_is_refused(): void
    {
        [$traslado, $credito] = $this->depositoConCredito();

        DB::beginTransaction();
        app(CancelCashToBankTransfer::class)->handle($traslado, 'Se cargó mal el ticket', (string) Str::uuid(), $this->quienAbre());

        $worker = $this->worker('confirm', $traslado, $credito);
        DB::commit();
        $resultado = $this->resultado($worker);

        expect($resultado['status'])->toBe('validation')
            ->and($resultado['errors'])->toHaveKey('transferId')
            ->and($traslado->refresh()->status)->toBe(CashTransferStatus::Cancelled)
            ->and(DB::table('bank_transaction_allocations')->where('bank_transaction_id', $credito->id)->count())->toBe(0);
    }

    // ── Andamiaje ───────────────────────────────────────────────────────

    /** @return array{CashToBankTransfer, BankTransaction} */
    private function depositoConCredito(): array
    {
        $cuenta = $this->cuenta();
        $this->cortar($cuenta, '2026-05-31');
        $this->abrirMultas($cuenta, '2026-06-01');

        $multa = app(RegisterFine::class)->handle([
            'caseNumber' => '169607/24-0',
            'resolution' => ['text' => 'N°0649/26', 'issuedOn' => '2026-05-07'],
            'acts' => [['actType' => 'HYS', 'text' => 'N°1089657', 'issuedOn' => null]],
            'caption' => 'CRISTOMAX S.A.S',
            'registeredOn' => '2026-05-07',
            'amount' => '1400000.00',
        ], $this->quienAbre());

        $pago = app(CollectFinePayment::class)->handle($multa, FinePaymentMedium::Cash, '20000.00', (string) Str::uuid(), $this->quienAbre());

        $traslado = app(DepositFineCollection::class)->handle($pago, [
            'depositDate' => BusinessDate::today(),
        ], UploadedFile::fake()->image('ticket.jpg'), (string) Str::uuid(), $this->quienAbre());

        return [$traslado, $this->movimiento($cuenta, BusinessDate::today()->toDateString(), '20000.00')];
    }

    private function worker(string $modo, CashToBankTransfer $traslado, BankTransaction $credito): Process
    {
        $tag = 'transfer-'.Str::uuid();
        $worker = new Process([PHP_BINARY, base_path('tests/Support/transfer-worker.php')], base_path());
        $worker->setTimeout(30);
        $worker->setInput(json_encode([
            'database' => config('database.connections.pgsql'), 'tag' => $tag, 'mode' => $modo,
            'transfer' => $traslado->id, 'transaction' => $credito->id, 'actor' => $this->quienAbre(),
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
