<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use App\Modules\Ledger\Actions\RegisterOpeningBalance;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Support\CashBalance;
use App\Modules\Shared\Models\CashBox;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * La pantalla del saldo del sistema anterior: de consulta, sin pagos.
 *
 * Mostraba también el pago suelto de un caso viejo —sin expediente, haber
 * ni cuota—, que se retiró: un caso viejo se paga cargando su expediente y
 * reservando la plata para la cuota. Lo reservado y el saldo por lugar los
 * cubre `FondosAnterioresTest`.
 */
class SaldoAnteriorTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_pantalla_muestra_cuanto_queda_y_donde_esta(): void
    {
        $this->abrirLibros('1000000.00');

        $this->actingAs($this->operador('administrativo'))
            ->get('/caja/saldo-anterior')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('caja/saldo-anterior')
                ->where('balances.pending', '1000000.00')
                ->where('legacyByPlace.cash', '1000000.00')
                ->where('setAside', [])
                ->missing('payments')
                ->missing('bankAccounts'));
    }

    /** La dirección de cuando la pantalla también pagaba lleva a la nueva. */
    public function test_la_direccion_vieja_lleva_a_la_pantalla_de_consulta(): void
    {
        $this->actingAs($this->operador('contador'))
            ->get('/caja/pagos-anteriores')
            ->assertRedirect('/caja/saldo-anterior');
    }

    /** Ya no hay por dónde registrar un pago suelto. */
    public function test_no_se_puede_registrar_un_pago_suelto(): void
    {
        $this->abrirLibros('1000000.00');

        $this->actingAs($this->operador('contador'))
            ->post('/caja/pagos-anteriores', [
                'amount' => '300000.00',
                'legacyReference' => '131010/2023',
            ])
            ->assertRedirect('/caja/saldo-anterior');

        $this->assertSame('1000000.00', app(CashBalance::class)->of(LedgerAccount::LegacyFunds, $this->caja()));
    }

    /** Y la base no tiene el tipo de evento con que se asentaba. */
    public function test_la_base_rechaza_un_pago_suelto(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('financial_events_type_check');

        DB::table('financial_events')->insert([
            'public_id' => (string) Str::ulid(),
            'cash_box_id' => $this->caja(),
            'event_type' => 'legacy_disbursement',
            'event_date' => '2026-06-02',
            'status' => 'posted',
            'posted_at' => now(),
            'idempotency_key' => 'test-pago-suelto',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function caja(): int
    {
        return (int) CashBox::query()->where('code', CashBox::HABERES)->value('id');
    }

    private function abrirLibros(string $efectivo): void
    {
        app(RegisterOpeningBalance::class)->handle(
            actorId: $this->quienAbre(),
            cashBoxId: $this->caja(),
            balances: [LedgerAccount::CashOnHand->value => $efectivo],
            denominations: $this->billetesPara($efectivo),
            date: CarbonImmutable::parse('2026-06-01'),
        );
    }
}
