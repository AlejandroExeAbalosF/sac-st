<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Support\ExpedienteNumber;
use App\Support\BusinessDate;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La forma corta con la instancia al final: `125957/2026-1`.
 *
 * Sin ella, otra instancia que no fuera la 0 solo podía cargarse
 * escribiendo el número completo.
 */
class InstanciaDelExpedienteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HaberesDemoSeeder::class);
    }

    public function test_la_forma_corta_acepta_la_instancia_al_final(): void
    {
        $con = ExpedienteNumber::parse('125957/2026-1');
        $sin = ExpedienteNumber::parse('125957/2026');

        expect($con?->canonical)->toBe('0030064-125957/2026-1')
            ->and($con?->instance)->toBe('1')
            ->and($con?->display)->toBe('125957/2026')
            ->and($sin?->canonical)->toBe('0030064-125957/2026-0')
            ->and(ExpedienteNumber::parse('125957/2026-123'))->toBeNull();
    }

    public function test_dos_instancias_del_mismo_numero_son_dos_expedientes(): void
    {
        $this->actingAs($this->operador())
            ->post(route('expedientes.store'), [...$this->datos(), 'number' => '222222/2026'])
            ->assertSessionHasNoErrors();
        $this->post(route('expedientes.store'), [...$this->datos(), 'number' => '222222/2026-1'])
            ->assertSessionHasNoErrors();
        $this->post(route('expedientes.store'), [...$this->datos(), 'number' => '0030064-222222/2026-1'])
            ->assertSessionHasErrors('number');

        expect(Expediente::query()->where('display_number', '222222/2026')->pluck('canonical_number')->sort()->values()->all())
            ->toBe(['0030064-222222/2026-0', '0030064-222222/2026-1']);
    }

    /**
     * @return array<string, mixed>
     */
    private function datos(): array
    {
        return [
            'subject' => 'Acta acuerdo',
            'receivedDate' => BusinessDate::today()->subDay()->toDateString(),
            'employerId' => 101,
        ];
    }
}
