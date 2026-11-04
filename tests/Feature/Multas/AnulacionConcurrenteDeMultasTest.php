<?php

declare(strict_types=1);

namespace Tests\Feature\Multas;

use App\Modules\Banking\Models\BankAccount;
use App\Modules\Multas\Actions\CollectFinePayment;
use App\Modules\Multas\Actions\DepositFineCollection;
use App\Modules\Multas\Actions\RegisterFine;
use App\Modules\Multas\Actions\VoidFineCollection;
use App\Modules\Multas\Enums\FinePaymentMedium;
use App\Modules\Multas\Enums\FinePaymentStatus;
use App\Modules\Multas\Enums\FineReasonCode;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Support\Reason;
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
 * Anular y depositar el mismo cobro a la vez: los dos toman la fila de la
 * recepción, y el segundo espera y encuentra lo que hizo el primero.
 *
 * Sin `RefreshDatabase`, como `RecursoConcurrenteDeMultasTest`: el segundo
 * proceso necesita ver los datos confirmados.
 */
class AnulacionConcurrenteDeMultasTest extends TestCase
{
    use BuildsSharedBankAccount;

    /** @var list<Process> */
    private array $workers = [];

    private BankAccount $laCuenta;

    private FinePayment $pago;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('sacst_test', DB::connection()->getDatabaseName());
        $this->assertSame(0, DB::transactionLevel());
        RefreshDatabaseState::$migrated = false;
        $this->artisan('migrate:fresh', ['--seed' => true, '--seeder' => CatalogosSeeder::class])->assertExitCode(0);

        $this->laCuenta = $this->cuenta();
        $this->abrirMultas($this->laCuenta, '2026-06-01');
        $multa = app(RegisterFine::class)->handle([
            'caseNumber' => '169607/24-0',
            'resolution' => ['text' => 'N°0649/26', 'issuedOn' => '2026-05-07'],
            'acts' => [['actType' => 'HYS', 'text' => 'N°1089657', 'issuedOn' => null]],
            'caption' => 'CRISTOMAX S.A.S',
            'registeredOn' => '2026-05-07',
            'amount' => '1400000.00',
        ], $this->quienAbre());
        $this->pago = app(CollectFinePayment::class)->handle($multa, FinePaymentMedium::Cash, '400000.00', (string) Str::uuid(), $this->quienAbre());
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

    /** Mientras se deposita, anular espera y después lo encuentra depositado. */
    public function test_voiding_while_it_is_being_deposited_waits_and_is_refused(): void
    {
        DB::beginTransaction();
        $this->depositar();

        $worker = $this->esperando(['mode' => 'void', 'payment' => $this->pago->id, 'actor' => $this->quienAbre()]);
        DB::commit();
        $resultado = $this->resultado($worker);

        expect($resultado['status'])->toBe('validation')
            ->and($resultado['errors'])->toHaveKey('reason')
            ->and($this->pago->refresh()->status)->toBe(FinePaymentStatus::Applied);
    }

    /** Mientras se anula, depositar espera y después lo encuentra anulado. */
    public function test_depositing_while_it_is_being_voided_waits_and_is_refused(): void
    {
        DB::beginTransaction();
        $this->anular();

        $worker = $this->esperando(['mode' => 'deposit', 'payment' => $this->pago->id, 'actor' => $this->quienAbre()]);
        DB::commit();
        $resultado = $this->resultado($worker);

        expect($resultado['status'])->toBe('validation')
            ->and($resultado['errors'])->toHaveKey('receiptId')
            ->and(DB::table('cash_to_bank_transfers')->count())->toBe(0);
    }

    /** Sin pasar por el Action: un depósito escrito directo espera y la base lo frena. */
    public function test_the_database_refuses_a_raw_deposit_while_it_is_being_voided(): void
    {
        DB::beginTransaction();
        $this->anular();

        $worker = $this->esperando([
            'mode' => 'raw-deposit', 'receipt' => $this->recepcion(), 'amount' => '400000.00',
            'account' => $this->laCuenta->id, 'box' => $this->cajaDe(CashBox::MULTAS), 'actor' => $this->quienAbre(),
        ]);
        DB::commit();
        $resultado = $this->resultado($worker);

        expect($resultado['status'])->toBe('database')
            ->and($resultado['message'])->toContain('Ese cobro se anuló')
            ->and(DB::table('cash_to_bank_transfers')->count())->toBe(0);
    }

    /** Sin pasar por el Action: una anulación escrita directo espera al depósito y la base la frena. */
    public function test_the_database_refuses_a_raw_void_while_it_is_being_deposited(): void
    {
        DB::beginTransaction();
        RawCashDeposit::write($this->recepcion(), '400000.00', $this->laCuenta->id, $this->cajaDe(CashBox::MULTAS), $this->quienAbre());

        $worker = $this->esperando(['mode' => 'raw-void', 'payment' => $this->pago->id, 'actor' => $this->quienAbre()]);
        DB::commit();
        $resultado = $this->resultado($worker);

        expect($resultado['status'])->toBe('database')
            ->and($resultado['message'])->toContain('ya se depositó')
            ->and(DB::table('fine_payment_voids')->count())->toBe(0);
    }

    private function recepcion(): int
    {
        return $this->pago->application()->firstOrFail()->fund_receipt_id;
    }

    private function anular(): void
    {
        app(VoidFineCollection::class)->handle($this->pago, new Reason(FineReasonCode::DataEntryError, 'Se tipeó mal'), $this->quienAbre());
    }

    private function depositar(): void
    {
        app(DepositFineCollection::class)->handle(
            $this->pago,
            ['depositDate' => BusinessDate::today()],
            UploadedFile::fake()->image('ticket.jpg'),
            (string) Str::uuid(),
            $this->quienAbre(),
        );
    }

    /**
     * Lanza el worker y vuelve cuando PostgreSQL lo muestra esperando un bloqueo.
     *
     * @param  array<string, mixed>  $datos
     */
    private function esperando(array $datos): Process
    {
        $tag = 'anulacion-'.Str::uuid();
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
