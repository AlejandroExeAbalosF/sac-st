<?php

declare(strict_types=1);

namespace Tests\Feature\Configuracion;

use App\Modules\Shared\Actions\ConfigureDocumentNumbering;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Models\DocumentSeries;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\NumberingFixture;
use Tests\TestCase;

/** Sin RefreshDatabase: el segundo proceso necesita ver los datos confirmados. */
class NumeracionConcurrenteTest extends TestCase
{
    private NumberingFixture $fixture;

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
        $this->abrirLibrosSinSaldo();
        $this->fixture = new NumberingFixture($this->quienAbre());
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

    /** @return iterable<string, array{DocumentType, string}> */
    public static function races(): iterable
    {
        foreach (DocumentType::cases() as $type) {
            foreach (['configure-emit', 'emit-configure', 'configure-configure', 'emit-emit', 'configure-rollback'] as $race) {
                yield $type->value.'-'.$race => [$type, $race];
            }
        }
    }

    #[DataProvider('races')]
    public function test_dos_conexiones_serializan_emision_y_configuracion(DocumentType $type, string $race): void
    {
        $original = $this->fixture->emit($type, $this->fixture->prepare($type));
        $first = $this->fixture->prepare($type);
        $second = $this->fixture->prepare($type);
        $workerActor = $this->operador('administrador')->id;
        $settings = [
            'prefix' => '0900', 'prefixDigits' => 4, 'numberDigits' => 5, 'separator' => '-', 'nextNumber' => 80,
            'reason' => 'Configuración concurrente.', 'token' => DocumentSeries::activeForCode($type->seriesCode())->configurationToken(),
        ];
        DB::beginTransaction();
        if (str_starts_with($race, 'configure-')) {
            $configured = app(ConfigureDocumentNumbering::class)->handle($type->seriesCode(), $settings, $this->fixture->actorId);
        } else {
            $issued = $this->fixture->emit($type, $first);
        }
        $mode = str_ends_with($race, '-configure') || $race === 'configure-rollback' ? 'configure' : 'emit';
        $tag = 'numbering-'.Str::uuid();
        $worker = new Process([PHP_BINARY, base_path('tests/Support/numbering-worker.php')], base_path());
        $worker->setTimeout(30);
        $worker->setInput(json_encode([
            'database' => config('database.connections.pgsql'), 'tag' => $tag, 'type' => $type->value,
            'mode' => $mode, 'settings' => [...$settings, 'prefix' => '0800'],
            'actor' => $workerActor, 'installment' => $second->id,
        ], JSON_THROW_ON_ERROR));
        $this->workers[] = $worker;
        $worker->start();
        $this->assertWorkerWaitsOnDatabaseLock($worker, $tag);
        if ($race === 'configure-rollback') {
            DB::rollBack();
        } else {
            DB::commit();
        }
        $worker->wait();
        $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
        $lines = explode("\n", trim($worker->getOutput()));
        $result = json_decode(end($lines), true, 512, JSON_THROW_ON_ERROR);
        if ($race === 'configure-rollback') {
            $this->assertSame('configured', $result['status'], json_encode($result));
            $this->assertSame('0800', DocumentSeries::activeForCode($type->seriesCode())->display_prefix);
            $this->assertDatabaseMissing('document_series', ['display_prefix' => '0900']);
        } elseif ($mode === 'configure') {
            $this->assertSame('validation', $result['status'], json_encode($result));
            $this->assertArrayHasKey('token', $result['errors']);
            $active = DocumentSeries::activeForCode($type->seriesCode());
            $this->assertSame(str_starts_with($race, 'configure-') ? '0900' : $type->seriesCode(), $active->display_prefix ?? $active->code);
        } elseif ($race === 'configure-emit') {
            $this->assertSame('emitted', $result['status'], json_encode($result));
            $this->assertSame('0900-00080', $result['formatted']);
            $this->assertSame($configured->id, $result['series']);
        } else {
            $this->assertSame('emitted', $result['status'], json_encode($result));
            $this->assertSame(2, $issued->number);
            $this->assertSame(3, $result['number']);
            $this->assertSame($type->seriesCode().'/00000003', $result['formatted']);
        }
        $this->assertSame($type->seriesCode().'/00000001', $original->refresh()->formatted_number);
        $this->assertSame(1, DocumentSeries::query()->where('code', $type->seriesCode())->where('is_active', true)->count());
    }

    private function assertWorkerWaitsOnDatabaseLock(Process $worker, string $tag): void
    {
        $deadline = microtime(true) + 10;
        do {
            DB::select('SELECT pg_stat_clear_snapshot()');
            $waiting = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE application_name = ?', [$tag]);
            if ($waiting?->wait_event_type === 'Lock') {
                $this->assertTrue($worker->isRunning());

                return;
            }
            if (! $worker->isRunning()) {
                $this->fail('El worker terminó sin esperar el bloqueo: '.$worker->getOutput().$worker->getErrorOutput());
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        $this->fail('No se observó la espera real en PostgreSQL: '.$worker->getOutput().$worker->getErrorOutput());
    }
}
