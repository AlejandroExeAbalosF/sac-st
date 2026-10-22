<?php

declare(strict_types=1);

namespace Tests\Feature\Multas;

use App\Models\User;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Banking\Models\ChequeRejection;
use App\Modules\Ledger\Actions\ClosePeriod;
use App\Modules\Ledger\Actions\ExportCashSheet;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\PeriodType;
use App\Modules\Ledger\Excel\CashSheet;
use App\Modules\Ledger\Excel\CashSheetBuilder;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Multas\Actions\OpenFineCashBook;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Multas\Support\FineCashBoxModule;
use App\Modules\Shared\Models\CashBox;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\BuildsSharedBankAccount;
use Tests\TestCase;

/**
 * El cierre de la caja de Multas con cheques (Etapa 6a): el inventario del
 * reverso es el de la fecha del cierre, y el rechazo y el reemplazo de un
 * cheque caen en la columna que de verdad movieron.
 */
class CierreDeCajaDeMultasTest extends TestCase
{
    use BuildsSharedBankAccount;
    use RefreshDatabase;

    private BankAccount $laCuenta;

    /**
     * Al 15/09 estaba en caja el cheque A; el 02/10 se rechaza y el 03/10
     * llega B. Cerrar el 15/09 después de todo eso lista A, no B ni nada; el
     * 02/10 lo muestra salir y el 03/10 lista B.
     */
    public function test_a_late_closing_lists_the_cheque_that_was_in_the_drawer_that_day(): void
    {
        $multa = $this->escenario();

        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00'));
        $pago = $this->cobrarCheque($multa, '20000.00', 'A-0001');

        $this->travelTo(CarbonImmutable::parse('2026-10-02 10:00'));
        $this->rechazar($pago);

        $this->travelTo(CarbonImmutable::parse('2026-10-03 10:00'));
        $this->actingAs($this->operador())->post(
            route('multas.rechazos.reemplazo', ChequeRejection::query()->firstOrFail()),
            ['chequeNumber' => 'B-0002', 'chequeBank' => 'Banco Macro', 'idempotencyKey' => (string) Str::uuid()],
        )->assertSessionHasNoErrors();

        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00'));

        $septiembre = $this->planilla($this->cerrarDia('2026-09-15'));
        expect(array_column($septiembre->cheques, 'number'))->toBe(['A-0001'])
            ->and($septiembre->cheques[0]['caption'])->toBe('CRISTOMAX S.A.S')
            ->and($septiembre->cheques[0]['expediente'])->toBe('169607/2024-0')
            // El cobro no registra quién libró el cheque, y quien pagó no lo es necesariamente.
            ->and($septiembre->cheques[0]['drawer'])->toBe('')
            ->and($septiembre->closing->closing_cheques)->toBe('20000.00');

        $rechazo = $this->cerrarDia('2026-10-02');
        $delRechazo = $this->planilla($rechazo);
        expect($rechazo->disbursed_cheques)->toBe('20000.00')
            ->and($rechazo->closing_cheques)->toBe('0.00')
            ->and($delRechazo->cheques)->toBe([])
            ->and($delRechazo->expense)->toHaveCount(1)
            ->and($delRechazo->expense[0]['number'])->toBe('Cheque n.º A-0001 rechazado')
            ->and($delRechazo->expense[0]['cheques'])->toBe('20000.00');

        $reemplazo = $this->cerrarDia('2026-10-03');
        $delReemplazo = $this->planilla($reemplazo);
        expect($reemplazo->received_cheques)->toBe('20000.00')
            ->and($reemplazo->closing_cheques)->toBe('20000.00')
            ->and(array_column($delReemplazo->cheques, 'number'))->toBe(['B-0002'])
            // El reemplazo paga la misma multa: la identifica la raíz de la cadena.
            ->and($delReemplazo->cheques[0]['caption'])->toBe('CRISTOMAX S.A.S')
            ->and($delReemplazo->cheques[0]['drawer'])->toBe('')
            ->and($delReemplazo->income)->toHaveCount(1)
            ->and($delReemplazo->income[0]['number'])->toBe('Reemplazo del cheque n.º A-0001 — no es recaudación')
            ->and($delReemplazo->income[0]['receiptId'])->toBeNull();

        // El papel: el rótulo del reemplazo se ajusta en su celda y el reverso lleva las columnas de Multas.
        $libro = IOFactory::load(Storage::disk('local')->path(app(ExportCashSheet::class)->handle($reemplazo)->object_key));
        $anverso = $libro->getSheetByName('CAJA 031026');
        $fila = array_search('Reemplazo del cheque n.º A-0001 — no es recaudación', array_column($anverso?->toArray(null, false, false) ?? [], 0), true);
        $reverso = $libro->getSheetByName('REVERSO 031026')?->toArray(null, false, false) ?? [];
        $encabezado = array_values(array_filter($reverso, fn (array $f): bool => $f[0] === 'RECIBO'))[0] ?? [];

        $columnaA = array_column($anverso?->toArray(null, false, false) ?? [], 0);

        // La columna de banco no es el saldo de la cuenta compartida, y la planilla lo dice.
        expect($columnaA)->toContain('DEPÓSITOS DIRECTOS: Lo reconocido en el libro de Multas. La cuenta es compartida con Aranceles: no es su saldo.')
            ->and($fila)->not->toBeFalse()
            ->and($anverso?->getStyle('A'.((int) $fila + 1))->getAlignment()->getWrapText())->toBeTrue()
            ->and(array_slice($encabezado, 0, 8))->toBe(['RECIBO', 'EXPTE', 'CARÁTULA', 'LIBRADOR', 'CHEQUE Nº', 'BANCO', 'FECHA', 'IMPORTE']);
    }

