<?php

declare(strict_types=1);

namespace Tests\Feature\Aranceles;

use App\Models\User;
use App\Modules\Aranceles\Actions\AddFeeTypePrice;
use App\Modules\Aranceles\Actions\CreateFeeType;
use App\Modules\Aranceles\Actions\UpdateFeeType;
use App\Modules\Aranceles\Actions\VoidFeeTypePrice;
use App\Modules\Aranceles\Enums\FeeCalculationMode;
use App\Modules\Aranceles\Models\FeeType;
use App\Modules\Aranceles\Models\FeeTypePrice;
use App\Modules\Shared\Models\AuditEvent;
use Carbon\CarbonImmutable;
use Database\Seeders\FeeTypeSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * El catálogo de aranceles y sus precios con vigencia (reglas A10 a A14): en la
 * pantalla, en el Action y en la base.
 */
class CatalogoDeArancelesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->operador('administrador');
    }

    private function tipo(int $codigo): FeeType
    {
        return FeeType::query()->where('code', $codigo)->firstOrFail();
    }

    private function dia(string $fecha): CarbonImmutable
    {
        return CarbonImmutable::parse($fecha);
    }

    private function nuevoTipo(int $codigo = 99, FeeCalculationMode $modo = FeeCalculationMode::Unit, string $valor = '100.00'): FeeType
    {
        return app(CreateFeeType::class)->handle(
            ['code' => $codigo, 'decreeLetter' => null, 'name' => 'Arancel de prueba', 'calculationMode' => $modo, 'unitLabel' => null],
            $this->dia('2026-01-01'),
            $valor,
            null,
            $this->admin->id,
        );
    }

    /** @param callable(): mixed $escritura */
    private function assertLaBaseRechaza(callable $escritura, string $mensaje): void
    {
        try {
            DB::transaction(function () use ($escritura): void {
                $escritura();
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
        } catch (QueryException $e) {
            $this->assertStringContainsString($mensaje, $e->getMessage());

            return;
        }

        $this->fail('La base tenía que rechazar la escritura: '.$mensaje);
    }

    // ── El catálogo inicial ───────────────────────────────

    public function test_seeds_the_25_fees_of_the_decree(): void
    {
        $this->assertSame(25, FeeType::query()->count());

        $homologacion = $this->tipo(14);
        $this->assertSame('n', $homologacion->decree_letter);
        $this->assertSame('120.00', $homologacion->priceOn($this->dia('2026-11-04'))?->unit_price);

        $this->assertSame('y', $this->tipo(25)->decree_letter);
        $this->assertSame('50.00', $this->tipo(25)->priceOn($this->dia('2026-11-04'))?->unit_price);
        $this->assertSame('folio', $this->tipo(3)->unit_label);

        $conciliacion = $this->tipo(15);
        $this->assertSame(FeeCalculationMode::Percentage, $conciliacion->calculation_mode);
        $this->assertSame('0.0700', $conciliacion->priceOn($this->dia('2026-11-04'))?->rate);
    }

    public function test_seeding_again_does_not_undo_what_the_area_changed(): void
    {
        app(UpdateFeeType::class)->handle($this->tipo(25), [
            'code' => 25,
            'decreeLetter' => 'y',
            'name' => 'Notificación corregida',
            'unitLabel' => null,
            'isActive' => false,
        ], $this->admin->id);

        $this->seed(FeeTypeSeeder::class);

        $this->assertSame(25, FeeType::query()->count());
        $this->assertSame('Notificación corregida', $this->tipo(25)->name);
        $this->assertFalse($this->tipo(25)->is_active);
    }

    /** Cada publicación corre el seeder: un código renumerado no puede volver a nacer. */
    public function test_seeding_again_does_not_bring_back_a_code_that_was_changed(): void
    {
        app(UpdateFeeType::class)->handle($this->tipo(25), [
            'code' => 30,
            'decreeLetter' => 'y',
            'name' => 'Notificaciones',
            'unitLabel' => null,
            'isActive' => true,
        ], $this->admin->id);

        $this->seed(FeeTypeSeeder::class);

        $this->assertSame(25, FeeType::query()->count());
        $this->assertFalse(FeeType::query()->where('code', 25)->exists());
    }

    // ── La pantalla y sus permisos ────────────────────────

    public function test_whoever_can_see_fees_opens_the_catalog_but_does_not_manage_it(): void
    {
        $this->actingAs($this->operador('consulta'))
            ->get(route('aranceles.tipos.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('aranceles/tipos')
                ->has('feeTypes', 25)
                ->where('canManage', false)
                ->where('nextCode', 26)
                ->where('feeTypes.13.code', 14)
                ->where('feeTypes.13.currentPrice.unitPrice', '120.00')
                ->where('feeTypes.13.currentPrice.status', 'current'));
    }

    public function test_only_the_catalog_permission_changes_it(): void
    {
        $operador = $this->operador('administrativo');

        $this->actingAs($operador)
            ->post(route('aranceles.tipos.store'), [])
            ->assertForbidden();
        $this->actingAs($operador)
            ->patch(route('aranceles.tipos.update', $this->tipo(1)), [])
            ->assertForbidden();
        $this->actingAs($operador)
            ->post(route('aranceles.tipos.precios.store', $this->tipo(1)), [])
            ->assertForbidden();
    }

    public function test_registers_a_type_with_its_first_price(): void
    {
        $this->actingAs($this->admin)
            ->post(route('aranceles.tipos.store'), [
                'code' => 30,
                'decreeLetter' => '',
                'name' => '  Copias certificadas  ',
                'calculationMode' => 'unit',
                'unitLabel' => 'copia',
                'validFrom' => '2026-11-01',
                'value' => '75.50',
                'legalReference' => 'Resolución de prueba',
            ])
            ->assertRedirect(route('aranceles.tipos.index'));

        $tipo = $this->tipo(30);

        $this->assertSame('Copias certificadas', $tipo->name);
        $this->assertNull($tipo->decree_letter);
        $this->assertSame('75.50', $tipo->priceOn($this->dia('2026-11-04'))?->unit_price);
        $this->assertSame($this->admin->id, $tipo->prices()->value('created_by'));
        $this->assertTrue(AuditEvent::query()->where('action', 'arancel.tipo-registrado')->where('subject_id', $tipo->id)->exists());
        $this->assertTrue(AuditEvent::query()->where('action', 'arancel.precio-agregado')->where('subject_id', $tipo->id)->exists());
    }

    public function test_a_percentage_takes_a_rate_with_a_comma(): void
    {
        $this->actingAs($this->admin)
            ->post(route('aranceles.tipos.store'), [
                'code' => 31,
                'name' => 'Tasa de prueba',
                'calculationMode' => 'percentage',
                'validFrom' => '2026-11-01',
                'value' => '0,125',
            ])
            ->assertSessionHasNoErrors();

        $precio = $this->tipo(31)->priceOn($this->dia('2026-11-04'));

        $this->assertSame('0.1250', $precio?->rate);
        $this->assertNull($precio?->unit_price);
    }

    public function test_a_repeated_code_or_letter_is_explained_in_its_field(): void
    {
        $this->actingAs($this->admin)
            ->post(route('aranceles.tipos.store'), [
                'code' => 14,
                'decreeLetter' => 'n',
                'name' => 'Repetido',
                'calculationMode' => 'unit',
                'validFrom' => '2026-11-01',
                'value' => '10',
            ])
            ->assertSessionHasErrors(['code', 'decreeLetter']);
    }

    public function test_a_race_for_the_same_code_reaches_the_field_too(): void
    {
        try {
            $this->nuevoTipo(14);
            $this->fail('Tenía que rechazar el código repetido.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('code', $e->errors());
        }
    }

    public function test_corrects_and_deactivates_a_type_with_its_audit(): void
    {
        $tipo = $this->tipo(5);

        $this->actingAs($this->admin)
            ->patch(route('aranceles.tipos.update', $tipo), [
                'code' => 5,
                'decreeLetter' => 'e',
                'name' => 'Libro de Contaminantes',
                'unitLabel' => '',
                'isActive' => false,
            ])
            ->assertSessionHasNoErrors();

        $tipo->refresh();
        $this->assertSame('Libro de Contaminantes', $tipo->name);
        $this->assertFalse($tipo->is_active);

        $evento = AuditEvent::query()->where('action', 'arancel.tipo-corregido')->where('subject_id', $tipo->id)->firstOrFail();
        $this->assertArrayHasKey('name', (array) $evento->new_values);
        $this->assertArrayHasKey('is_active', (array) $evento->new_values);
    }

    // ── Los precios y su vigencia ─────────────────────────

    public function test_each_date_gets_the_price_in_force_that_day(): void
    {
        $tipo = $this->nuevoTipo();
        $agregar = app(AddFeeTypePrice::class);

        $agregar->handle($tipo, $this->dia('2026-06-01'), '150', null, $this->admin->id);
        $agregar->handle($tipo, $this->dia('2027-01-01'), '200', null, $this->admin->id);

        $this->assertNull($tipo->priceOn($this->dia('2025-12-31')));
        $this->assertSame('100.00', $tipo->priceOn($this->dia('2026-05-31'))?->unit_price);
        $this->assertSame('150.00', $tipo->priceOn($this->dia('2026-06-01'))?->unit_price);
        $this->assertSame('200.00', $tipo->priceOn($this->dia('2027-01-01'))?->unit_price);
    }

    public function test_a_future_price_is_scheduled_and_does_not_replace_todays(): void
    {
        $tipo = $this->tipo(14);
        CarbonImmutable::setTestNow('2026-11-04 12:00:00');

        app(AddFeeTypePrice::class)->handle($tipo, $this->dia('2027-01-01'), '500', null, $this->admin->id);

        $this->actingAs($this->admin)
            ->get(route('aranceles.tipos.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('feeTypes.13.currentPrice.unitPrice', '120.00')
                ->where('feeTypes.13.prices.0.status', 'scheduled')
                ->where('feeTypes.13.prices.1.status', 'current'));

        CarbonImmutable::setTestNow();
    }

    public function test_two_prices_on_the_same_date_are_rejected_in_the_field(): void
    {
        $tipo = $this->nuevoTipo();

        $this->actingAs($this->admin)
            ->post(route('aranceles.tipos.precios.store', $tipo), ['validFrom' => '2026-01-01', 'value' => '5'])
            ->assertSessionHasErrors('validFrom');

        $this->assertLaBaseRechaza(fn () => FeeTypePrice::query()->create([
            'fee_type_id' => $tipo->id,
            'valid_from' => '2026-01-01',
            'unit_price' => '5',
        ]), 'fee_type_prices_type_date_unique');
    }

    public function test_a_zero_price_is_rejected_on_both_sides(): void
    {
        $tipo = $this->nuevoTipo();

        $this->actingAs($this->admin)
            ->post(route('aranceles.tipos.precios.store', $tipo), ['validFrom' => '2026-02-01', 'value' => '0'])
            ->assertSessionHasErrors('value');

        $this->assertLaBaseRechaza(fn () => FeeTypePrice::query()->create([
            'fee_type_id' => $tipo->id,
            'valid_from' => '2026-02-01',
            'unit_price' => '0',
        ]), 'fee_type_prices_unit_price_check');
    }

    public function test_voiding_a_price_takes_it_out_and_frees_its_date(): void
    {
        $tipo = $this->nuevoTipo();
        $mal = app(AddFeeTypePrice::class)->handle($tipo, $this->dia('2026-06-01'), '1500', null, $this->admin->id);

        $this->actingAs($this->admin)
            ->post(route('aranceles.precios.anular', $mal), ['reason' => 'Le sobraba un cero'])
            ->assertSessionHasNoErrors();

        $mal->refresh();
        $this->assertSame('Le sobraba un cero', $mal->void_reason);
        $this->assertSame($this->admin->id, $mal->voided_by);
        $this->assertSame('100.00', $tipo->priceOn($this->dia('2026-07-01'))?->unit_price);
        $this->assertTrue(AuditEvent::query()->where('action', 'arancel.precio-anulado')->where('subject_id', $tipo->id)->exists());

        $bien = app(AddFeeTypePrice::class)->handle($tipo, $this->dia('2026-06-01'), '150', null, $this->admin->id);
        $this->assertSame('150.00', $tipo->priceOn($this->dia('2026-07-01'))?->unit_price);
        $this->assertNotSame($mal->id, $bien->id);
    }

    public function test_voiding_needs_a_reason_and_the_permission(): void
    {
        $tipo = $this->nuevoTipo();
        $precio = app(AddFeeTypePrice::class)->handle($tipo, $this->dia('2026-06-01'), '150', null, $this->admin->id);

        $this->actingAs($this->admin)
            ->post(route('aranceles.precios.anular', $precio), ['reason' => '   '])
            ->assertSessionHasErrors('reason');

        $this->actingAs($this->operador('contador'))
            ->post(route('aranceles.precios.anular', $precio), ['reason' => 'Motivo'])
            ->assertForbidden();

        $this->assertNull($precio->refresh()->voided_at);
    }

    public function test_voiding_twice_does_not_fail_nor_rewrite_the_reason(): void
    {
        $tipo = $this->nuevoTipo();
        $precio = app(AddFeeTypePrice::class)->handle($tipo, $this->dia('2026-06-01'), '150', null, $this->admin->id);
        $anular = app(VoidFeeTypePrice::class);

        $anular->handle($precio, 'Primero', $this->admin->id);
        $anular->handle($precio, 'Segundo', $this->admin->id);

        $this->assertSame('Primero', $precio->refresh()->void_reason);
    }

    public function test_the_only_price_cannot_be_voided_on_either_side(): void
    {
        $tipo = $this->nuevoTipo();
        $unico = $tipo->prices()->firstOrFail();

        try {
            app(VoidFeeTypePrice::class)->handle($unico, 'Motivo', $this->admin->id);
            $this->fail('Tenía que rechazar anular el único precio.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('único precio', $e->errors()['reason'][0]);
        }

        $this->assertLaBaseRechaza(fn () => $unico->forceFill([
            'voided_at' => now(),
            'void_reason' => 'Directo',
        ])->save(), 'único precio');
    }

    // ── Lo que impone la base ─────────────────────────────

    public function test_the_database_rejects_editing_or_deleting_a_price(): void
    {
        $precio = $this->nuevoTipo()->prices()->firstOrFail();

        $this->assertLaBaseRechaza(
            fn () => DB::table('fee_type_prices')->where('id', $precio->id)->update(['unit_price' => '1']),
            'no se edita',
        );
        $this->assertLaBaseRechaza(
            fn () => DB::table('fee_type_prices')->where('id', $precio->id)->delete(),
            'no se borra',
        );
    }

    public function test_the_database_rejects_undoing_a_void(): void
    {
        $tipo = $this->nuevoTipo();
        $precio = app(AddFeeTypePrice::class)->handle($tipo, $this->dia('2026-06-01'), '150', null, $this->admin->id);
        app(VoidFeeTypePrice::class)->handle($precio, 'Motivo', $this->admin->id);

        $this->assertLaBaseRechaza(
            fn () => DB::table('fee_type_prices')->where('id', $precio->id)->update(['void_reason' => 'Otro']),
            'no se edita',
        );
    }

    public function test_the_database_requires_the_value_of_the_mode(): void
    {
        $unitario = $this->nuevoTipo();
        $porcentual = $this->nuevoTipo(98, FeeCalculationMode::Percentage, '0.07');

        $this->assertLaBaseRechaza(fn () => FeeTypePrice::query()->create([
            'fee_type_id' => $unitario->id,
            'valid_from' => '2026-03-01',
            'rate' => '1',
        ]), 'modo de cálculo');
        $this->assertLaBaseRechaza(fn () => FeeTypePrice::query()->create([
            'fee_type_id' => $porcentual->id,
            'valid_from' => '2026-03-01',
            'unit_price' => '1',
        ]), 'modo de cálculo');
        $this->assertLaBaseRechaza(fn () => FeeTypePrice::query()->create([
            'fee_type_id' => $unitario->id,
            'valid_from' => '2026-03-01',
            'unit_price' => '1',
            'rate' => '1',
        ]), 'fee_type_prices_one_value');
    }

    public function test_the_database_freezes_the_mode_of_a_type_with_prices(): void
    {
        $tipo = $this->nuevoTipo();

        $this->assertLaBaseRechaza(
            fn () => DB::table('fee_types')->where('id', $tipo->id)->update(['calculation_mode' => 'percentage', 'unit_label' => null]),
            'modo de cálculo no cambia',
        );
    }

    public function test_the_database_rejects_a_type_without_a_price(): void
    {
        $this->assertLaBaseRechaza(fn () => FeeType::query()->create([
            'code' => 97,
            'name' => 'Sin precio',
            'calculation_mode' => FeeCalculationMode::Unit,
        ]), 'no tiene precio');
    }

    public function test_the_database_rejects_deleting_a_type_with_prices(): void
    {
        $tipo = $this->nuevoTipo();

        $this->assertLaBaseRechaza(fn () => $tipo->delete(), 'fee_type_prices');
    }

    public function test_the_database_keeps_a_unit_label_off_a_percentage(): void
    {
        $tipo = $this->nuevoTipo(98, FeeCalculationMode::Percentage, '0.07');

        $this->assertLaBaseRechaza(
            fn () => DB::table('fee_types')->where('id', $tipo->id)->update(['unit_label' => 'folio']),
            'fee_types_unit_label_only_on_unit',
        );
    }
}
