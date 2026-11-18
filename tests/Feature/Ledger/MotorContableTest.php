<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use App\Models\User;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventStatus;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Models\FinancialEvent;
use App\Modules\Ledger\Models\JournalLine;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Shared\Models\CashBox;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
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
 * defensa existe de verdad. Lo hacen por el único camino que la base
 * admite: el evento nace en borrador, recibe sus líneas y después se postea.
 */
class MotorContableTest extends TestCase
{
    use RefreshDatabase;

    private int $caja;

    protected function setUp(): void
    {
        parent::setUp();

        $this->abrirLibrosSinSaldo(Currency::Ars);
        $this->caja = (int) CashBox::query()->where('code', CashBox::HABERES)->value('id');
    }

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

                $this->lineaCruda($evento, LedgerAccount::CashOnHand, debit: '72000.00');
                $this->lineaCruda($evento, LedgerAccount::UnassignedFunds, credit: '50000.00');
                $this->postear($evento);

                // Las dos líneas entraron con el asiento desbalanceado: es
                // lo que un trigger diferido tiene que permitir.
                $lineasInsertadas = true;

                $this->confirmar();
            });

            $this->fail('La base aceptó un asiento que no balancea.');
        } catch (QueryException $e) {
            $this->assertTrue($lineasInsertadas, 'El trigger actuó antes de tiempo: no está diferido.');
            $this->assertStringContainsString('no balancea', $e->getMessage());
        }

        $this->assertFalse(FinancialEvent::query()->where('idempotency_key', 'no-cierra')->exists());
    }

    public function test_un_asiento_que_cierra_entra(): void
    {
        $evento = DB::transaction(function (): int {
            $evento = $this->eventoCrudo('cierra');

            $this->lineaCruda($evento, LedgerAccount::CashOnHand, debit: '72000.00');
            $this->lineaCruda($evento, LedgerAccount::UnassignedFunds, credit: '72000.00');
            $this->postear($evento);

            $this->confirmar();

            return $evento;
        });

        $this->assertSame(FinancialEventStatus::Posted, FinancialEvent::query()->findOrFail($evento)->status);
        $this->assertSame(2, JournalLine::query()->where('financial_event_id', $evento)->count());
    }

    /** Un evento asentado sin asiento sería plata que no está en ningún lado. */
    public function test_no_se_puede_asentar_un_evento_sin_lineas(): void
    {
        $this->expectException(QueryException::class);

        DB::transaction(function (): void {
            $this->postear($this->eventoCrudo('sin-lineas'));

            $this->confirmar();
        });
    }

    /** Un borrador puede estar a medio armar: para eso sirve. */
    public function test_un_borrador_sin_lineas_es_valido(): void
    {
        DB::transaction(function (): void {
            $this->eventoCrudo('borrador');

            $this->confirmar();
        });

        $this->assertTrue(FinancialEvent::query()->where('idempotency_key', 'borrador')->exists());
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
        $evento = $this->eventoDe($this->asientoCompleto());

        $this->rechaza(fn () => $this->eventos($evento)->update(['status' => 'draft', 'posted_at' => null]), 'pasa a');
    }

    public function test_la_fecha_de_un_hecho_monetario_no_se_edita(): void
    {
        $evento = $this->eventoDe($this->asientoCompleto());

        $this->expectException(QueryException::class);

        $this->eventos($evento)->update(['event_date' => '2020-01-01']);
    }

    /** Una línea de ceros no dice nada; una con los dos lados, nadie la sabría leer. */
    public function test_una_linea_no_puede_tener_debito_y_credito_a_la_vez(): void
    {
        $this->expectException(QueryException::class);

        DB::transaction(function (): void {
            $evento = $this->eventoCrudo('dos-lados');
            $this->lineaCruda($evento, LedgerAccount::CashOnHand, debit: '10.00', credit: '10.00');
        });
    }

    /*
    |--------------------------------------------------------------------------
    | El asiento sellado
    |--------------------------------------------------------------------------
    */

    /**
     * Un par balanceado sobre un asiento ya posteado no rompe el balance, y
     * por eso nada lo frenaba: cambiaba un saldo sin dejar reversión.
     */
    public function test_un_asiento_posteado_no_admite_lineas_nuevas(): void
    {
        $evento = $this->eventoDe($this->asientoCompleto());

        $this->rechaza(function () use ($evento): void {
            $this->lineaCruda($evento, LedgerAccount::CashOnHand, debit: '5.00');
            $this->lineaCruda($evento, LedgerAccount::UnassignedFunds, credit: '5.00');
        }, 'no admite líneas nuevas');
    }

    /** Todo evento nace en borrador: uno que nace posteado se saltea el sellado. */
    public function test_un_evento_no_nace_posteado(): void
    {
        $this->rechaza(fn () => DB::table('financial_events')->insert([
            ...$this->filaDeEvento('nace-posteado'),
            'status' => 'posted',
            'posted_at' => now(),
        ]), 'nace en borrador');
    }

    /** Solo hacia adelante: un revertido no vuelve a borrador ni un borrador se revierte. */
    public function test_el_estado_del_evento_solo_avanza(): void
    {
        $evento = $this->eventoDe($this->asientoCompleto());
        $borrador = $this->eventoCrudo('borrador-suelto');

        $this->rechaza(fn () => $this->eventos($borrador)->update(['status' => 'reversed', 'posted_at' => now()]), 'pasa a');

        $this->eventos($evento)->update(['status' => 'reversed']);

        $this->rechaza(fn () => $this->eventos($evento)->update(['status' => 'draft', 'posted_at' => null]), 'pasa a');
        $this->rechaza(fn () => $this->eventos($evento)->update(['status' => 'posted']), 'pasa a');
    }

    /** Posteado, el evento queda como se posteó: quién, cuándo y con qué descripción. */
    public function test_un_evento_posteado_no_se_edita(): void
    {
        $evento = (int) app(PostJournalEntry::class)->handle(
            type: FinancialEventType::FundsReceived,
            idempotencyKey: 'con-autor',
            lines: $this->lineasBalanceadas('10.00'),
            date: now(),
            cashBoxId: $this->caja,
            actorId: $this->quienAbre(),
        )->id;
        $otro = User::factory()->create()->id;

        $this->rechaza(fn () => $this->eventos($evento)->update(['posted_by' => $otro]), 'no se edita');
        $this->rechaza(fn () => $this->eventos($evento)->update(['created_by' => null]), 'no se edita');
        $this->rechaza(fn () => $this->eventos($evento)->update(['posted_at' => now()->subDay()]), 'no se edita');
        $this->rechaza(fn () => $this->eventos($evento)->update(['description' => 'Otra cosa']), 'no se edita');
    }

    /** Borrar al usuario sigue anulando su autoría, con el evento ya posteado. */
    public function test_el_set_null_de_la_fk_alcanza_al_evento_posteado(): void
    {
        $autor = User::factory()->create();
        $evento = app(PostJournalEntry::class)->handle(
            type: FinancialEventType::FundsReceived,
            idempotencyKey: 'con-autor',
            lines: $this->lineasBalanceadas('10.00'),
            date: now(),
            cashBoxId: $this->caja,
            actorId: $autor->id,
        );

        $autor->delete();

        $evento->refresh();
        $this->assertNull($evento->created_by);
        $this->assertNull($evento->posted_by);
    }

    /** La caja es obligatoria: sin ella el evento se saltea la apertura y el cierre. */
    public function test_un_evento_no_existe_sin_caja(): void
    {
        $this->rechaza(fn () => DB::table('financial_events')->insert([
            ...$this->filaDeEvento('sin-caja'),
            'cash_box_id' => null,
        ]), 'cash_box_id');
    }

    /** La línea es de la caja de su evento. */
    public function test_una_linea_no_es_de_otra_caja_que_su_evento(): void
    {
        $otraCaja = (int) CashBox::query()->where('id', '<>', $this->caja)->value('id');
        $evento = $this->eventoCrudo('otra-caja');

        $this->rechaza(fn () => DB::table('journal_lines')->insert([
            ...$this->filaDeLinea($evento, LedgerAccount::CashOnHand, debit: '5.00'),
            'cash_box_id' => $otraCaja,
        ]), 'journal_lines_event_cash_box_foreign');
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
            cashBoxId: $this->caja,
        );

        $segundo = $asentar->handle(
            type: FinancialEventType::FundsReceived,
            idempotencyKey: $clave,
            lines: $this->lineasBalanceadas('72000.00'),
            date: now(),
            cashBoxId: $this->caja,
        );

        $this->assertSame($primero->id, $segundo->id);
        $this->assertSame(1, FinancialEvent::query()->where('idempotency_key', $clave)->count());
        $this->assertSame(2, JournalLine::query()->where('financial_event_id', $primero->id)->count());
    }

    /** El Action arma el borrador, carga las líneas con la caja del evento y lo postea. */
    public function test_el_action_postea_con_las_lineas_en_la_caja_del_evento(): void
    {
        $evento = app(PostJournalEntry::class)->handle(
            type: FinancialEventType::FundsReceived,
            idempotencyKey: 'sellado',
            lines: $this->lineasBalanceadas('10.00'),
            date: now(),
            cashBoxId: $this->caja,
            actorId: $this->quienAbre(),
        );

        $this->confirmar();

        $evento->refresh();
        $this->assertSame(FinancialEventStatus::Posted, $evento->status);
        $this->assertNotNull($evento->posted_at);
        $this->assertSame($this->quienAbre(), $evento->posted_by);
        $this->assertSame(
            [$this->caja],
            JournalLine::query()->where('financial_event_id', $evento->id)->distinct()->pluck('cash_box_id')->all(),
        );
    }

    /** Una línea que nombra otra caja es un error del llamador, no del operador. */
    public function test_el_action_rechaza_una_linea_de_otra_caja(): void
    {
        $otraCaja = (int) CashBox::query()->where('id', '<>', $this->caja)->value('id');

        $this->expectException(LogicException::class);

        app(PostJournalEntry::class)->handle(
            type: FinancialEventType::FundsReceived,
            idempotencyKey: 'otra-caja',
            lines: [
                EntryLine::debit(LedgerAccount::CashOnHand, '10.00')->onCashBox($otraCaja),
                EntryLine::credit(LedgerAccount::UnassignedFunds, '10.00'),
            ],
            date: now(),
            cashBoxId: $this->caja,
        );
    }

    /** El Action explica; la base impide. Los dos hacen falta. */
    public function test_el_action_explica_el_desbalance_antes_de_tocar_la_base(): void
    {
        try {
            app(PostJournalEntry::class)->handle(
                type: FinancialEventType::FundsReceived,
                idempotencyKey: 'desbalanceado',
                lines: [
                    EntryLine::debit(LedgerAccount::CashOnHand, '72000.00'),
                    EntryLine::credit(LedgerAccount::UnassignedFunds, '50000.00'),
                ],
                date: now(),
                cashBoxId: $this->caja,
            );

            $this->fail('El Action aceptó un asiento que no cierra.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('no cierra', $e->getMessage());
        }

        $this->assertFalse(FinancialEvent::query()->where('idempotency_key', 'desbalanceado')->exists());
    }

    public function test_una_linea_no_admite_importes_negativos(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EntryLine::debit(LedgerAccount::CashOnHand, '-100.00');
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

    /** Que la base rechace la escritura, dentro de un punto de guardado, por la guarda que se nombra. */
    private function rechaza(callable $escritura, string $motivo): void
    {
        try {
            DB::transaction(function () use ($escritura): void {
                $escritura();
                $this->confirmar();
            });
        } catch (QueryException $e) {
            $this->assertStringContainsString($motivo, $e->getMessage());

            return;
        }

        $this->fail('La base aceptó la escritura; se esperaba: '.$motivo);
    }

    /** @return list<EntryLine> */
    private function lineasBalanceadas(string $importe): array
    {
        return [
            EntryLine::debit(LedgerAccount::CashOnHand, $importe),
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
            cashBoxId: $this->caja,
        );

        return (int) JournalLine::query()->where('financial_event_id', $evento->id)->value('id');
    }

    private function eventoDe(int $linea): int
    {
        return (int) JournalLine::query()->whereKey($linea)->value('financial_event_id');
    }

    private function eventos(int $evento): Builder
    {
        return DB::table('financial_events')->where('id', $evento);
    }

    /** @return array<string, mixed> */
    private function filaDeEvento(string $clave): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'cash_box_id' => $this->caja,
            'event_type' => FinancialEventType::FundsReceived->value,
            'event_date' => '2026-04-24',
            'status' => 'draft',
            'idempotency_key' => $clave,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** Escritura directa, sin pasar por el Action: es lo que se quiere probar. Nace en borrador. */
    private function eventoCrudo(string $clave): int
    {
        return (int) DB::table('financial_events')->insertGetId($this->filaDeEvento($clave));
    }

    private function postear(int $evento): void
    {
        $this->eventos($evento)->update(['status' => 'posted', 'posted_at' => now()]);
    }

    /** @return array<string, mixed> */
    private function filaDeLinea(int $evento, LedgerAccount $cuenta, string $debit = '0', string $credit = '0'): array
    {
        return [
            'financial_event_id' => $evento,
            'account_code' => $cuenta->value,
            'currency' => Currency::Ars->value,
            'debit' => $debit,
            'credit' => $credit,
            'cash_box_id' => $this->caja,
            'created_at' => now(),
        ];
    }

    private function lineaCruda(
        int $evento,
        LedgerAccount $cuenta,
        string $debit = '0',
        string $credit = '0',
    ): void {
        DB::table('journal_lines')->insert($this->filaDeLinea($evento, $cuenta, $debit, $credit));
    }
}
