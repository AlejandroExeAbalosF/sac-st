<?php

declare(strict_types=1);

namespace Tests\Feature\Aranceles;

use App\Models\User;
use App\Modules\Aranceles\Actions\CollectFeeInCash;
use App\Modules\Aranceles\Actions\OpenFeeCashBook;
use App\Modules\Aranceles\Models\FeeType;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogosSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\BuildsSharedBankAccount;
use Tests\TestCase;

/**
 * El informe del mes lee una sola foto aunque otro cobre mientras se arma: el resumen
 * por tipo y el detalle de la recaudación siguen coincidiendo.
 *
 * Sin `RefreshDatabase`: el cobro entra por otra conexión, que solo ve lo confirmado.
 */
#[Group('serial')]
class MovimientosConcurrentesDeArancelesTest extends TestCase
{
    use BuildsSharedBankAccount;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertSame('sacst_test', DB::connection()->getDatabaseName());
        $this->assertSame(0, DB::transactionLevel());
        RefreshDatabaseState::$migrated = false;
        $this->artisan('migrate:fresh', ['--seed' => true, '--seeder' => CatalogosSeeder::class])->assertExitCode(0);

        config(['database.connections.pgsql_concurrente' => config('database.connections.pgsql')]);

        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00:00', 'America/Argentina/Salta'));
        $this->admin = $this->operador('administrador');

        app(OpenFeeCashBook::class)->handle(
            date: BusinessDate::today()->subDays(10),
            bankAccountId: $this->cuenta()->id,
            balances: [],
            denominations: [],
            notes: null,
            declaredEmpty: true,
            actorId: $this->admin->id,
        );

        $this->cobrar();
    }

    protected function tearDown(): void
    {
        try {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            DB::purge('pgsql_concurrente');
            RefreshDatabaseState::$migrated = false;
        } finally {
            parent::tearDown();
        }
    }

    protected function quienAbre(): int
    {
        return $this->admin->id;
    }

    private function cobrar(): void
    {
        app(CollectFeeInCash::class)->handle(
            'AG Max SRL', null, [['feeTypeId' => FeeType::query()->where('code', 14)->value('id'), 'quantity' => 1]],
            (string) Str::uuid(), $this->admin->id, null, null, null, true,
        );
    }

    /** Apenas se lee el resumen por tipo, otro cobra y confirma por su propia conexión. */
    private function cobrarDuranteLaLectura(): void
    {
        $cobrado = false;

        DB::listen(function (QueryExecuted $consulta) use (&$cobrado): void {
            if ($cobrado || ! str_contains($consulta->sql, 'fee_charge_items')) {
                return;
            }

            $cobrado = true;
            $principal = DB::getDefaultConnection();
            DB::setDefaultConnection('pgsql_concurrente');

            try {
                $this->cobrar();
            } finally {
                DB::setDefaultConnection($principal);
            }
        });
    }

    public function test_the_screen_reads_every_section_from_the_same_moment(): void
    {
        $this->cobrarDuranteLaLectura();

        $this->actingAs($this->admin)->get(route('aranceles.movimientos.index', ['mes' => '2026-09']))
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $props = $page->toArray()['props'];
                $secciones = collect($props['sections'])->keyBy('key');

                expect($props['byType'][0]['total'])->toBe('120.00')
                    ->and($secciones['recaudacion']['total'])->toBe('120.00')
                    ->and($secciones['recaudacion']['count'])->toBe(1);
            });

        // Terminada la lectura, el cobro está y se ve.
        expect(DB::table('fee_charges')->count())->toBe(2)
            ->and(DB::transactionLevel())->toBe(0);
    }

    public function test_the_export_reads_every_section_from_the_same_moment(): void
    {
        $this->cobrarDuranteLaLectura();

        $contenido = $this->actingAs($this->admin)
            ->get(route('aranceles.movimientos.exportar', ['mes' => '2026-09']))
            ->assertOk()
            ->streamedContent();
        $archivo = tempnam(sys_get_temp_dir(), 'mov').'.xlsx';
        file_put_contents($archivo, $contenido);
        $filas = IOFactory::load($archivo)->getActiveSheet()->toArray(null, false, false);
        @unlink($archivo);

        $total = fn (string $seccion, string $rotulo): mixed => collect($filas)->first(fn (array $f): bool => $f[0] === $seccion && $f[1] === $rotulo)[7] ?? null;

        expect($total('Recaudación por tipo', 'Total'))->toBe('120,00')
            ->and($total('Recaudación', 'Total de la sección'))->toBe('120,00')
            ->and(DB::transactionLevel())->toBe(0);
    }
}
