<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Enums\FinancialEventStatus;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Models\FinancialEvent;
use App\Modules\Ledger\Models\JournalLine;
use App\Modules\Ledger\Support\EntryLine;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * El libro diario.
 *
 * Lo que se prueba acá no es el Action: es que la **base** impide escribir
 * un asiento que no cierra. Un Action se puede saltear —un comando de
 * consola, una corrección a mano, un módulo futuro escrito con prisa—;
 * el trigger, no.
 *
 * Por eso varios de estos tests escriben con `DB::table()` en vez de usar
 * los modelos: es la única forma de comprobar que la última línea de
 * defensa existe de verdad.
 */
class MotorContableTest extends TestCase
{
    use RefreshDatabase;

    /**
     * El test que justifica el diseño entero.
     *
     * Las dos líneas entran sin protestar y la transacción **falla al
     * confirmar**. Eso es lo que distingue un `CONSTRAINT TRIGGER
     * DEFERRABLE` de uno inmediato: si fuera inmediato, la primera línea
     * de todo asiento del sistema sería rechazada por no tener todavía su
     * contrapartida.
     */
    public function test_un_asiento_que_no_cierra_se_rechaza_al_confirmar_la_transaccion(): void
    {
        $lineasInsertadas = false;

        try {
            DB::transaction(function () use (&$lineasInsertadas): void {
                $evento = $this->eventoCrudo('no-cierra');

                $this->lineaCruda($evento, LedgerAccount::BankAccount, debit: '72000.00');
                $this->lineaCruda($evento, LedgerAccount::UnassignedFunds, credit: '50000.00');

                // Las dos líneas entraron con el asiento desbalanceado: es
                // exactamente lo que un trigger diferido tiene que permitir.
                $lineasInsertadas = true;

                $this->confirmar();
            });

            $this->fail('La base aceptó un asiento que no balancea.');
        } catch (QueryException $e) {
            $this->assertTrue($lineasInsertadas, 'El trigger actuó antes de tiempo: no está diferido.');
            $this->assertStringContainsString('no balancea', $e->getMessage());
        }

        $this->assertSame(0, FinancialEvent::query()->count());
        $this->assertSame(0, JournalLine::query()->count());
    }

    public function test_un_asiento_que_cierra_entra(): void
    {
        DB::transaction(function (): void {
            $evento = $this->eventoCrudo('cierra');

            $this->lineaCruda($evento, LedgerAccount::BankAccount, debit: '72000.00');
            $this->lineaCruda($evento, LedgerAccount::UnassignedFunds, credit: '72000.00');

            $this->confirmar();
        });

        $this->assertSame(1, FinancialEvent::query()->count());
        $this->assertSame(2, JournalLine::query()->count());
    }

    /** Un evento asentado sin asiento sería plata que no está en ningún lado. */
    public function test_no_se_puede_asentar_un_evento_sin_lineas(): void
    {
        $this->expectException(QueryException::class);

        DB::transaction(function (): void {
            $this->eventoCrudo('sin-lineas');

            $this->confirmar();
        });
    }

    /** Un borrador puede estar a medio armar: para eso sirve. */
    public function test_un_borrador_sin_lineas_es_valido(): void
    {
        DB::transaction(function (): void {
            $this->eventoCrudo('borrador', FinancialEventStatus::Draft);

            $this->confirmar();
        });

        $this->assertSame(1, FinancialEvent::query()->count());
    }

    public function test_una_linea_del_diario_no_se_edita(): void
    {
        $linea = $this->asientoCompleto();

        $this->expectException(QueryException::class);

        DB::table('journal_lines')->where('id', $linea)->update(['debit' => '1.00']);
    }

    public function test_una_linea_del_diario_no_se_borra(): void
    {
        $linea = $this->asientoCompleto();

        $this->expectException(QueryException::class);

        DB::table('journal_lines')->where('id', $linea)->delete();
    }

    public function test_un_hecho_monetario_no_se_borra(): void
    {
        $this->asientoCompleto();

        $this->expectException(QueryException::class);

        DB::table('financial_events')->delete();
    }

    /** Volver a borrador sería poder retocar un asiento ya asentado. */
    public function test_un_evento_asentado_no_vuelve_a_borrador(): void
    {
        $this->asientoCompleto();
        $evento = FinancialEvent::query()->firstOrFail();

        $this->expectException(QueryException::class);

        DB::table('financial_events')->where('id', $evento->id)->update(['status' => 'draft']);
    }

    public function test_la_fecha_de_un_hecho_monetario_no_se_edita(): void
    {
        $this->asientoCompleto();
        $evento = FinancialEvent::query()->firstOrFail();

        $this->expectException(QueryException::class);

        DB::table('financial_events')->where('id', $evento->id)->update(['event_date' => '2020-01-01']);
    }