    /** Rechazado en tránsito, ya había salido de la columna de cheques con el depósito. */
    public function test_a_cheque_rejected_in_transit_does_not_touch_the_cheque_column(): void
    {
        $multa = $this->escenario();

        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00'));
        $pago = $this->cobrarCheque($multa, '20000.00', 'T-0001');
        $this->depositar($pago);

        $this->travelTo(CarbonImmutable::parse('2026-09-16 10:00'));
        $this->rechazar($pago);

        $cierre = $this->cerrarDia('2026-09-16');
        $planilla = $this->planilla($cierre);

        expect($cierre->disbursed_cheques)->toBe('0.00')
            ->and($cierre->closing_cheques)->toBe('0.00')
            ->and($cierre->closing_bank_deposits)->toBe('0.00')
            ->and($planilla->expense)->toBe([])
            ->and($planilla->cheques)->toBe([]);
    }

    /** Rechazado ya acreditado, el banco conserva el crédito hasta que llegue el débito. */
    public function test_a_cheque_rejected_after_its_credit_leaves_the_bank_column_alone(): void
    {
        $multa = $this->escenario();

        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00'));
        $pago = $this->cobrarCheque($multa, '20000.00', 'C-0001');
        $traslado = $this->depositar($pago);
        $this->acreditar($traslado);

        $this->travelTo(CarbonImmutable::parse('2026-09-16 10:00'));
        $this->rechazar($pago);

        $cierre = $this->cerrarDia('2026-09-16');

        expect($cierre->disbursed_bank_deposits)->toBe('0.00')
            ->and($cierre->closing_bank_deposits)->toBe('20000.00')
            ->and($this->planilla($cierre)->expense)->toBe([]);
    }

