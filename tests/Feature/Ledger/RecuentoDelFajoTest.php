<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use App\Modules\Ledger\Actions\RecordCashCount;
use App\Modules\Ledger\Actions\RegisterOpeningBalance;
use App\Modules\Ledger\Actions\ReviewCashCount;
use App\Modules\Ledger\Enums\CashCountScope;
use App\Modules\Ledger\Enums\CashCountStatus;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Models\CashCount;
use App\Modules\Ledger\Support\CarryRecount;
use App\Modules\Shared\Models\CashBox;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CollectsInstallments;
use Tests\TestCase;

/**
 * Abrir el fajo de días anteriores es un hecho, y se registra como tal.
 *
 * La rutina de todas las tardes es contar lo que entró hoy y **declarar** el
 * fajo sin tocarlo. Abrirlo es excepcional: se hace cuando se sospecha un
 * faltante, cuando cambia el responsable o cuando toca una verificación.
 *
 * Antes eso vivía solo en el navegador —sumaba sus billetes a los del día y
 * desaparecía—, así que el arqueo guardado quedaba indistinguible de uno
 * donde el cajero contó todo. Lo que se prueba acá es que las tres cosas que
 * eso se llevaba puestas ahora quedan escritas: que el fajo se abrió, por
 * qué, y qué se encontró adentro.
 */
class RecuentoDelFajoTest extends TestCase
{
    use CollectsInstallments;
    use RefreshDatabase;

    /** El día del arqueo. La apertura es de la víspera. */
    private const DIA = '2026-06-02';

    /**
     * El caso completo: el fajo se abre, se cuenta y está entero.
     *
     * Dos millones vienen de la apertura y medio millón entró hoy. Contando
     * los dos por separado, el arqueo dice tres cosas distintas: cuánto se
     * contó en total, cuánto de eso salió del fondo histórico y con qué
     * billetes estaba armado ese fondo.
     */
    public function test_recontar_el_fajo_deja_el_motivo_los_importes_y_sus_billetes(): void
    {
        $this->escenario();

        $arqueo = $this->arquear(
            delDia: [100_000 => 5],
            recuento: new CarryRecount(
                reason: 'Cambio de responsable del cajón.',
                denominations: [100_000 => 20],
            ),
        );

        // Lo contado es el cajón entero: los billetes del fajo también están ahí.
        $this->assertSame('2500000.00', $arqueo->counted_amount);
        $this->assertSame('0.00', $arqueo->uncounted_amount);
        $this->assertSame('0.00', $arqueo->difference_amount);

        // Y queda dicho qué parte de eso salió del fajo.
        $this->assertTrue($arqueo->recountedTheCarry());
        $this->assertSame('Cambio de responsable del cajón.', $arqueo->carry_recount_reason);
        $this->assertSame('2000000.00', $arqueo->carry_expected_amount);
        $this->assertSame('2000000.00', $arqueo->carry_counted_amount);
        $this->assertSame('0.00', $arqueo->carryDifference());

        // Cada conteo conserva su composición.
        $this->assertSame([['100000.00', 5]], $this->lineas($arqueo, CashCountScope::Day));
        $this->assertSame([['100000.00', 20]], $this->lineas($arqueo, CashCountScope::Carry));
    }

    /** La comparación usa una foto física anterior, nunca un saldo inferido. */
    public function test_encuentra_el_ultimo_conteo_completo_con_denominaciones(): void
    {
        $this->escenario();

        $referencia = CashCount::query()
            ->with(['lines', 'carryLines'])
            ->lastFullCountBefore(
                $this->caja(),
                Currency::Ars,
                CarbonImmutable::parse(self::DIA),
            )
            ->firstOrFail();

        $this->assertSame('2026-06-01', $referencia->counted_on->toDateString());
        $this->assertSame('2000000.00', $referencia->counted_amount);
        $this->assertSame([['100000.00', 20]], $this->lineas($referencia, CashCountScope::Day));
    }

