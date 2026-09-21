<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\User;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use Database\Seeders\CashBoxSeeder;
use Database\Seeders\DocumentSeriesSeeder;
use Database\Seeders\EscenariosDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class EscenariosDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_solo_puede_ejecutarse_en_ambientes_seguros(): void
    {
        $ambienteOriginal = $this->app->environment();
        $this->app->instance('env', 'production');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('solo puede ejecutarse en local o testing');

            (new EscenariosDemoSeeder)->run();
        } finally {
            $this->app->instance('env', $ambienteOriginal);
        }
    }

    public function test_construye_los_escenarios_y_restaura_las_protecciones(): void
    {
        $this->seed([DocumentSeriesSeeder::class, CashBoxSeeder::class]);
        User::factory()->create();

        $temporalesAntes = glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'sacst*') ?: [];

        $this->seed(EscenariosDemoSeeder::class);

        $temporalesDespues = glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'sacst*') ?: [];

        sort($temporalesAntes);
        sort($temporalesDespues);

        $this->assertSame(3, Expediente::query()->count());
        $this->assertSame(9, BeneficiaryInstallment::query()->count());
        $this->assertSame(9, BankTransaction::query()->count());
        $this->assertSame($temporalesAntes, $temporalesDespues);
        $this->assertSame(
            0,
            DB::table('pg_trigger')
                ->where('tgisinternal', false)
                ->where('tgenabled', 'D')
                ->count(),
        );
    }
}
