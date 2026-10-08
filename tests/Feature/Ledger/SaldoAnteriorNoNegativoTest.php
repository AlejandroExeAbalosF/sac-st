<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Actions\RegisterOpeningBalance;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Support\CashBalance;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Shared\Models\CashBox;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CollectsInstallments;
use Tests\TestCase;

/**
 * Del sistema anterior no se reserva más de lo que se declaró al abrir los
 * libros, lo intente quien lo intente.
 *
 * Reservar para una cuota histórica es la única forma en que baja ese
 * saldo. `SetAsideLegacyFunds` da el mensaje legible —lo cubre
 * `FondosAnterioresTest`—; acá se prueba la base, escribiendo el asiento sin
 * pasar por ese Action. El trigger es diferido y `RefreshDatabase` nunca
 * confirma: cada test pasa a `IMMEDIATE`, que dispara en ese momento lo que
 * estaba pendiente.
 */
class SaldoAnteriorNoNegativoTest extends TestCase
{
    use CollectsInstallments;
    use RefreshDatabase;

    private ?BeneficiaryInstallment $cuotaHistorica = null;

    public function test_la_base_no_deja_reservar_mas_de_lo_que_queda_del_sistema_anterior(): void
    {
        $this->abrirLibros('1000.00');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Del sistema anterior no queda tanto por pagar');

        $this->reservarSinElAction('1500.00');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_reservar_exactamente_lo_que_queda_lo_deja_en_cero(): void
    {
        $this->abrirLibros('1000.00');

        $this->reservarSinElAction('1000.00');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $this->assertSame('0.00', app(CashBalance::class)->of(LedgerAccount::LegacyFunds, $this->caja()));
    }

    /** La suma es del saldo acumulado, no de cada reserva por separado. */
    public function test_dos_reservas_que_juntas_superan_el_saldo_se_rechazan(): void
    {
        $this->abrirLibros('1000.00');

        $this->reservarSinElAction('600.00', 'a');
        $this->reservarSinElAction('600.00', 'b');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Del sistema anterior no queda tanto por pagar');

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    /**
     * Un borrador no consume saldo, pero postearlo sí: el control se
     * repite cuando el evento deja de ser borrador.
     */
    public function test_postear_un_borrador_que_deja_el_saldo_negativo_se_rechaza(): void
    {
        $this->abrirLibros('1000.00');

        $evento = (int) DB::table('financial_events')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'cash_box_id' => $this->caja(),
            'event_type' => FinancialEventType::LegacyFundsAllocated->value,
            'event_date' => '2026-06-02',
            'status' => 'draft',
            'idempotency_key' => 'test-borrador-anterior',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('journal_lines')->insert($this->linea($evento, LedgerAccount::LegacyFunds, debit: '1500.00'));
        DB::table('journal_lines')->insert($this->lineaDeCuota($evento, '1500.00'));
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Del sistema anterior no queda tanto por pagar');

        DB::table('financial_events')->where('id', $evento)->update([
            'status' => 'posted',
            'posted_at' => now(),
        ]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    /**
     * Dos consumos simultáneos se serializan.
     *
     * La guarda toma un bloqueo consultivo por caja y moneda antes de sumar,
     * y lo retiene hasta que la transacción termina: un segundo consumo,
     * desde otra conexión, no puede sumar hasta que el primero confirme, y
     * para entonces ve su débito.
     *
     * La mitad final —que el segundo, ya desbloqueado, vea el débito del
     * primero— no se puede reproducir acá: `RefreshDatabase` nunca confirma,
     * y confirmar dejaría líneas append-only que ningún test puede borrar.
     * Lo que se verifica es lo que la sostiene: que el bloqueo lo toma la
     * propia base, escribiendo el asiento sin ningún Action de por medio.
     */
    public function test_la_guarda_retiene_el_bloqueo_hasta_que_la_transaccion_termina(): void
    {
        $this->abrirLibros('1000.00');

        config(['database.connections.pgsql_concurrente' => config('database.connections.pgsql')]);
        $otra = DB::connection('pgsql_concurrente');

        try {
            $this->assertTrue($this->otraConexionPuedeConsumir($otra), 'Antes del consumo el bloqueo tiene que estar libre.');

            $evento = (int) DB::table('financial_events')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'cash_box_id' => $this->caja(),
                'event_type' => FinancialEventType::LegacyFundsAllocated->value,
                'event_date' => '2026-06-02',
                'status' => 'posted',
                'posted_at' => now(),
                'idempotency_key' => 'test-concurrencia-anterior',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('journal_lines')->insert($this->linea($evento, LedgerAccount::LegacyFunds, debit: '300.00'));
            DB::table('journal_lines')->insert($this->lineaDeCuota($evento, '300.00'));
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

            $this->assertFalse($this->otraConexionPuedeConsumir($otra), 'La guarda tiene que retener el bloqueo hasta confirmar.');
        } finally {
            DB::purge('pgsql_concurrente');
        }
    }

    /** Si otra conexión podría tomar ahora el bloqueo de los consumos de esta caja. */
    private function otraConexionPuedeConsumir(Connection $otra): bool
    {
        $fila = $otra->selectOne(
            'SELECT pg_try_advisory_xact_lock(hashtext(?), ?) AS libre',
            ['sacst.legacy_funds.ARS', $this->caja()],
        );

        return (bool) $fila->libre;
    }

    /** Asienta una reserva del sistema anterior sin los controles de `SetAsideLegacyFunds`. */
    private function reservarSinElAction(string $importe, string $clave = 'unico'): void
    {
        $cuota = $this->cuotaHistorica();

        app(PostJournalEntry::class)->handle(
            type: FinancialEventType::LegacyFundsAllocated,
            idempotencyKey: "test-reserva-anterior-{$clave}",
            lines: [
                EntryLine::debit(LedgerAccount::LegacyFunds, $importe)->onCashBox($this->caja()),
                EntryLine::credit(LedgerAccount::BeneficiaryFunds, $importe)
                    ->forInstallment($cuota->haber_id, $cuota->id)
                    ->onCashBox($this->caja()),
            ],
            date: CarbonImmutable::parse('2026-06-02'),
            cashBoxId: $this->caja(),
        );
    }

    /** @return array<string, mixed> */
    private function linea(int $evento, LedgerAccount $cuenta, string $debit = '0', string $credit = '0'): array
    {
        return [
            'financial_event_id' => $evento,
            'account_code' => $cuenta->value,
            'debit' => $debit,
            'credit' => $credit,
            'cash_box_id' => $this->caja(),
            'currency' => 'ARS',
        ];
    }

    /** @return array<string, mixed> */
    private function lineaDeCuota(int $evento, string $importe): array
    {
        $cuota = $this->cuotaHistorica();

        return [
            ...$this->linea($evento, LedgerAccount::BeneficiaryFunds, credit: $importe),
            'haber_id' => $cuota->haber_id,
            'beneficiary_installment_id' => $cuota->id,
        ];
    }

    /** La cuota para la que se reserva: el saldo es lo que se prueba, no ella. */
    private function cuotaHistorica(): BeneficiaryInstallment
    {
        return $this->cuotaHistorica ??= $this->cuota('900/2024', '1500.00');
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
