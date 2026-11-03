<?php

declare(strict_types=1);

namespace Tests\Feature\Multas;

use App\Modules\Multas\Actions\CollectFinePayment;
use App\Modules\Multas\Actions\RegisterFine;
use App\Modules\Multas\Actions\RegisterFineAppeal;
use App\Modules\Multas\Enums\AppealKind;
use App\Modules\Multas\Enums\FinePaymentMedium;
use App\Modules\Multas\Models\Fine;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsSharedBankAccount;
use Tests\TestCase;

/**
 * El recurso y un cobro de la misma multa a la vez: el segundo espera al
 * primero sobre la fila de la multa y, al seguir, ve lo que confirmó.
 *
 * Sin `RefreshDatabase`, como `CuentasConcurrentesDeMultasTest`: el segundo
 * proceso necesita ver los datos confirmados.
 */
class RecursoConcurrenteDeMultasTest extends TestCase
{
    use BuildsSharedBankAccount;

    /** @var list<Process> */
    private array $workers = [];

    private Fine $multa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('sacst_test', DB::connection()->getDatabaseName());
        $this->assertSame(0, DB::transactionLevel());
        RefreshDatabaseState::$migrated = false;
        $this->artisan('migrate:fresh', ['--seed' => true, '--seeder' => CatalogosSeeder::class])->assertExitCode(0);

        $this->abrirMultas($this->cuenta(), '2026-06-01');
        $this->multa = app(RegisterFine::class)->handle([
            'caseNumber' => '169607/24-0',
            'resolution' => ['text' => 'N°0649/26', 'issuedOn' => '2026-05-07'],
            'acts' => [['actType' => 'HYS', 'text' => 'N°1089657', 'issuedOn' => null]],
            'caption' => 'CRISTOMAX S.A.S',
            'registeredOn' => '2026-05-07',
            'amount' => '1400000.00',
        ], $this->quienAbre());
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

    /** Mientras se cobra la multa, el recurso espera y después la encuentra con un pago. */
    public function test_appealing_while_the_fine_is_being_collected_waits_and_is_refused(): void
    {
        DB::beginTransaction();
        $this->cobrar();

        $worker = $this->esperando(['mode' => 'appeal', 'fine' => $this->multa->id, 'amount' => '280000.00', 'actor' => $this->quienAbre()]);
        DB::commit();
        $resultado = $this->resultado($worker);

        expect($resultado['status'])->toBe('validation')
            ->and($resultado['errors'])->toHaveKey('kind')
            ->and(DB::table('fine_appeals')->count())->toBe(0);
    }

    /** Sin pasar por el Action, la base hace lo mismo: el trámite no se abre sobre una multa con pagos. */
    public function test_the_database_refuses_an_appeal_opened_while_the_fine_is_being_collected(): void
    {
        DB::beginTransaction();
        $this->cobrar();

        $worker = $this->esperando(['mode' => 'raw-appeal', 'fine' => $this->multa->id]);
        DB::commit();
        $resultado = $this->resultado($worker);

        expect($resultado['status'])->toBe('database')
            ->and($resultado['message'])->toContain('el recurso se registra antes de cualquier pago')
            ->and(DB::table('fine_appeals')->count())->toBe(0);
    }

    /** Mientras se registra el recurso, el cobro espera y después encuentra la multa congelada. */
    public function test_collecting_while_the_appeal_is_being_registered_waits_and_is_refused(): void
    {
        DB::beginTransaction();
        app(RegisterFineAppeal::class)->handle(
            $this->multa, AppealKind::Hierarchical, FinePaymentMedium::DirectDeposit, null, '280000.00', (string) Str::uuid(), $this->quienAbre(),
        );

        $worker = $this->esperando(['mode' => 'collect', 'fine' => $this->multa->id, 'amount' => '1000.00', 'actor' => $this->quienAbre()]);
        DB::commit();
        $resultado = $this->resultado($worker);

        expect($resultado['status'])->toBe('validation')
            ->and($resultado['errors'])->toHaveKey('amount')
            ->and(DB::table('fine_payments')->count())->toBe(1);
    }

    private function cobrar(): void
    {
        app(CollectFinePayment::class)->handle($this->multa, FinePaymentMedium::Cash, '1000.00', (string) Str::uuid(), $this->quienAbre());
    }

    /**
     * Lanza el worker y vuelve cuando PostgreSQL lo muestra esperando un bloqueo.
     *
     * @param  array<string, mixed>  $datos
     */
    private function esperando(array $datos): Process
    {
        $tag = 'recurso-'.Str::uuid();
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
