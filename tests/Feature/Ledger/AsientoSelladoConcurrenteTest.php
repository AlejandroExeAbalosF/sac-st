<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Shared\Models\CashBox;
use App\Support\BusinessDate;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Cargar líneas y postear el mismo asiento a la vez, en dos procesos de verdad.
 *
 * `journal_lines_sealed` lee el evento con `FOR SHARE`. La FK solo toma
 * `FOR KEY SHARE`, que no choca con el `UPDATE` de `status`: sin ese bloqueo,
 * un insert que empezó con el asiento en borrador entraba aunque otro lo
 * hubiera posteado en el medio, y sumaba un par balanceado a un asiento
 * sellado.
 *
 * Sin `RefreshDatabase`, como `EgresoConcurrenteTest`: el segundo proceso
 * necesita ver los datos confirmados.
 */
#[Group('serial')]
class AsientoSelladoConcurrenteTest extends TestCase
{
    /** @var list<Process> */
    private array $workers = [];

    private int $caja;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('sacst_test', DB::connection()->getDatabaseName());
        $this->assertSame(0, DB::transactionLevel());
        RefreshDatabaseState::$migrated = false;
        $this->artisan('migrate:fresh', ['--seed' => true, '--seeder' => CatalogosSeeder::class])->assertExitCode(0);

        $this->abrirLibrosSinSaldo(Currency::Ars);
        $this->caja = (int) CashBox::query()->where('code', CashBox::HABERES)->value('id');
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

    /** Mientras se postea, las líneas nuevas esperan y encuentran el asiento sellado. */
    public function test_lines_while_posting_wait_and_are_refused(): void
    {
        $evento = $this->borradorConAsiento();

        DB::beginTransaction();
        DB::table('financial_events')->where('id', $evento)->update(['status' => 'posted', 'posted_at' => now()]);

        $worker = $this->esperando(['mode' => 'raw-journal-lines', 'event' => $evento, 'box' => $this->caja, 'amount' => '5.00']);
        DB::commit();
        $resultado = $this->resultado($worker);

        expect($resultado['status'])->toBe('database')
            ->and($resultado['message'])->toContain('no admite líneas nuevas')
            ->and(DB::table('journal_lines')->where('financial_event_id', $evento)->count())->toBe(2);
    }

    /** Mientras se cargan líneas, postear espera y después sella el asiento completo. */
    public function test_posting_while_adding_lines_waits_and_includes_them(): void
    {
        $evento = $this->borradorConAsiento();

        DB::beginTransaction();
        $this->linea($evento, 'CASH_ON_HAND', debit: '5.00');

        $worker = $this->esperando(['mode' => 'raw-post-event', 'event' => $evento]);
        $this->linea($evento, 'UNASSIGNED_FUNDS', credit: '5.00');
        DB::commit();
        $resultado = $this->resultado($worker);

        expect($resultado['status'])->toBe('done')
            ->and(DB::table('financial_events')->where('id', $evento)->value('status'))->toBe('posted')
            ->and(DB::table('journal_lines')->where('financial_event_id', $evento)->count())->toBe(4);
    }

    /** Un borrador confirmado, con su asiento balanceado, listo para postear. */
    private function borradorConAsiento(): int
    {
        return DB::transaction(function (): int {
            $evento = (int) DB::table('financial_events')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'cash_box_id' => $this->caja,
                'event_type' => FinancialEventType::FundsReceived->value,
                'event_date' => BusinessDate::today()->toDateString(),
                'status' => 'draft',
                'idempotency_key' => 'sellado-'.Str::random(10),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->linea($evento, 'CASH_ON_HAND', debit: '10.00');
            $this->linea($evento, 'UNASSIGNED_FUNDS', credit: '10.00');

            return $evento;
        });
    }

    private function linea(int $evento, string $cuenta, string $debit = '0.00', string $credit = '0.00'): void
    {
        DB::table('journal_lines')->insert([
            'financial_event_id' => $evento,
            'account_code' => $cuenta,
            'currency' => 'ARS',
            'debit' => $debit,
            'credit' => $credit,
            'cash_box_id' => $this->caja,
            'created_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $datos */
    private function esperando(array $datos): Process
    {
        $tag = 'asiento-'.Str::uuid();
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
