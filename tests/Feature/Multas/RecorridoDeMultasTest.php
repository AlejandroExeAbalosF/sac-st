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
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PeriodClosingStatus;
use App\Modules\Ledger\Enums\PeriodType;
use App\Modules\Ledger\Models\FinancialEvent;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Ledger\Support\CashBalance;
use App\Modules\Multas\Actions\ReportFineBankPayment;
use App\Modules\Multas\Enums\FinePaymentMedium;
use App\Modules\Multas\Enums\FineTimeliness;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Shared\Models\CashBox;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsSharedBankAccount;
use Tests\TestCase;

/**
 * El recorrido completo de la caja de Multas con multas nuevas (Etapa 7):
 * de la apertura al cierre del mes y la consulta, con los criterios de
 * aceptación de la Etapa 6 afirmados sobre un solo escenario.
 *
 * Septiembre: dos multas, cobros en efectivo y con cheque, depósitos y su
 * acreditación, el cheque rechazado ya acreditado y su reemplazo, y un pago
 * por banco informado. Octubre, con septiembre cerrado: los pagos que
 * llegan tarde, el que se identifica desde la cola y el papel que se adjunta.
 */
class RecorridoDeMultasTest extends TestCase
{
    use BuildsSharedBankAccount;
    use RefreshDatabase;

    private BankAccount $laCuenta;

