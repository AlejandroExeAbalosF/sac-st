<?php

declare(strict_types=1);

namespace Tests\Feature\Multas;

use App\Models\User;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Banking\Models\ChequeRejection;
use App\Modules\Ledger\Actions\ClosePeriod;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\PeriodType;
use App\Modules\Ledger\Models\FinancialEvent;
use App\Modules\Multas\Actions\ReportFineBankPayment;
use App\Modules\Multas\Enums\FinePaymentMedium;
use App\Modules\Multas\Enums\FinePaymentStatus;
use App\Modules\Multas\Enums\FineTimeliness;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Multas\Models\FinePaymentReceipt;
use App\Modules\Multas\Support\FineMovements;
use App\Modules\Shared\Models\CashBox;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\BuildsSharedBankAccount;
use Tests\TestCase;

/**
 * Los créditos sin identificar de la cuenta compartida y la consulta
 * operativa de Multas (Etapa 6c, M08 y M10).
 */
class CreditosYMovimientosDeMultasTest extends TestCase
{
    use BuildsSharedBankAccount;
    use RefreshDatabase;

    private BankAccount $laCuenta;

    // ── La cola ──────────────────────────────────────────────────────────

    /** La cola es la de la cuenta: sin lo ya usado, y el panel cuenta lo mismo. */
    public function test_the_queue_lists_only_free_credits_and_the_panel_counts_the_same(): void
    {
        $multa = $this->escenario();
        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00'));

        $libre = $this->credito('7300.00');
        // La acreditación de un depósito de la caja: ya está imputada.
        $pago = $this->cobrarEfectivo($multa, '5000.00');
        $this->actingAs($this->operador())->post(route('multas.pagos.deposito.store', $pago), [
            'depositDate' => '2026-09-15', 'ticket' => UploadedFile::fake()->image('ticket.jpg'), 'idempotencyKey' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
        $acreditacion = $this->credito('5000.00');
        $traslado = CashToBankTransfer::query()->sole();
        $this->actingAs($this->operador('contador'))->post(route('multas.traslados.confirm', $traslado), [
            'bankTransactionId' => $acreditacion->id, 'idempotencyKey' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->operador('consulta'))
            ->get(route('multas.caja.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('creditos.items', 1)
                ->where('creditos.items.0.credit.id', $libre->id)
                ->where('bank.unclassifiedCount', 1)
                ->where('bank.unclassifiedAmount', '7300.00')
                ->where('canIdentify', false));
    }

    /**
     * Presentado el 29/09, cerrado septiembre el 30/09, identificado el 02/10:
     * verificar el pago informado conserva su presentación y queda en término.
     */
    public function test_identifying_a_reported_payment_keeps_its_presentation(): void
    {
        $multa = $this->escenario();

        $this->travelTo(CarbonImmutable::parse('2026-09-29 10:00'));
        $informado = app(ReportFineBankPayment::class)->handle(
            $multa, FinePaymentMedium::BankTransfer, '12000.00', (string) Str::uuid(), $this->quienAbre(),
        );
        $credito = $this->credito('12000.00', '2026-09-29');

        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00'));
        $this->cerrarSeptiembre();

        $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00'));
        $this->actingAs($this->operador('contador'))
            ->get(route('multas.caja.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('creditos.items.0.credit.id', $credito->id)
                ->where('creditos.items.0.reported.0.payment.id', $informado->id)
                ->where('canIdentify', true));

        $this->actingAs($this->operador('contador'))
            ->post(route('multas.pagos.verify', $informado), ['bankTransactionId' => $credito->id])
            ->assertSessionHasNoErrors();

        $informado->refresh();
        expect($informado->status)->toBe(FinePaymentStatus::Applied)
            ->and($informado->application()->firstOrFail()->timeliness)->toBe(FineTimeliness::OnTime)
            ->and(FinePayment::query()->count())->toBe(1)
            ->and(FinePaymentReceipt::query()->where('fine_payment_id', $informado->id)->exists())->toBeTrue();

        $this->actingAs($this->operador('contador'))
            ->get(route('multas.caja.index'))
            ->assertInertia(fn (Assert $page) => $page->has('creditos.items', 0));
    }

    /** Un crédito que nadie informó: se busca la multa y se registra el pago, que se presenta hoy. */
    public function test_identifying_a_credit_nobody_reported_creates_the_payment(): void
    {
        $multa = $this->escenario();
        $this->travelTo(CarbonImmutable::parse('2026-09-28 10:00'));
        $credito = $this->credito('9000.00', '2026-09-28');
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00'));
        $this->cerrarSeptiembre();

        $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00'));
        $contador = $this->operador('contador');

        $this->actingAs($contador)
            ->getJson(route('multas.caja.multas', ['q' => 'CRISTOMAX']))
            ->assertJsonPath('0.id', $multa->id);

        $clave = (string) Str::uuid();
        $identificar = fn () => $this->actingAs($contador)->post(route('multas.pagos-bancarios.verificados.store', $multa), [
            'medium' => 'bank_transfer', 'bankTransactionId' => $credito->id, 'idempotencyKey' => $clave,
        ]);

        $identificar()->assertSessionHasNoErrors();
        // El doble clic no registra dos pagos.
        $identificar()->assertSessionHasNoErrors();

        $pago = FinePayment::query()->sole();
        expect($pago->status)->toBe(FinePaymentStatus::Applied)
            // Presentado al identificarlo, después del cierre de septiembre.
            ->and($pago->application()->firstOrFail()->timeliness)->toBe(FineTimeliness::Late);
    }

    /** Dos operadores con el mismo crédito: el segundo recibe el motivo y nada queda a medias. */
    public function test_a_credit_cannot_be_identified_twice(): void
    {
        $multa = $this->escenario();
        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00'));
        $uno = app(ReportFineBankPayment::class)->handle($multa, FinePaymentMedium::BankTransfer, '3000.00', (string) Str::uuid(), $this->quienAbre());
        $otro = app(ReportFineBankPayment::class)->handle($multa, FinePaymentMedium::BankTransfer, '3000.00', (string) Str::uuid(), $this->quienAbre());
        $credito = $this->credito('3000.00');

        $this->actingAs($this->operador('contador'))
            ->post(route('multas.pagos.verify', $uno), ['bankTransactionId' => $credito->id])
            ->assertSessionHasNoErrors();

        $eventos = FinancialEvent::query()->count();

        $this->actingAs($this->operador('administrador'))
            ->post(route('multas.pagos.verify', $otro), ['bankTransactionId' => $credito->id])
            ->assertSessionHasErrors();

        expect($otro->refresh()->status)->toBe(FinePaymentStatus::Reported)
            ->and(FinancialEvent::query()->count())->toBe($eventos);
    }

    /** Si el recibo falla, no queda ni ingreso ni aplicación: el crédito sigue en la cola. */
    public function test_a_failed_receipt_leaves_nothing_behind(): void
    {
        $multa = $this->escenario();
        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00'));
        $this->cobrarEfectivo($multa, '1000.00', talonario: '18467');
        $credito = $this->credito('4000.00');
        $eventos = FinancialEvent::query()->count();

        $this->actingAs($this->operador('contador'))->post(route('multas.pagos-bancarios.verificados.store', $multa), [
            'medium' => 'bank_transfer', 'bankTransactionId' => $credito->id,
            // Esa hoja del talonario ya se usó.
            'talonarioNumber' => '18467', 'idempotencyKey' => (string) Str::uuid(),
        ])->assertSessionHasErrors('talonarioNumber');

        expect(FinancialEvent::query()->count())->toBe($eventos)
            ->and(FinePayment::query()->where('medium', 'bank_transfer')->exists())->toBeFalse();

        $this->actingAs($this->operador('contador'))
            ->get(route('multas.caja.index'))
            ->assertInertia(fn (Assert $page) => $page->where('creditos.items.0.credit.id', $credito->id));
    }

    /** Mirar la cola es de cualquiera que mire la caja; identificar, de quien concilia. */
    public function test_only_who_verifies_payments_can_identify(): void
    {
        $multa = $this->escenario();
        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00'));
        $credito = $this->credito('4000.00');

        $this->actingAs($this->operador())
            ->post(route('multas.pagos-bancarios.verificados.store', $multa), [
                'medium' => 'bank_transfer', 'bankTransactionId' => $credito->id, 'idempotencyKey' => (string) Str::uuid(),
            ])->assertForbidden();
        $this->actingAs($this->operador())->getJson(route('multas.caja.multas', ['q' => 'CRISTO']))->assertForbidden();
    }

    // ── La consulta operativa ────────────────────────────────────────────

    /**
     * Cada sección con su fecha y su total, sin sumarse entre sí: el depósito
     * no es un cobro y el reemplazo no es recaudación.
     */
    public function test_the_month_lists_each_kind_of_movement_by_its_own_date(): void
    {
        $multa = $this->escenario();
        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00'));
        $this->cobrarEfectivo($multa, '5000.00');
        $cheque = $this->cobrarCheque($multa, '20000.00', 'A-0001');
        $this->actingAs($this->operador())->post(route('multas.pagos.deposito.store', $cheque), [
            'depositDate' => '2026-09-15', 'ticket' => UploadedFile::fake()->image('ticket.jpg'), 'idempotencyKey' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();

        $this->travelTo(CarbonImmutable::parse('2026-09-16 10:00'));
        $this->actingAs($this->operador('contador'))->post(route('multas.pagos.rechazo.store', $cheque), [
            'rejectedOn' => '2026-09-16', 'reason' => 'Sin fondos', 'evidence' => UploadedFile::fake()->image('aviso.jpg'),
            'idempotencyKey' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->operador())->post(
            route('multas.rechazos.reemplazo', ChequeRejection::query()->sole()),
            ['chequeNumber' => 'B-0002', 'chequeBank' => 'Banco Macro', 'idempotencyKey' => (string) Str::uuid()],
        )->assertSessionHasNoErrors();

        $credito = $this->credito('9000.00', '2026-09-16');
        $this->actingAs($this->operador('contador'))->post(route('multas.pagos-bancarios.verificados.store', $multa), [
            'medium' => 'bank_transfer', 'bankTransactionId' => $credito->id, 'idempotencyKey' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();

        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00'));

        $this->actingAs($this->operador('consulta'))
            ->get(route('multas.movimientos.index', ['mes' => '2026-09']))
            ->assertInertia(function (Assert $page): void {
                $secciones = collect($page->toArray()['props']['sections'])->keyBy('key');

                expect($secciones['cobros']['count'])->toBe(2)
                    ->and($secciones['cobros']['total'])->toBe('25000.00')
                    ->and($secciones['aplicaciones']['total'])->toBe('34000.00')
                    ->and($secciones['depositos']['total'])->toBe('20000.00')
                    ->and($secciones['rechazos']['rows'][0]['detail'])->toContain('cheque n.º A-0001')
                    ->and($secciones['reemplazos']['rows'][0]['detail'])->toContain('no es recaudación')
                    ->and($secciones['reemplazos']['rows'][0]['caption'])->toBe('CRISTOMAX S.A.S')
                    ->and($secciones['creditos']['total'])->toBe('9000.00')
                    ->and($page->toArray()['props'])->not->toHaveKey('total');
            });

        $exporte = $this->actingAs($this->operador('consulta'))
            ->get(route('multas.movimientos.exportar', ['mes' => '2026-09']))
            ->assertOk()
            ->streamedContent();
        $archivo = tempnam(sys_get_temp_dir(), 'mov').'.xlsx';
        file_put_contents($archivo, $exporte);
        $filas = IOFactory::load($archivo)->getActiveSheet()->toArray(null, false, false);
        @unlink($archivo);

        // El rótulo, una fila por movimiento y el total de cada sección; ningún total general.
        expect($filas[1][0])->toBe('Consulta operativa: no es la rendición oficial.')
            ->and(array_column(array_filter($filas, fn (array $f): bool => $f[0] === 'Cobros en caja' && $f[1] !== 'Total de la sección'), 7))
            ->toBe(['5.000,00', '20.000,00'])
            ->and(count(array_filter($filas, fn (array $f): bool => $f[1] === 'Total de la sección')))->toBe(count(FineMovements::SECTIONS))
            ->and(array_filter($filas, fn (array $f): bool => str_contains(mb_strtolower((string) $f[0]), 'total general')))->toBe([]);
    }

    /** La cola se recorre entera: los de Aranceles no tapan a los que vienen detrás. */
    public function test_the_queue_is_paged_so_every_credit_can_be_reached(): void
    {
        $this->escenario();
        $this->travelTo(CarbonImmutable::parse('2026-09-20 10:00'));

        for ($i = 1; $i <= 51; $i++) {
            $ultimo = $this->credito((string) (1000 + $i).'.00', '2026-09-'.str_pad((string) (1 + intdiv($i, 3)), 2, '0', STR_PAD_LEFT));
        }

        $contador = $this->operador('contador');

        $this->actingAs($contador)
            ->get(route('multas.caja.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('creditos.items', 50)
                ->where('creditos.page', 1)
                ->where('creditos.lastPage', 2)
                ->where('bank.unclassifiedCount', 51));

        $this->actingAs($contador)
            ->get(route('multas.caja.index', ['creditos' => 2]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('creditos.items', 1)
                ->where('creditos.items.0.credit.id', $ultimo->id));

        // Una página que ya no existe cae en la última, no en una cola vacía.
        $this->actingAs($contador)
            ->get(route('multas.caja.index', ['creditos' => 9]))
            ->assertInertia(fn (Assert $page) => $page->where('creditos.page', 2)->has('creditos.items', 1));
    }

    /** Lo aplicado a la deuda y el excedente van separados, en pantalla y en el Excel. */
    public function test_the_excess_is_not_counted_as_applied_to_the_debt(): void
    {
        $multa = $this->escenario();
        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00'));
        $this->cobrarEfectivo($multa, '100600.00');
        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00'));

        $this->actingAs($this->operador('consulta'))
            ->get(route('multas.movimientos.index', ['mes' => '2026-09']))
            ->assertInertia(function (Assert $page): void {
                $secciones = collect($page->toArray()['props']['sections'])->keyBy('key');

                expect($secciones['aplicaciones']['total'])->toBe('100000.00')
                    ->and($secciones['excedentes']['total'])->toBe('600.00')
                    ->and($secciones['excedentes']['rows'][0]['detail'])->toContain('no reduce la deuda')
                    ->and($secciones['cobros']['total'])->toBe('100600.00');
            });

        $filas = $this->exporte('2026-09');
        $total = fn (string $seccion): mixed => collect($filas)->first(fn (array $f): bool => $f[0] === $seccion && $f[1] === 'Total de la sección')[7] ?? null;

        expect($total('Aplicaciones a la deuda'))->toBe('100.000,00')
            ->and($total('Excedentes al aplicar'))->toBe('600,00');
    }

    /**
     * Un depósito cancelado queda como antecedente, pero no suma: volver a
     * depositar ese cobro no puede contar dos veces el mismo dinero.
     */
    public function test_a_cancelled_deposit_stays_listed_but_out_of_the_total(): void
    {
        $multa = $this->escenario();
        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00'));
        $pago = $this->cobrarEfectivo($multa, '5000.00');
        $depositar = fn () => $this->actingAs($this->operador())->post(route('multas.pagos.deposito.store', $pago), [
            'depositDate' => '2026-09-15', 'ticket' => UploadedFile::fake()->image('ticket.jpg'), 'idempotencyKey' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();

        $depositar();
        $this->actingAs($this->operador('contador'))
            ->post(route('multas.traslados.cancel', CashToBankTransfer::query()->sole()), [
                'reason' => 'Se cargó el ticket de otro depósito', 'idempotencyKey' => (string) Str::uuid(),
            ])->assertSessionHasNoErrors();
        $depositar();

        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00'));

        $this->actingAs($this->operador('consulta'))
            ->get(route('multas.movimientos.index', ['mes' => '2026-09']))
            ->assertInertia(function (Assert $page): void {
                $depositos = collect($page->toArray()['props']['sections'])->keyBy('key')['depositos'];

                expect($depositos['count'])->toBe(2)
                    ->and($depositos['total'])->toBe('5000.00')
                    ->and($depositos['excludedCount'])->toBe(1)
                    ->and($depositos['excludedTotal'])->toBe('5000.00')
                    ->and(array_column($depositos['rows'], 'counted'))->toBe([false, true]);
            });

        $filas = collect($this->exporte('2026-09'))->filter(fn (array $f): bool => $f[0] === 'Depósitos al banco');

        expect($filas->firstWhere(1, 'Total de la sección')[7])->toBe('5.000,00')
            ->and($filas->firstWhere(1, 'Fuera del total (cancelados)')[7])->toBe('5.000,00')
            ->and($filas->pluck(8)->filter()->values()->all())->toBe(['No', 'Sí']);
    }

    /** @return list<list<mixed>> */
    private function exporte(string $mes): array
    {
        $contenido = $this->actingAs($this->operador('consulta'))
            ->get(route('multas.movimientos.exportar', ['mes' => $mes]))
            ->assertOk()
            ->streamedContent();
        $archivo = tempnam(sys_get_temp_dir(), 'mov').'.xlsx';
        file_put_contents($archivo, $contenido);
        $filas = IOFactory::load($archivo)->getActiveSheet()->toArray(null, false, false);
        @unlink($archivo);

        return $filas;
    }

    private function escenario(): Fine
    {
        $this->laCuenta = $this->cuenta();
        $this->abrirMultas($this->laCuenta, '2026-06-01');

        $this->travelTo(CarbonImmutable::parse('2026-09-01 10:00'));
        $this->actingAs($this->operador())->post(route('multas.store'), [
            'caseNumber' => '169607/24-0',
            'resolution' => ['text' => 'N°0649/26', 'issuedOn' => '2026-05-07'],
            'acts' => [['actType' => 'HYS', 'text' => 'N°1089657', 'issuedOn' => null]],
            'caption' => 'CRISTOMAX S.A.S',
            'registeredOn' => '2026-09-01',
            'amount' => '100000.00',
        ])->assertSessionHasNoErrors();

        return Fine::query()->latest('id')->firstOrFail();
    }

    private function credito(string $importe, ?string $fecha = null): BankTransaction
    {
        return $this->movimiento($this->laCuenta, $fecha ?? '2026-09-15', $importe);
    }

    private function cobrarEfectivo(Fine $multa, string $importe, ?string $talonario = null): FinePayment
    {
        $this->actingAs($this->operador())->post(route('multas.cobros.store', $multa), array_filter([
            'medium' => 'cash', 'amount' => $importe, 'talonarioNumber' => $talonario, 'idempotencyKey' => (string) Str::uuid(),
        ]))->assertSessionHasNoErrors();

        return FinePayment::query()->latest('id')->firstOrFail();
    }

    private function cobrarCheque(Fine $multa, string $importe, string $numero): FinePayment
    {
        $this->actingAs($this->operador())->post(route('multas.cobros.store', $multa), [
            'medium' => 'cheque', 'amount' => $importe,
            'cheque' => ['number' => $numero, 'bank' => 'Banco Nación', 'issueDate' => null],
            'idempotencyKey' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();

        return FinePayment::query()->latest('id')->firstOrFail();
    }

    private function cerrarSeptiembre(): void
    {
        app(ClosePeriod::class)->handle(
            cashBoxId: $this->cajaDe(CashBox::MULTAS),
            date: CarbonImmutable::parse('2026-09-30'),
            type: PeriodType::Monthly,
            currency: Currency::Ars,
            actorId: User::query()->findOrFail($this->quienAbre())->id,
        );
    }
}