    /**
     * Un faltante viejo deja de leerse como un faltante de hoy.
     *
     * Es el motivo entero de la separación. La diferencia del día sigue
     * siendo la misma —el cajón tiene cien mil pesos menos de los que el
     * libro dice—, pero ahora está dicho de dónde faltan: de la plata que
     * nadie contaba desde la apertura, no de la recaudación de la jornada.
     */
    public function test_el_faltante_del_fajo_queda_atribuido_al_fajo(): void
    {
        $this->escenario();

        $arqueo = $this->arquear(
            delDia: [100_000 => 5],
            recuento: new CarryRecount(
                reason: 'Sospecha de faltante: se encontró un billete menos y se labró acta.',
                denominations: [100_000 => 19],
            ),
        );

        $this->assertSame('-100000.00', $arqueo->difference_amount);
        $this->assertSame('-100000.00', $arqueo->carryDifference());
        $this->assertNull($arqueo->explanation);

        // Lo del día cuadra: los cinco billetes son la recaudación entera.
        $this->assertSame('500000.00', $this->totalDe($arqueo, CashCountScope::Day));

        $revisado = app(ReviewCashCount::class)->handle(
            $arqueo,
            $this->operador('contador')->id,
        );

        $this->assertSame(CashCountStatus::Reviewed, $revisado->status);
    }

    /** Dos repartos internos distintos no son una diferencia monetaria. */
    public function test_diferencias_internas_compensadas_no_exigen_explicacion(): void
    {
        $this->escenario();

        $arqueo = $this->arquear(
            delDia: [100_000 => 6],
            recuento: new CarryRecount('Verificación periódica.', [100_000 => 19]),
        );

        $this->assertSame('0.00', $arqueo->difference_amount);
        $this->assertSame('-100000.00', $arqueo->carryDifference());
        $this->assertTrue($arqueo->hasDayDiscrepancy());
        $this->assertFalse($arqueo->requiresDifferenceExplanation());
        $this->assertNull($arqueo->explanation);

        app(ReviewCashCount::class)->handle($arqueo, $this->operador('contador')->id);
    }

    /**
     * Lo que el libro dice del fajo lo calcula el servidor.
     *
     * Por lo mismo que el saldo teórico del arqueo: si el número contra el
     * que se compara el recuento lo pusiera quien cuenta, el control sería
     * pedirle que apruebe su propio examen. Sale de restarle al saldo del
     * libro la recaudación del día, que es lo que entró y sigue en el cajón.
     */
    public function test_lo_que_el_libro_dice_del_fajo_no_viaja_desde_la_pantalla(): void
    {
        $this->escenario();

        // Medio millón cobrado hoy, dos millones de antes.
        $arqueo = $this->arquear(
            delDia: [100_000 => 5],
            recuento: new CarryRecount('Verificación periódica.', [100_000 => 20]),
        );

        $this->assertSame('2500000.00', $arqueo->expected_amount);
        $this->assertSame('2000000.00', $arqueo->carry_expected_amount);
    }

    /** Un fajo vacío es un resultado, no la ausencia de un recuento. */
    public function test_encontrar_el_fajo_vacio_se_registra_igual(): void
    {
        $this->escenario();

        $arqueo = $this->arquear(
            delDia: [100_000 => 5],
            recuento: new CarryRecount('El sobre apareció abierto.', []),
            explicacion: 'El fajo no estaba. Se labró acta.',
        );

        $this->assertTrue($arqueo->recountedTheCarry());
        $this->assertSame('0.00', $arqueo->carry_counted_amount);
        $this->assertSame('-2000000.00', $arqueo->carryDifference());
        $this->assertSame([], $this->lineas($arqueo, CashCountScope::Carry));
    }

    /**
     * Un arqueo cuyo único conteo es el del fajo se puede revisar.
     *
     * La guarda de `ReviewCashCount` rechaza un arqueo sin denominaciones
     * contadas, y con las líneas separadas por conteo el día que no entró
     * efectivo quedaba con las del día vacías. Miraba el conteo equivocado:
     * los billetes del fajo también se contaron.
     */
    public function test_se_revisa_un_arqueo_donde_solo_se_conto_el_fajo(): void
    {
        $this->escenario();

        // El medio millón del día se lo llevó el beneficiario; queda el fondo.
        $arqueo = $this->arquear(
            delDia: [],
            recuento: new CarryRecount('Arqueo sorpresivo.', [100_000 => 20]),
            explicacion: 'Se contó solo el fondo: la recaudación se pagó en el día.',
        );

        $revisado = app(ReviewCashCount::class)->handle(
            $arqueo,
            $this->operador('contador')->id,
        );

        $this->assertSame(CashCountStatus::Reviewed, $revisado->status);
        $this->assertSame([], $this->lineas($revisado, CashCountScope::Day));
    }