    public function test_the_whole_multas_circuit_closes_september_and_judges_october(): void
    {
        $this->laCuenta = $this->cuenta();
        $this->abrirMultas($this->laCuenta, '2026-09-01');

        // ── Septiembre ──────────────────────────────────────────────────
        $this->el('2026-09-03');
        $sancionada = $this->multa('169607/24-0', 'CRISTOMAX S.A.S', '2026-09-03', '100000.00');
        $otra = $this->multa('120276/25', 'LA SERENISIMA S.A.', '2026-09-03', '50000.00');

        $this->el('2026-09-10');
        $efectivo = $this->cobrar($sancionada, ['medium' => 'cash', 'amount' => '10000.00']);
        $cheque = $this->cobrar($sancionada, [
            'medium' => 'cheque', 'amount' => '20000.00',
            'cheque' => ['number' => 'A-0001', 'bank' => 'Banco Nación', 'issueDate' => null],
        ]);

        $this->el('2026-09-11');
        $this->depositar($efectivo);
        $this->depositar($cheque);

        $this->el('2026-09-12');
        foreach (CashToBankTransfer::query()->orderBy('id')->get() as $traslado) {
            $this->acreditar($traslado);
        }

        $this->el('2026-09-15');
        $this->actingAs($this->operador('contador'))->post(route('multas.pagos.rechazo.store', $cheque), [
            'rejectedOn' => '2026-09-15', 'reason' => 'Sin fondos suficientes',
            'evidence' => UploadedFile::fake()->image('aviso.jpg'), 'idempotencyKey' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
        expect($this->saldo($sancionada, 'pending_regularization'))->toBe('20000.00');

        $this->el('2026-09-16');
        $this->actingAs($this->operador())->post(route('multas.rechazos.reemplazo', ChequeRejection::query()->sole()), [
            'chequeNumber' => 'B-0002', 'chequeBank' => 'Banco Macro', 'idempotencyKey' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
        expect($this->saldo($sancionada, 'pending_regularization'))->toBe('0.00');

        $this->el('2026-09-20');
        $informado = app(ReportFineBankPayment::class)->handle(
            $otra, FinePaymentMedium::BankTransfer, '15000.00', (string) Str::uuid(), $this->quienAbre(),
        );
        $creditoInformado = $this->movimiento($this->laCuenta, '2026-09-29', '15000.00');

        // ── Cierre de septiembre ────────────────────────────────────────
        $this->el('2026-10-01');
        foreach (['2026-09-10', '2026-09-11', '2026-09-12', '2026-09-15', '2026-09-16'] as $dia) {
            $this->cerrar($dia, PeriodType::Daily);
        }
        $septiembre = $this->cerrar('2026-09-30', PeriodType::Monthly);

        /*
         * Cuadre del mes: lo cobrado está en el banco, el reemplazo en el
         * cajón, y el cheque rechazado ya acreditado sigue en el banco hasta
         * su débito. Ningún traslado entra como cobro.
         */
        expect($septiembre->closing_cash)->toBe('0.00')
            ->and($septiembre->closing_cheques)->toBe('20000.00')
            ->and($septiembre->closing_bank_deposits)->toBe('30000.00')
            ->and($septiembre->received_cash)->toBe('10000.00')
            ->and($septiembre->deposited_to_bank_cash)->toBe('10000.00')
            ->and($septiembre->deposited_to_bank_cheques)->toBe('20000.00')
            ->and($this->libro(LedgerAccount::PendingBankDebits))->toBe('20000.00')
            ->and($this->libro(LedgerAccount::RejectedCheques))->toBe('0.00')
            ->and($this->saldo($sancionada, 'debt'))->toBe('70000.00');

        // ── Octubre, con septiembre cerrado ─────────────────────────────
        $this->el('2026-10-02');

        // Una multa de septiembre pagada en octubre, después del cierre: fuera de término.
        $tarde = $this->cobrar($sancionada, ['medium' => 'cash', 'amount' => '5000.00']);
        expect($tarde->application()->firstOrFail()->timeliness)->toBe(FineTimeliness::Late);

        // El informado en septiembre e identificado en octubre conserva su presentación: en término.
        $this->actingAs($this->operador('contador'))
            ->get(route('multas.creditos.identificar', $creditoInformado))
            ->assertInertia(fn (Assert $page) => $page
                ->where('credit.id', $creditoInformado->id)
                ->where('reported.0.payment.id', $informado->id));
        $this->actingAs($this->operador('contador'))
            ->post(route('multas.pagos.verify', $informado), ['bankTransactionId' => $creditoInformado->id])
            ->assertSessionHasNoErrors();
        expect($informado->refresh()->application()->firstOrFail()->timeliness)->toBe(FineTimeliness::OnTime);

        /*
         * Pagado en septiembre y presentado en octubre, después del cierre:
         * fuera de término, y sin recargo — la deuda baja exactamente lo
         * pagado y no aparece ningún ajuste.
         */
        $this->el('2026-10-05');
        $creditoTarde = $this->movimiento($this->laCuenta, '2026-09-28', '8000.00');
        $ajustes = DB::table('fine_debt_entries')->where('fine_id', $otra->id)->count();
        $deudaAntes = $this->saldo($otra, 'debt');
        $presentado = app(ReportFineBankPayment::class)->handle(
            $otra, FinePaymentMedium::BankTransfer, '8000.00', (string) Str::uuid(), $this->quienAbre(),
            declaredPaidOn: CarbonImmutable::parse('2026-09-28'),
        );
        $this->actingAs($this->operador('contador'))
            ->post(route('multas.pagos.verify', $presentado), ['bankTransactionId' => $creditoTarde->id])
            ->assertSessionHasNoErrors();
        expect($presentado->refresh()->application()->firstOrFail()->timeliness)->toBe(FineTimeliness::Late)
            ->and($this->saldo($otra, 'debt'))->toBe(bcsub($deudaAntes, '8000.00', 2))
            ->and(DB::table('fine_debt_entries')->where('fine_id', $otra->id)->count())->toBe($ajustes);

        // Adjuntar el papel a la multa no reabre septiembre ni mueve el libro.
        $eventos = FinancialEvent::query()->count();
        $version = $septiembre->refresh()->evidence_version;
        $this->actingAs($this->operador())->post(route('multas.adjuntos.store', $sancionada), [
            'file' => UploadedFile::fake()->image('resolucion.jpg'), 'title' => 'Resolución firmada',
        ])->assertSessionHasNoErrors();
        expect($septiembre->refresh()->status)->toBe(PeriodClosingStatus::Closed)
            ->and($septiembre->evidence_version)->toBe($version)
            ->and(FinancialEvent::query()->count())->toBe($eventos);

        // ── La consulta del mes ─────────────────────────────────────────
        $this->actingAs($this->operador('consulta'))
            ->get(route('multas.movimientos.index', ['mes' => '2026-09']))
            ->assertInertia(function (Assert $page): void {
                $secciones = collect($page->toArray()['props']['sections'])->keyBy('key');

                // Cobrado en la caja: el efectivo y el cheque original, no el reemplazo ni los depósitos.
                expect($secciones['cobros']['total'])->toBe('30000.00')
                    ->and($secciones['depositos']['total'])->toBe('30000.00')
                    ->and($secciones['rechazos']['total'])->toBe('20000.00')
                    ->and($secciones['reemplazos']['total'])->toBe('20000.00')
                    ->and($secciones['aplicaciones']['total'])->toBe('30000.00');
            });

        $this->actingAs($this->operador('consulta'))
            ->get(route('multas.movimientos.index', ['mes' => '2026-10']))
            ->assertInertia(function (Assert $page): void {
                $secciones = collect($page->toArray()['props']['sections'])->keyBy('key');

                expect($secciones['aplicaciones']['total'])->toBe('28000.00')
                    ->and($secciones['creditos']['total'])->toBe('23000.00');
            });

        expect(FinePayment::query()->count())->toBe(5);
    }

    private function el(string $dia): void
    {
        $this->travelTo(CarbonImmutable::parse($dia.' 10:00'));
    }

    private function multa(string $expediente, string $caratula, string $registrada, string $importe): Fine
    {
        $this->actingAs($this->operador())->post(route('multas.store'), [
            'caseNumber' => $expediente,
            'resolution' => ['text' => 'N°'.random_int(100, 999).'/26', 'issuedOn' => '2026-05-07'],
            'acts' => [],
            'caption' => $caratula,
            'registeredOn' => $registrada,
            'amount' => $importe,
        ])->assertSessionHasNoErrors();

        return Fine::query()->latest('id')->firstOrFail();
    }

    /** @param  array<string, mixed>  $datos */
    private function cobrar(Fine $multa, array $datos): FinePayment
    {
        $this->actingAs($this->operador())
            ->post(route('multas.cobros.store', $multa), [...$datos, 'idempotencyKey' => (string) Str::uuid()])
            ->assertSessionHasNoErrors();

        return FinePayment::query()->latest('id')->firstOrFail();
    }

    private function depositar(FinePayment $pago): void
    {
        $this->actingAs($this->operador())->post(route('multas.pagos.deposito.store', $pago), [
            'depositDate' => BusinessDate::today()->toDateString(),
            'ticket' => UploadedFile::fake()->image('ticket.jpg'),
            'idempotencyKey' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
    }

    private function acreditar(CashToBankTransfer $traslado): BankTransaction
    {
        $credito = $this->movimiento($this->laCuenta, BusinessDate::today()->toDateString(), $traslado->amount);

        $this->actingAs($this->operador('contador'))->post(route('multas.traslados.confirm', $traslado), [
            'bankTransactionId' => $credito->id, 'idempotencyKey' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();

        return $credito;
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

    private function saldo(Fine $multa, string $columna): string
    {
        return (string) DB::table('fine_balances')->where('fine_id', $multa->id)->value($columna);
    }

    private function libro(LedgerAccount $cuenta): string
    {
        return app(CashBalance::class)->of($cuenta, $this->cajaDe(CashBox::MULTAS), Currency::Ars);
    }
}