    /**
     * Un cheque de la apertura está en el cajón aunque todavía no pague
     * ninguna multa, y la evidencia congela las columnas y la aclaración de
     * la columna de banco de Multas.
     */
    public function test_an_opening_cheque_without_a_fine_is_in_the_inventory(): void
    {
        $this->laCuenta = $this->cuenta();
        $this->cortar($this->laCuenta, '2026-05-31');

        app(OpenFineCashBook::class)->handle(
            date: CarbonImmutable::parse('2026-06-01'),
            bankAccountId: $this->laCuenta->id,
            balances: ['CHEQUES_IN_CUSTODY' => '5000.00'],
            denominations: [],
            cheques: [[
                'number' => 'AP-77', 'bank' => 'Banco Nación', 'issueDate' => '2026-05-20', 'amount' => '5000.00',
                'expediente' => '123/2025', 'company' => 'JUAN PÉREZ',
            ]],
            notes: null,
            declaredEmpty: false,
            actorId: $this->quienAbre(),
        );

        $this->travelTo(CarbonImmutable::parse('2026-06-02 10:00'));
        $cierre = $this->cerrarDia('2026-06-01');
        $planilla = $this->planilla($cierre);

        // La evidencia es JSONB: las claves vuelven en otro orden.
        expect($planilla->cheques)->toEqual([[
            'receipt' => '', 'expediente' => '123/2025', 'caption' => '', 'drawer' => 'JUAN PÉREZ',
            'number' => 'AP-77', 'bank' => 'Banco Nación', 'date' => '20/05/2026', 'amount' => '5000.00',
        ]])
            ->and($planilla->chequeColumns)->toEqual(FineCashBoxModule::CHEQUE_COLUMNS)
            ->and($planilla->bankColumnNote)->toContain('compartida con Aranceles');

        $payload = json_decode((string) DB::table('period_closing_evidence')->where('period_closing_id', $cierre->id)->value('payload'), true);
        expect($payload['schema'])->toBe(2)
            ->and($payload['chequeColumns'])->toEqual(FineCashBoxModule::CHEQUE_COLUMNS);
    }

    private function escenario(): Fine
    {
        $this->laCuenta = $this->cuenta();
        $this->cortar($this->laCuenta, '2026-05-31');
        $this->abrirMultas($this->laCuenta, '2026-06-01');

        $this->travelTo(CarbonImmutable::parse('2026-09-01 10:00'));
        $this->actingAs($this->operador())->post(route('multas.store'), [
            'caseNumber' => '169607/24-0',
            'resolution' => ['text' => 'N°0649/26', 'issuedOn' => '2026-05-07'],
            'acts' => [['actType' => 'HYS', 'text' => 'N°1089657', 'issuedOn' => null]],
            'caption' => 'CRISTOMAX S.A.S',
            'registeredOn' => '2026-05-07',
            'amount' => '100000.00',
        ])->assertSessionHasNoErrors();

        return Fine::query()->latest('id')->firstOrFail();
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

    private function depositar(FinePayment $pago): CashToBankTransfer
    {
        $this->actingAs($this->operador())->post(route('multas.pagos.deposito.store', $pago), [
            'depositDate' => BusinessDate::today()->toDateString(),
            'ticket' => UploadedFile::fake()->image('ticket.jpg'),
            'idempotencyKey' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();

        return CashToBankTransfer::query()->latest('id')->firstOrFail();
    }

    private function acreditar(CashToBankTransfer $traslado): void
    {
        $credito = $this->movimiento($this->laCuenta, $traslado->deposit_date->toDateString(), $traslado->amount);

        $this->actingAs($this->operador('contador'))->post(route('multas.traslados.confirm', $traslado), [
            'bankTransactionId' => $credito->id, 'idempotencyKey' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
    }

    private function rechazar(FinePayment $pago): void
    {
        $this->actingAs($this->operador('contador'))->post(route('multas.pagos.rechazo.store', $pago), [
            'rejectedOn' => BusinessDate::today()->toDateString(),
            'reason' => 'Sin fondos suficientes',
            'evidence' => UploadedFile::fake()->image('aviso.jpg'),
            'idempotencyKey' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
    }

    private function cerrarDia(string $fecha): PeriodClosing
    {
        $quien = User::query()->findOrFail($this->quienAbre());
        $caja = $this->cajaDe(CashBox::MULTAS);

        $this->arqueoListoParaCerrar($caja, $fecha, $quien);

        return app(ClosePeriod::class)->handle(
            cashBoxId: $caja,
            date: CarbonImmutable::parse($fecha),
            type: PeriodType::Daily,
            currency: Currency::Ars,
            actorId: $quien->id,
        );
    }

    private function planilla(PeriodClosing $cierre): CashSheet
    {
        return app(CashSheetBuilder::class)->build($cierre);
    }
}