    /** Una línea de ceros no dice nada; una con los dos lados, nadie la sabría leer. */
    public function test_una_linea_no_puede_tener_debito_y_credito_a_la_vez(): void
    {
        $this->expectException(QueryException::class);

        DB::transaction(function (): void {
            $evento = $this->eventoCrudo('dos-lados', FinancialEventStatus::Draft);
            $this->lineaCruda($evento, LedgerAccount::BankAccount, debit: '10.00', credit: '10.00');
        });
    }

    /*
    |--------------------------------------------------------------------------
    | El Action
    |--------------------------------------------------------------------------
    */

    /** El segundo clic no vuelve a asentar el mismo hecho. */
    public function test_el_mismo_hecho_no_se_asienta_dos_veces(): void
    {
        $asentar = app(PostJournalEntry::class);
        $clave = 'recepcion:movimiento-7:72000.00';

        $primero = $asentar->handle(
            type: FinancialEventType::FundsReceived,
            idempotencyKey: $clave,
            lines: $this->lineasBalanceadas('72000.00'),
            date: now(),
        );

        $segundo = $asentar->handle(
            type: FinancialEventType::FundsReceived,
            idempotencyKey: $clave,
            lines: $this->lineasBalanceadas('72000.00'),
            date: now(),
        );

        $this->assertSame($primero->id, $segundo->id);
        $this->assertSame(1, FinancialEvent::query()->count());
        $this->assertSame(2, JournalLine::query()->count());
    }

    /** El Action explica; la base impide. Los dos hacen falta. */
    public function test_el_action_explica_el_desbalance_antes_de_tocar_la_base(): void
    {
        try {
            app(PostJournalEntry::class)->handle(
                type: FinancialEventType::FundsReceived,
                idempotencyKey: 'desbalanceado',
                lines: [
                    EntryLine::debit(LedgerAccount::BankAccount, '72000.00'),
                    EntryLine::credit(LedgerAccount::UnassignedFunds, '50000.00'),
                ],
                date: now(),
            );

            $this->fail('El Action aceptó un asiento que no cierra.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('no cierra', $e->getMessage());
        }

        $this->assertSame(0, FinancialEvent::query()->count());
    }

    public function test_una_linea_no_admite_importes_negativos(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EntryLine::debit(LedgerAccount::BankAccount, '-100.00');
    }

    /*
    |--------------------------------------------------------------------------
    | Andamiaje
    |--------------------------------------------------------------------------
    */

    /**
     * Fuerza la comprobación que en producción hace el `COMMIT`.
     *
     * Hace falta por cómo corren los tests, no por cómo corre el sistema.
     * `RefreshDatabase` envuelve cada test en una transacción que **nunca
     * se confirma** —al terminar hace rollback—, y un `CONSTRAINT TRIGGER
     * DEFERRABLE` se evalúa justo en ese confirmado que no llega. Sin
     * esto, los tests del balance pasarían siempre, incluso con el trigger
     * borrado.
     *
     * `SET CONSTRAINTS ALL IMMEDIATE` evalúa en el acto todo lo diferido
     * que está pendiente. Es el mismo momento y la misma comprobación que
     * el `COMMIT` real, adelantada una línea.
     */
    private function confirmar(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    /** @return list<EntryLine> */
    private function lineasBalanceadas(string $importe): array
    {
        return [
            EntryLine::debit(LedgerAccount::BankAccount, $importe),
            EntryLine::credit(LedgerAccount::UnassignedFunds, $importe),
        ];
    }

    /** Deja un asiento completo y devuelve el id de una de sus líneas. */
    private function asientoCompleto(): int
    {
        $evento = app(PostJournalEntry::class)->handle(
            type: FinancialEventType::FundsReceived,
            idempotencyKey: 'asiento-'.Str::random(8),
            lines: $this->lineasBalanceadas('72000.00'),
            date: now(),
        );

        return (int) JournalLine::query()->where('financial_event_id', $evento->id)->value('id');
    }

    /** Escritura directa, sin pasar por el Action: es lo que se quiere probar. */
    private function eventoCrudo(
        string $clave,
        FinancialEventStatus $estado = FinancialEventStatus::Posted,
    ): int {
        return (int) DB::table('financial_events')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'event_type' => FinancialEventType::FundsReceived->value,
            'event_date' => '2026-04-24',
            'status' => $estado->value,
            'idempotency_key' => $clave,
            'posted_at' => $estado === FinancialEventStatus::Posted ? now() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function lineaCruda(
        int $evento,
        LedgerAccount $cuenta,
        string $debit = '0',
        string $credit = '0',
    ): void {
        DB::table('journal_lines')->insert([
            'financial_event_id' => $evento,
            'account_code' => $cuenta->value,
            'debit' => $debit,
            'credit' => $credit,
            'created_at' => now(),
        ]);
    }
}
