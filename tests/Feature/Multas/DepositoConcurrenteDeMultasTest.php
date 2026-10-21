<?php

declare(strict_types=1);

namespace Tests\Feature\Multas;

use App\Modules\Banking\Actions\CancelCashToBankTransfer;
use App\Modules\Banking\Actions\ConfirmCashDepositCredit;
use App\Modules\Banking\Enums\CashTransferStatus;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Multas\Actions\CollectFinePayment;
use App\Modules\Multas\Actions\DepositFineCollection;
use App\Modules\Multas\Actions\RegisterFine;
use App\Modules\Multas\Enums\FinePaymentMedium;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Shared\Models\CashBox;
use App\Support\BusinessDate;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsSharedBankAccount;
use Tests\Support\RawCashDeposit;
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

    /**
     * Dos escritores que no pasan por ningún Action —ni siquiera por
     * `PostJournalEntry`, que bloquea la caja— depositan el mismo cobro: la
     * guarda serializa por la recepción y el segundo, al seguir, ya ve al
     * primero y se rechaza. Sin el bloqueo de la guarda el segundo no espera
     * y deja otro traslado vigente: la prueba falla.
     */
    public function test_the_database_keeps_one_live_transfer_per_collection_under_concurrency(): void
    {
        [$recepcion, $cuenta] = $this->cobroEnCaja();
        $caja = $this->cajaDe(CashBox::MULTAS);

        DB::beginTransaction();
        RawCashDeposit::write($recepcion, '20000.00', $cuenta->id, $caja, $this->quienAbre());
        // La guarda corre ahora y deja tomada la recepción hasta el commit.
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $worker = $this->esperando([
            'mode' => 'raw-deposit', 'receipt' => $recepcion, 'amount' => '20000.00',
            'account' => $cuenta->id, 'box' => $caja, 'actor' => $this->quienAbre(),
        ]);
        DB::commit();
        $resultado = $this->resultado($worker);

        expect($resultado['status'])->toBe('database')
            ->and($resultado['message'])->toContain('ya tiene un traslado vigente')
            ->and(DB::table('cash_to_bank_transfer_items')->where('fund_receipt_id', $recepcion)->count())->toBe(1);
    }

    // ── Andamiaje ───────────────────────────────────────────────────────

    /**
     * Un cobro en efectivo de $ 20.000, en la caja.
     *
     * @return array{int, BankAccount}
     */
    private function cobroEnCaja(): array
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

        return [$pago->application()->firstOrFail()->fund_receipt_id, $cuenta];
    }

    /** @return array{CashToBankTransfer, BankTransaction} */
    private function depositoConCredito(): array
    {
        [$recepcion, $cuenta] = $this->cobroEnCaja();
        $pago = FinePayment::query()->whereHas('application', fn ($q) => $q->where('fund_receipt_id', $recepcion))->firstOrFail();

        $traslado = app(DepositFineCollection::class)->handle($pago, [
            'depositDate' => BusinessDate::today(),
        ], UploadedFile::fake()->image('ticket.jpg'), (string) Str::uuid(), $this->quienAbre());

        return [$traslado, $this->movimiento($cuenta, BusinessDate::today()->toDateString(), '20000.00')];
    }

    private function worker(string $modo, CashToBankTransfer $traslado, BankTransaction $credito): Process
    {
        return $this->esperando([
            'mode' => $modo, 'transfer' => $traslado->id, 'transaction' => $credito->id, 'actor' => $this->quienAbre(),
        ]);
    }

    /**
     * Lanza el worker y vuelve cuando PostgreSQL lo muestra esperando un bloqueo.
     *
     * @param  array<string, mixed>  $datos
     */
    private function esperando(array $datos): Process
    {
        $tag = 'transfer-'.Str::uuid();
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
