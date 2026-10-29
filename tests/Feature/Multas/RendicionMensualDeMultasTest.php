<?php

declare(strict_types=1);

namespace Tests\Feature\Multas;

use App\Models\User;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Ledger\Actions\ClosePeriod;
use App\Modules\Ledger\Actions\ReopenPeriod;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\PeriodType;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Multas\Actions\EmitFineMonthlyReport;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineMonthlyReport;
use App\Modules\Shared\Models\CashBox;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsSharedBankAccount;
use Tests\TestCase;

/**
 * La rendición mensual de Multas a la SAF (P02, Etapa 8), con el escenario de
 * las notas de septiembre de 2026 corrido un mes —la base exige que el mes
 * haya terminado según su propio reloj—: lo acreditado y reconocido en el mes va
 * en término; lo reconocido en el mes con una acreditación de un mes anterior,
 * fuera de término. El corte es el fin del mes, no su cierre manual. Cada pago,
 * en un solo informe.
 */
class RendicionMensualDeMultasTest extends TestCase
{
    use BuildsSharedBankAccount;
    use RefreshDatabase;

    private BankAccount $laCuenta;

    private Fine $multa;

    public function test_each_payment_lands_in_one_report_by_its_credit_and_its_recognition(): void
    {
        $this->escenario();

        // Julio: entra un crédito que nadie identifica antes del cierre del mes.
        $deJulio = $this->movimiento($this->laCuenta, '2026-07-28', '13000.00');
        $this->el('2026-08-01');
        $this->cerrar('2026-07-31', PeriodType::Monthly);

        // Agosto: efectivo cobrado el 04/08 y acreditado el 11/08.
        $this->el('2026-08-04');
        $this->actingAs($this->operador())->post(route('multas.cobros.store', $this->multa), [
            'medium' => 'cash', 'amount' => '4200.00', 'idempotencyKey' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
        $this->el('2026-08-05');
        $this->identificar($deJulio);
        $this->el('2026-08-11');
        $this->actingAs($this->operador())->post(route('multas.pagos.deposito.store', $this->multa->payments()->where('medium', 'cash')->sole()), [
            'depositDate' => '2026-08-11', 'ticket' => UploadedFile::fake()->image('ticket.jpg'), 'idempotencyKey' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
        $credito = $this->movimiento($this->laCuenta, '2026-08-11', '4200.00');
        $this->actingAs($this->operador('contador'))->post(route('multas.traslados.confirm', CashToBankTransfer::query()->sole()), [
            'bankTransactionId' => $credito->id, 'idempotencyKey' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();

        // Fin de agosto: dos créditos que se reconocen en septiembre, uno con agosto
        // todavía abierto y otro ya cerrado. Los dos pasan a septiembre.
        $antes = $this->movimiento($this->laCuenta, '2026-08-31', '9000.00');
        $despues = $this->movimiento($this->laCuenta, '2026-08-29', '7000.00');

        $this->el('2026-09-02');
        $this->identificar($antes);
        $this->el('2026-09-05');
        foreach (['2026-08-04', '2026-08-05', '2026-08-11'] as $dia) {
            $this->cerrar($dia, PeriodType::Daily);
        }
        $agostoCerrado = $this->cerrar('2026-08-31', PeriodType::Monthly);
        $this->el('2026-09-06');
        $this->identificar($despues);

        $rendicion = app(EmitFineMonthlyReport::class)->handle($agostoCerrado, $this->quienAbre());

        expect($this->filas($rendicion['on_time']))->toBe([['2026-08-11', 'CC', '4200.00']])
            ->and($rendicion['on_time']->total)->toBe('4200.00')
            ->and($this->filas($rendicion['late']))->toBe([['2026-07-28', 'Trans.', '13000.00']])
            ->and($rendicion['on_time']->payload[0]['caption'])->toBe('CRISTOMAX S.A.S')
            ->and($rendicion['on_time']->attachment_id)->not->toBeNull();

        // Emitirla de nuevo devuelve la misma.
        $otra = app(EmitFineMonthlyReport::class)->handle($agostoCerrado, $this->quienAbre());
        expect($otra['on_time']->id)->toBe($rendicion['on_time']->id)
            ->and(FineMonthlyReport::query()->count())->toBe(2);

        // Septiembre: los dos créditos de agosto reconocidos en septiembre van fuera de término.
        $this->el('2026-10-02');
        foreach (['2026-09-02', '2026-09-06'] as $dia) {
            $this->cerrar($dia, PeriodType::Daily);
        }
        $septiembreRendido = app(EmitFineMonthlyReport::class)->handle($this->cerrar('2026-09-30', PeriodType::Monthly), $this->quienAbre());

        expect($this->filas($septiembreRendido['on_time']))->toBe([])
            ->and($this->filas($septiembreRendido['late']))->toBe([
                ['2026-08-29', 'Trans.', '7000.00'],
                ['2026-08-31', 'Trans.', '9000.00'],
            ]);

        // Ningún pago figura en dos informes.
        $todas = collect([$rendicion['on_time'], $rendicion['late'], $septiembreRendido['on_time'], $septiembreRendido['late']])
            ->flatMap(fn (FineMonthlyReport $i): array => array_column($i->payload, 'receipt'));
        expect($todas->duplicates()->all())->toBe([])->and($todas)->toHaveCount(4);

        // El papel: en el visor, con el importe en letras.
        $this->actingAs($this->operador('consulta'))
            ->get(route('multas.rendiciones.view', $rendicion['late']))
            ->assertOk()
            ->assertSee('FUERA DE TÉRMINO MES DE AGOSTO DE 2026')
            ->assertSee('Son Pesos: Trece mil', false);
    }

    /**
     * El corte es el último día del mes: lo reconocido el 31/08 va en término
     * aunque agosto se cierre días después, y lo reconocido el 01/09 ya es de
     * septiembre aunque agosto siguiera abierto.
     */
    public function test_the_cut_is_the_end_of_the_month_not_its_closing(): void
    {
        $this->escenario();
        $ultimoDia = $this->movimiento($this->laCuenta, '2026-08-20', '9000.00');
        $alOtroDia = $this->movimiento($this->laCuenta, '2026-08-21', '7000.00');

        $this->el('2026-08-31');
        $this->identificar($ultimoDia);
        $this->el('2026-09-01');
        $this->identificar($alOtroDia);

        $this->el('2026-09-05');
        $this->cerrar('2026-07-31', PeriodType::Monthly);
        $this->cerrar('2026-08-31', PeriodType::Daily);
        $agosto = app(EmitFineMonthlyReport::class)->handle($this->cerrar('2026-08-31', PeriodType::Monthly), $this->quienAbre());

        $this->el('2026-10-01');
        $this->cerrar('2026-09-01', PeriodType::Daily);
        $septiembre = app(EmitFineMonthlyReport::class)->handle($this->cerrar('2026-09-30', PeriodType::Monthly), $this->quienAbre());

        expect($this->filas($agosto['on_time']))->toBe([['2026-08-20', 'Trans.', '9000.00']])
            ->and($this->filas($agosto['late']))->toBe([])
            ->and($this->filas($septiembre['on_time']))->toBe([])
            ->and($this->filas($septiembre['late']))->toBe([['2026-08-21', 'Trans.', '7000.00']]);
    }

    /**
     * El mes del calendario de Multas trae su rendición: emitirla con el mes
     * cerrado, y después sus dos notas. Y lo que el libro reconoció en la
     * cuenta, por mes y en el año.
     */
    public function test_the_calendar_month_carries_the_rendition_and_what_the_book_recognized(): void
    {
        $this->escenario();
        $agosto = $this->movimiento($this->laCuenta, '2026-08-20', '9000.00');
        $septiembre = $this->movimiento($this->laCuenta, '2026-08-21', '7000.00');
        $this->el('2026-08-31');
        $this->identificar($agosto);
        $this->el('2026-09-01');
        $this->identificar($septiembre);

        $this->el('2026-09-05');
        $this->cerrar('2026-07-31', PeriodType::Monthly);
        $this->cerrar('2026-08-31', PeriodType::Daily);
        $cierre = $this->cerrar('2026-08-31', PeriodType::Monthly);

        $admin = $this->operador('administrador');
        $mes = fn () => $this->actingAs($admin)->get(route('multas.caja.calendario', ['anio' => 2026, 'mes' => 8]));

        $mes()->assertInertia(fn (Assert $page) => $page
            ->where('bankLedger.label', 'Reconocido en el libro de Multas')
            ->where('bankLedger.byMonth.8', '9000.00')
            ->where('bankLedger.byMonth.9', '7000.00')
            ->where('bankLedger.year', '16000.00')
            ->where('monthPanel.title', 'Rendición a la SAF')
            ->has('monthPanel.documents', 0)
            ->where('monthPanel.emit.href', route('multas.rendiciones.store', $cierre, absolute: false))
            ->where('monthPanel.links.0.href', route('multas.movimientos.index', ['mes' => '2026-08'], absolute: false)));

        $this->actingAs($admin)
            ->from(route('multas.caja.calendario', ['anio' => 2026, 'mes' => 8]))
            ->post(route('multas.rendiciones.store', $cierre))
            ->assertRedirect(route('multas.caja.calendario', ['anio' => 2026, 'mes' => 8]));

        $mes()->assertInertia(fn (Assert $page) => $page
            ->has('monthPanel.documents', 2)
            ->where('monthPanel.documents.0.current', true)
            ->where('monthPanel.emit', null));

        // Quien no emite ve las notas sin el botón.
        $this->actingAs($this->operador('administrativo'))
            ->get(route('multas.caja.calendario', ['anio' => 2026, 'mes' => 7]))
            ->assertInertia(fn (Assert $page) => $page->where('monthPanel.emit', null));
    }

    /** Reabrir y volver a cerrar el mes da otra versión; la anterior queda. */
    public function test_reclosing_the_month_emits_a_new_version_and_keeps_the_old_one(): void
    {
        $this->escenario();
        $this->el('2026-08-01');
        $agosto = $this->cerrar('2026-07-31', PeriodType::Monthly);
        $primera = app(EmitFineMonthlyReport::class)->handle($agosto, $this->quienAbre());

        app(ReopenPeriod::class)->handle($agosto->refresh(), $this->quienAbre(), 'Corregir el cierre de agosto');

        $this->expectException(ValidationException::class);

        try {
            app(EmitFineMonthlyReport::class)->handle($agosto->refresh(), $this->quienAbre());
        } finally {
            $segunda = app(EmitFineMonthlyReport::class);
            $agosto = $this->cerrar('2026-07-31', PeriodType::Monthly);
            $nueva = $segunda->handle($agosto, $this->quienAbre());

            expect($nueva['on_time']->closing_version)->toBe(2)
                ->and($primera['on_time']->refresh()->closing_version)->toBe(1)
                ->and(FineMonthlyReport::query()->count())->toBe(4);
        }
    }

    /** La base no acepta una rendición sobre un mes abierto ni la deja cambiar. */
    public function test_the_database_keeps_the_rendition_frozen(): void
    {
        $this->escenario();
        $this->el('2026-08-01');
        $agosto = $this->cerrar('2026-07-31', PeriodType::Monthly);
        $informe = app(EmitFineMonthlyReport::class)->handle($agosto, $this->quienAbre())['late'];

        $this->rechaza('no se modifica', fn () => DB::table('fine_monthly_reports')->where('id', $informe->id)->update(['total' => '1.00']));
        $this->rechaza('no se borra', fn () => DB::table('fine_monthly_reports')->where('id', $informe->id)->delete());

        app(ReopenPeriod::class)->handle($agosto->refresh(), $this->quienAbre(), 'Corregir el cierre de agosto');
        $this->rechaza('cierre mensual vigente', fn () => DB::table('fine_monthly_reports')->insert([
            'period_closing_id' => $agosto->id, 'closing_version' => 1, 'kind' => 'on_time', 'payload' => '[]',
            'total' => '0.00', 'rows_count' => 0, 'emitted_at' => now(),
        ]));
    }

    /** Mirar es de quien ve multas; emitir, de quien rinde. */
    public function test_who_can_see_and_emit_the_rendition(): void
    {
        $this->escenario();
        $this->el('2026-08-01');
        $agosto = $this->cerrar('2026-07-31', PeriodType::Monthly);

        $this->actingAs($this->operador())->post(route('multas.rendiciones.store', $agosto))->assertForbidden();

        $this->actingAs($this->operador('consulta'))
            ->get(route('multas.rendiciones.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('multas/rendiciones')
                ->where('months.0.closingId', $agosto->id)
                ->where('can.emit', false));

        // Emitir no depende de conciliar: alcanza con el permiso propio.
        $quienRinde = $this->operador('consulta');
        $quienRinde->givePermissionTo('multas.rendicion.emitir');
        expect($quienRinde->can('multas.verificar-pagos'))->toBeFalse();

        $this->actingAs($quienRinde)
            ->post(route('multas.rendiciones.store', $agosto))
            ->assertSessionHasNoErrors();

        expect(FineMonthlyReport::query()->count())->toBe(2);
    }

    private function escenario(): void
    {
        $this->laCuenta = $this->cuenta();
        $this->abrirMultas($this->laCuenta, '2026-07-01');

        $this->el('2026-07-01');
        $this->actingAs($this->operador())->post(route('multas.store'), [
            'caseNumber' => '169607/24-0',
            'resolution' => ['text' => 'N°0649/26', 'issuedOn' => '2026-04-07'],
            'acts' => [],
            'caption' => 'CRISTOMAX S.A.S',
            'registeredOn' => '2026-07-01',
            'amount' => '500000.00',
        ])->assertSessionHasNoErrors();

        $this->multa = Fine::query()->latest('id')->firstOrFail();
    }

    private function identificar(BankTransaction $credito): void
    {
        $this->actingAs($this->operador('contador'))->post(route('multas.pagos-bancarios.verificados.store', $this->multa), [
            'medium' => 'bank_transfer', 'bankTransactionId' => $credito->id, 'idempotencyKey' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
    }

    private function el(string $dia): void
    {
        $this->travelTo(CarbonImmutable::parse($dia.' 10:00'));
    }

    private function cerrar(string $fecha, PeriodType $tipo): PeriodClosing
    {
        $quien = User::query()->findOrFail($this->quienAbre());
        $caja = $this->cajaDe(CashBox::MULTAS);

        if ($tipo === PeriodType::Daily) {
            $this->arqueoListoParaCerrar($caja, $fecha, $quien);
        }

        return app(ClosePeriod::class)->handle(
            cashBoxId: $caja,
            date: CarbonImmutable::parse($fecha),
            type: $tipo,
            currency: Currency::Ars,
            actorId: $quien->id,
        );
    }

    /** @return list<list<string>> */
    private function filas(FineMonthlyReport $informe): array
    {
        return array_map(fn (array $f): array => [$f['date'], $f['origin'], $f['amount']], $informe->payload);
    }

    private function rechaza(string $fragmento, callable $accion): void
    {
        try {
            DB::transaction(function () use ($accion): void {
                $accion();
            });
        } catch (QueryException $e) {
            expect(mb_strtolower($e->getMessage()))->toContain(mb_strtolower($fragmento));

            return;
        }

        $this->fail("La base aceptó lo que tenía que rechazar ({$fragmento}).");
    }
}
