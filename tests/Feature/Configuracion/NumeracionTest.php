<?php

declare(strict_types=1);

namespace Tests\Feature\Configuracion;

use App\Models\User;
use App\Modules\Shared\Actions\ConfigureDocumentNumbering;
use App\Modules\Shared\Actions\TakeNextDocumentNumber;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Models\DocumentSeries;
use Database\Seeders\DocumentSeriesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class NumeracionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function input(): array
    {
        return ['prefix' => '125', 'prefixDigits' => 5, 'numberDigits' => 6, 'separator' => '-',
            'nextNumber' => 123, 'reason' => 'Configuración inicial del área.',
            'token' => DocumentSeries::activeForCode('0010')->configurationToken()];
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('administrador');
    }

    public function test_el_administrador_configura_y_la_emision_usa_el_formato(): void
    {
        $this->withoutVite();
        $user = $this->admin();
        $this->actingAs($user)->get('/configuracion/numeracion')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('configuracion/numeracion')->has('series', 3));
        $this->put('/configuracion/numeracion/receipt_income', $this->input())->assertSessionHasNoErrors()->assertRedirect();
        $number = DB::transaction(fn () => app(TakeNextDocumentNumber::class)->handle('0010'));
        $this->assertSame('00125-000123', $number['formatted']);
        $this->assertSame(3, DocumentSeries::query()->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'numeracion.configurada', 'user_id' => $user->id]);
    }

    public function test_sin_permiso_no_se_puede_leer_ni_configurar(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('consulta'));
        $this->get('/configuracion/numeracion')->assertForbidden();
        $this->put('/configuracion/numeracion/receipt_income', $this->input())->assertForbidden();
    }

    public function test_no_admite_prefijo_de_otro_tipo(): void
    {
        $this->actingAs($this->admin())->put('/configuracion/numeracion/receipt_income', [
            ...$this->input(), 'prefix' => '0020', 'prefixDigits' => 4,
        ])->assertSessionHasErrors('prefix');
    }

    public function test_valida_campos_y_exige_motivo(): void
    {
        $this->actingAs($this->admin())->put('/configuracion/numeracion/receipt_income', [
            ...$this->input(), 'prefix' => '../', 'prefixDigits' => 0, 'numberDigits' => 13,
            'nextNumber' => 0, 'separator' => '<', 'reason' => '',
        ])->assertSessionHasErrors(['prefix', 'prefixDigits', 'numberDigits', 'nextNumber', 'separator', 'reason']);
    }

    public function test_no_trunca_el_prefijo(): void
    {
        $this->actingAs($this->admin())->put('/configuracion/numeracion/receipt_income', [
            ...$this->input(), 'prefixDigits' => 2,
        ])->assertSessionHasErrors('prefixDigits');
    }

    public function test_emitir_invalida_un_formulario_abierto(): void
    {
        $input = $this->input();
        DB::transaction(fn () => app(TakeNextDocumentNumber::class)->handle('0010'));
        $this->actingAs($this->admin())->put('/configuracion/numeracion/receipt_income', $input)->assertSessionHasErrors('token');
    }

    public function test_otro_cambio_invalida_un_formulario_abierto(): void
    {
        $input = $this->input();
        $actor = $this->admin();
        app(ConfigureDocumentNumbering::class)->handle(DocumentType::ReceiptIncome, $input, $actor->id);
        $this->expectException(ValidationException::class);
        app(ConfigureDocumentNumbering::class)->handle(DocumentType::ReceiptIncome, $input, $actor->id);
    }

    public function test_cada_tipo_tiene_su_configuracion_independiente(): void
    {
        $actor = $this->admin();
        foreach (DocumentType::cases() as $index => $type) {
            $series = DocumentSeries::activeForCode($type->seriesCode());
            app(ConfigureDocumentNumbering::class)->handle($type, [
                ...$this->input(), 'prefix' => (string) (100 + $index), 'token' => $series->configurationToken(),
            ], $actor->id);
            $number = DB::transaction(fn () => app(TakeNextDocumentNumber::class)->handle($type->seriesCode()));
            $this->assertSame('00'.(100 + $index).'-000123', $number['formatted']);
        }
    }

    public function test_resembrar_no_restaura_la_configuracion_de_fabrica(): void
    {
        app(ConfigureDocumentNumbering::class)->handle(DocumentType::ReceiptIncome, $this->input(), $this->admin()->id);
        $this->seed(DocumentSeriesSeeder::class);
        $this->assertSame('00125-000123', DB::transaction(fn () => app(TakeNextDocumentNumber::class)->handle('0010'))['formatted']);
    }
}