    public function test_abrir_el_fajo_exige_decir_por_que(): void
    {
        $this->escenario();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('exige decir por qué');

        $this->arquear(
            delDia: [100_000 => 5],
            recuento: new CarryRecount('   ', [100_000 => 20]),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | La base, que es la que no se puede saltear
    |--------------------------------------------------------------------------
    */

    /** Los tres datos del recuento van juntos o no va ninguno. */
    public function test_la_base_rechaza_un_recuento_a_medias(): void
    {
        $this->escenario();
        $arqueo = $this->arquear(delDia: [100_000 => 5]);

        $this->expectExceptionMessage('cash_counts_carry_recount_check');

        DB::table('cash_counts')
            ->where('id', $arqueo->id)
            ->update(['carry_recount_reason' => 'Sin los importes.']);
    }

    /** Y recontar excluye declarar: no se puede tener las dos cosas. */
    public function test_la_base_rechaza_recontar_y_declarar_sin_recontar(): void
    {
        $this->escenario();
        $arqueo = $this->arquear(delDia: [100_000 => 5]);

        $this->expectExceptionMessage('cash_counts_carry_excludes_uncounted_check');

        DB::table('cash_counts')->where('id', $arqueo->id)->update([
            'carry_recount_reason' => 'Verificación.',
            'carry_expected_amount' => '2000000.00',
            'carry_counted_amount' => '2000000.00',
        ]);
    }

    /**
     * Las líneas del fajo tienen que sumar lo que el recuento declara.
     *
     * Misma regla que el total del arqueo, y por lo mismo: un importe
     * tipeado puede contradecir a sus propios sumandos. El trigger es
     * diferido, así que bajo `RefreshDatabase` hay que adelantarlo para que
     * llegue a dispararse.
     */
    public function test_la_base_rechaza_lineas_del_fajo_sin_recuento_declarado(): void
    {
        $this->escenario();
        $arqueo = $this->arquear(delDia: [100_000 => 5]);

        $this->expectExceptionMessage('sin declarar el recuento');

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::table('cash_count_lines')->insert([
            'cash_count_id' => $arqueo->id,
            'scope' => CashCountScope::Carry->value,
            'denomination' => '100000.00',
            'quantity' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Andamiaje
    |--------------------------------------------------------------------------
    */

    /**
     * Dos millones de la apertura y medio millón cobrado hoy.
     *
     * Es el reparto que hace interesante al recuento: si todo el cajón fuera
     * del día no habría fajo que abrir.
     */
    private function escenario(): void
    {
        app(RegisterOpeningBalance::class)->handle(
            cashBoxId: $this->caja(),
            balances: [LedgerAccount::CashOnHand->value => '2000000.00'],
            denominations: $this->billetesPara('2000000.00'),
            date: CarbonImmutable::parse('2026-06-01'),
            actorId: $this->operador('administrador')->id,
        );

        $this->cobrar($this->cuota('131010/2023', '500000.00'), 72190, self::DIA);
    }

    /**
     * @param  array<int, int>  $delDia
     */
    private function arquear(
        array $delDia,
        ?CarryRecount $recuento = null,
        ?string $explicacion = null,
    ): CashCount {
        return app(RecordCashCount::class)->handle(
            cashBoxId: $this->caja(),
            countedOn: CarbonImmutable::parse(self::DIA),
            denominations: $delDia,
            actorId: $this->operador('contador')->id,
            explanation: $explicacion,
            carryRecount: $recuento,
        );
    }

    /**
     * Las líneas de uno de los dos conteos, como pares denominación/cantidad.
     *
     * @return list<array{0: string, 1: int}>
     */
    private function lineas(CashCount $arqueo, CashCountScope $scope): array
    {
        $filas = [];

        foreach ($arqueo->allLines()->where('scope', $scope)->get() as $linea) {
            $filas[] = [$linea->denomination, (int) $linea->quantity];
        }

        return $filas;
    }

    private function totalDe(CashCount $arqueo, CashCountScope $scope): string
    {
        return (string) $arqueo->allLines()->where('scope', $scope)->sum('subtotal');
    }

    private function caja(): int
    {
        return (int) CashBox::query()->where('code', CashBox::HABERES)->value('id');
    }
}
