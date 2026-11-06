<?php

declare(strict_types=1);

namespace Tests\Feature\Multas;

use App\Models\User;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Ledger\Actions\ClosePeriod;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PeriodType;
use App\Modules\Ledger\Exceptions\ClosedPeriodException;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Ledger\Support\CashBalance;
use App\Modules\Ledger\Support\CashDayBook;
use App\Modules\Ledger\Support\InverseEntry;
use App\Modules\Multas\Actions\AdjustFineDebt;
use App\Modules\Multas\Actions\CollectFinePayment;
use App\Modules\Multas\Actions\RecordFineHistoricalMovement;
use App\Modules\Multas\Actions\RegisterFineAppeal;
use App\Modules\Multas\Actions\ReportFineBankPayment;
use App\Modules\Multas\Actions\ResolveFineAppeal;
use App\Modules\Multas\Actions\ReverseFineDebtEntry;
use App\Modules\Multas\Actions\RevertFineAppealVerdict;
use App\Modules\Multas\Actions\VoidFineCollection;
use App\Modules\Multas\Enums\AppealKind;
use App\Modules\Multas\Enums\FineAppealStatus;
use App\Modules\Multas\Enums\FineDebtStatus;
use App\Modules\Multas\Enums\FinePaymentMedium;
use App\Modules\Multas\Enums\FinePaymentStatus;
use App\Modules\Multas\Enums\FineReasonCode;
use App\Modules\Multas\Enums\HistoricalMovementKind;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineAppeal;
use App\Modules\Multas\Models\FineAppealVerdict;
use App\Modules\Multas\Models\FineBalance;
use App\Modules\Multas\Models\FineDebtEntry;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Multas\Support\PartialDate;
use App\Modules\Shared\Enums\ReceiptStatus;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Models\Receipt;
use App\Modules\Shared\Support\Reason;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\Concerns\BuildsSharedBankAccount;
use Tests\TestCase;

/**
 * Anular un cobro de la caja y revertir un veredicto (decisión del usuario,
 * 03/11/2026): cada regla en el Action y en la base.
 */
class AnularCobroYRevertirVeredictoTest extends TestCase
{
    use BuildsSharedBankAccount;
    use RefreshDatabase;

    private BankAccount $laCuenta;

    // ── Anular un cobro ─────────────────────────────────────────────────

    /** El dinero nunca entró: vuelve la deuda, sale de la caja y el recibo se anula con su número. */
    public function test_voiding_a_cash_collection_undoes_it_and_keeps_the_receipt_number(): void
    {
        $multa = $this->escenario();
        $pago = $this->cobrar($multa, '400000.00');
        $recibo = $this->reciboDe($pago);

        $this->anular($pago);

        expect($pago->refresh()->status)->toBe(FinePaymentStatus::Voided)
            ->and($this->saldo($multa, 'debt'))->toBe('1400000.00')
            ->and($this->saldo($multa, 'operational_paid'))->toBe('0.00')
            ->and($recibo->refresh()->status)->toBe(ReceiptStatus::Voided)
            ->and($recibo->void_reason)->toContain('Error de carga')
            ->and(FundReceipt::query()->findOrFail($pago->application()->firstOrFail()->fund_receipt_id)->reversal_event_id)->not->toBeNull()
            ->and($this->libro(LedgerAccount::CashOnHand))->toBe('0.00')
            ->and($this->libro(LedgerAccount::CollectedFunds))->toBe('0.00')
            ->and($this->libro(LedgerAccount::UnassignedFunds))->toBe('0.00');

        $nuevo = $this->cobrar($multa, '400000.00');
        expect($this->reciboDe($nuevo)->formatted_number)->not->toBe($recibo->formatted_number);
    }

    /** Anulado otro día: el ingreso sigue en su fecha y la reversión aparece en la suya. */
    public function test_voiding_another_day_keeps_the_day_book_explaining_each_day(): void
    {
        $multa = $this->escenario();
        $hoy = BusinessDate::today();
        $ayer = $hoy->subDay();
        $caja = (int) CashBox::query()->where('code', CashBox::MULTAS)->value('id');

        $this->travelTo($ayer->setTime(12, 0));
        $pago = $this->cobrar($multa, '400000.00');
        $numero = $this->reciboDe($pago)->talonario_number ?? $this->reciboDe($pago)->formatted_number;
        $this->travelBack();

        $this->anular($pago);

        $libro = app(CashDayBook::class);

        expect(array_column($libro->entries($caja, $ayer, $ayer)['income'], 'cash'))->toBe(['400000.00'])
            ->and(array_column($libro->entries($caja, $hoy, $hoy)['income'], 'cash'))->toBe(['-400000.00'])
            ->and($libro->entries($caja, $hoy, $hoy)['income'][0]['number'])->toBe('Anulación del recibo '.$numero);
    }

    /** Un segundo envío del mismo formulario no vuelve a revertir. */
    public function test_voiding_twice_is_a_single_void(): void
    {
        $pago = $this->cobrar($this->escenario(), '400000.00');

        $this->anular($pago);
        $this->anular($pago);

        expect(DB::table('fine_payment_voids')->count())->toBe(1);
    }

    /** El cheque en custodia sale del inventario. */
    public function test_voiding_a_cheque_takes_it_out_of_custody(): void
    {
        $multa = $this->escenario();
        $pago = app(CollectFinePayment::class)->handle(
            $multa, FinePaymentMedium::Cheque, '400000.00', (string) Str::uuid(), $this->quienAbre(),
            cheque: ['number' => 'A-0001', 'bank' => 'Banco Nación', 'issueDate' => null],
        );

        $this->anular($pago);

        expect($this->saldo($multa, 'debt'))->toBe('1400000.00')
            ->and($this->libro(LedgerAccount::ChequesInCustody))->toBe('0.00');
    }

    /** Con el excedente ya reclasificado por un ajuste, el saldo y el libro quedan coherentes. */
    public function test_voiding_after_an_excess_reclassification_keeps_balance_and_ledger(): void
    {
        $multa = $this->escenario();
        $pago = $this->cobrar($multa, '1400000.00');
        $this->corregirImporte($multa, '1000000.00');

        expect($this->saldo($multa, 'operational_excess'))->toBe('400000.00')
            ->and($this->libro(LedgerAccount::PendingExcess))->toBe('400000.00');

        $this->anular($pago);
        $this->verificarPendientes();

        expect($this->saldo($multa, 'debt'))->toBe('1000000.00')
            ->and($this->saldo($multa, 'operational_excess'))->toBe('0.00')
            ->and($this->libro(LedgerAccount::PendingExcess))->toBe('0.00')
            ->and($this->libro(LedgerAccount::CollectedFunds))->toBe('0.00');
    }

    /** Anular uno de dos cobros: lo que el otro traía de más vuelve a aplicarse. */
    public function test_voiding_one_of_two_collections_reabsorbs_the_excess_of_the_other(): void
    {
        $multa = $this->escenario();
        $primero = $this->cobrar($multa, '1000000.00');
        $this->cobrar($multa, '600000.00');

        expect($this->saldo($multa, 'operational_excess'))->toBe('200000.00');

        $this->anular($primero);
        $this->verificarPendientes();

        expect($this->saldo($multa, 'debt'))->toBe('800000.00')
            ->and($this->saldo($multa, 'operational_excess'))->toBe('0.00')
            ->and($this->libro(LedgerAccount::PendingExcess))->toBe('0.00')
            ->and($this->libro(LedgerAccount::CollectedFunds))->toBe('600000.00');
    }

    /** Lo depositado sí entró; cancelado el depósito, vuelve a la caja y se puede anular. */
    public function test_a_deposited_collection_is_not_voided_until_the_deposit_is_cancelled(): void
    {
        $multa = $this->escenario();
        $pago = $this->cobrar($multa, '400000.00');
        $traslado = $this->depositar($pago);

        $this->rechazaElAction('reason', fn () => $this->anular($pago));

        $this->actingAs($this->operador('contador'))->post(route('multas.traslados.cancel', $traslado), [
            'reasonCode' => 'data_entry_error', 'reason' => 'Se cargó mal el ticket', 'idempotencyKey' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();

        $this->anular($pago);

        expect($pago->refresh()->status)->toBe(FinePaymentStatus::Voided);
    }

    /** Lo informado se descarta; lo verificado contra el banco queda fuera de esta anulación. */
    public function test_only_counter_collections_are_voided(): void
    {
        $multa = $this->escenario();
        $informado = $this->informar($multa, '100000.00');

        $this->rechazaElAction('reason', fn () => $this->anular($informado));

        $this->verificar($informado, '100000.00');
        $this->rechazaElAction('reason', fn () => $this->anular($informado));
    }

    /** Anular el cobro de un recurso en trámite lo hace caer, y la multa admite otro. */
    public function test_voiding_the_collection_of_an_open_appeal_drops_it(): void
    {
        $multa = $this->escenario();
        $pago = $this->recurrir($multa, FinePaymentMedium::Cash, '280000.00');

        $this->anular($pago);

        expect(FineAppeal::query()->sole()->status)->toBe(FineAppealStatus::Dropped)
            ->and($this->estado($multa))->toBe(FineDebtStatus::Pending);

        $this->recurrir($multa, FinePaymentMedium::Cash, '280000.00');
        expect(FineAppeal::query()->where('status', FineAppealStatus::Open)->count())->toBe(1);
    }

    /** Con veredicto, primero se revierte: se dio sobre ese dinero. */
    public function test_a_collection_with_a_verdict_is_not_voided(): void
    {
        $multa = $this->escenario();
        $pago = $this->recurrir($multa, FinePaymentMedium::Cash, '280000.00');
        $this->resolver($multa, FineAppealStatus::Rejected);

        $this->rechazaElAction('reason', fn () => $this->anular($pago));

        $this->revertir($multa);
        $this->anular($pago);

        expect(FineAppeal::query()->sole()->status)->toBe(FineAppealStatus::Dropped);
    }

    /** Anular va con su propio permiso, y la ficha lo ofrece solo a quien lo tiene y donde corresponde. */
    public function test_voiding_needs_its_permission_and_the_screen_offers_it(): void
    {
        $multa = $this->escenario();
        $pago = $this->cobrar($multa, '400000.00');
        $cuerpo = ['reasonCode' => 'data_entry_error', 'reason' => 'Se tipeó mal'];

        $this->actingAs($this->operador())->post(route('multas.pagos.void', $pago), $cuerpo)->assertForbidden();

        $this->actingAs($this->operador('contador'))
            ->get(route('multas.show', $multa->showParameters()))
            ->assertInertia(fn (Assert $page) => $page
                ->where('can.voidCollection', true)
                ->where('multa.payments.0.voidable', true));

        $this->actingAs($this->operador('contador'))->post(route('multas.pagos.void', $pago), [])->assertSessionHasErrors('reasonCode');
        $this->actingAs($this->operador('contador'))->post(route('multas.pagos.void', $pago), $cuerpo)->assertSessionHasNoErrors();

        $this->actingAs($this->operador('contador'))
            ->get(route('multas.show', $multa->showParameters()))
            ->assertInertia(fn (Assert $page) => $page
                ->where('multa.payments.0.status', 'voided')
                ->where('multa.payments.0.voidable', false)
                ->where('multa.payments.0.voidReason', fn (string $motivo): bool => str_contains($motivo, 'Se tipeó mal')));
    }

    /** El cierre del día del cobro no cambia: la corrección queda en el día abierto; con ese cerrado, no entra. */
    public function test_voiding_after_the_closing_keeps_the_evidence_and_lands_on_an_open_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00'));
        $multa = $this->escenario();
        $pago = $this->cobrar($multa, '400000.00');
        $caja = $this->cajaDe(CashBox::MULTAS);
        $this->cerrarDia('2026-09-15');
        $hastaElCierre = fn (): string => app(CashBalance::class)->of(LedgerAccount::CashOnHand, $caja, Currency::Ars, CarbonImmutable::parse('2026-09-15'));

        expect($hastaElCierre())->toBe('400000.00');

        $this->travelTo(CarbonImmutable::parse('2026-09-16 10:00'));
        $this->anular($pago);

        $revertida = DB::table('financial_events')->whereNotNull('reversal_of_id')->orderByDesc('id')->value('event_date');

        expect($hastaElCierre())->toBe('400000.00')
            ->and((string) $revertida)->toStartWith('2026-09-16')
            ->and($this->libro(LedgerAccount::CashOnHand))->toBe('0.00');

        $otro = $this->cobrar($multa, '100000.00');
        $this->cerrarDia('2026-09-16');

        $this->expectException(ClosedPeriodException::class);
        $this->anular($otro);
    }

    /** Movimientos: el cobro sigue en su mes, marcado; la anulación resta en el suyo. */
    public function test_movements_keep_the_voided_collection_in_its_month(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00'));
        $multa = $this->escenario();
        $pago = $this->cobrar($multa, '400000.00');
        $mismoMes = $this->cobrar($multa, '100000.00');
        $this->anular($mismoMes);

        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00'));
        $this->anular($pago);

        $this->actingAs($this->operador('consulta'))
            ->get(route('multas.movimientos.index', ['mes' => '2026-09']))
            ->assertInertia(function (Assert $page): void {
                $secciones = collect($page->toArray()['props']['sections'])->keyBy('key');

                expect($secciones['cobros']['total'])->toBe('400000.00')
                    ->and($secciones['cobros']['count'])->toBe(3)
                    ->and($secciones['cobros']['rows'][0]['detail'])->toContain('anulado el 05/10/2026')
                    ->and($secciones['aplicaciones']['total'])->toBe('400000.00');
            });

        $this->actingAs($this->operador('consulta'))
            ->get(route('multas.movimientos.index', ['mes' => '2026-10']))
            ->assertInertia(function (Assert $page): void {
                $secciones = collect($page->toArray()['props']['sections'])->keyBy('key');

                expect($secciones['cobros']['total'])->toBe('-400000.00')
                    ->and($secciones['cobros']['rows'][0]['detail'])->toContain('Anulación del cobro')
                    ->and($secciones['cobros']['rows'][0]['detail'])->toContain('Error de carga')
                    ->and($secciones['aplicaciones']['total'])->toBe('-400000.00');
            });
    }

    /** Un cheque anulado no se deposita ni sigue entre los que están en custodia. */
    public function test_a_voided_cheque_is_not_offered_for_deposit(): void
    {
        $multa = $this->escenario();
        $pago = app(CollectFinePayment::class)->handle(
            $multa, FinePaymentMedium::Cheque, '400000.00', (string) Str::uuid(), $this->quienAbre(),
            cheque: ['number' => 'A-0003', 'bank' => null, 'issueDate' => null],
        );
        $this->anular($pago);

        $this->actingAs($this->operador())->post(route('multas.pagos.deposito.store', $pago), [
            'depositDate' => BusinessDate::today()->toDateString(),
            'ticket' => UploadedFile::fake()->image('ticket.jpg'),
            'idempotencyKey' => (string) Str::uuid(),
        ])->assertSessionHasErrors('receiptId');

        expect(FundReceipt::query()->whereNull('reversal_event_id')->where('medium', 'cheque')->exists())->toBeFalse();
    }

    // ── La base, sin pasar por el Action ────────────────────────────────

    /** Una anulación exige las reversiones de sus dos asientos. */
    public function test_the_database_requires_both_reversals_to_void(): void
    {
        $multa = $this->escenario();
        $pago = $this->cobrar($multa, '400000.00');
        $ajeno = $this->cobrar($multa, '100000.00')->application()->firstOrFail()->application_event_id;

        $this->rechazaLaBase('se asienta revirtiendo', fn () => DB::table('fine_payment_voids')->insert([
            'fine_payment_id' => $pago->id, 'fine_id' => $pago->fine_id, 'reason_code' => 'data_entry_error',
            'application_reversal_event_id' => $this->reversion($ajeno),
            'funds_exit_event_id' => $this->reversion($pago->application()->firstOrFail()->fundReceipt()->firstOrFail()->financial_event_id),
        ]));
    }

    /** Depositado, la base no deja anular aunque las reversiones estén. */
    public function test_the_database_refuses_voiding_a_deposited_collection(): void
    {
        $pago = $this->cobrar($this->escenario(), '400000.00');
        $this->depositar($pago);

        $this->rechazaLaBase('ya se depositó', fn () => $this->anulacionDirecta($pago));
    }

    /** El pago no queda anulado sin su anulación, ni con el recibo vigente. */
    public function test_the_database_keeps_voided_payments_settled(): void
    {
        $pago = $this->cobrar($this->escenario(), '400000.00');
        $this->verificarPendientes();

        $this->rechazaLaBase('sin su anulación', fn () => DB::table('fine_payments')->where('id', $pago->id)->update(['status' => 'voided']));

        $this->rechazaLaBase('no revirtió su recepción', function () use ($pago): void {
            $this->anulacionDirecta($pago);
            DB::table('fine_payments')->where('id', $pago->id)->update(['status' => 'voided']);
        });

        $this->rechazaLaBase('no lo dejó anulado', fn () => $this->anulacionDirecta($pago));
    }

    /** El recibo de un pago vigente no se anula solo, ni la recepción se revierte sin anulación. */
    public function test_the_database_keeps_the_receipt_and_the_money_with_the_payment(): void
    {
        $pago = $this->cobrar($this->escenario(), '400000.00');
        $recibo = $this->reciboDe($pago);
        $aplicacion = $pago->application()->firstOrFail();
        $recepcion = FundReceipt::query()->findOrFail($aplicacion->fund_receipt_id);
        $this->verificarPendientes();

        $this->rechazaLaBase('anulando el cobro', fn () => DB::table('receipts')->where('id', $recibo->id)->update([
            'status' => 'voided', 'void_reason_code' => 'other', 'void_reason' => 'Directo', 'voided_by' => $this->quienAbre(), 'voided_at' => now(),
        ]));

        $this->rechazaLaBase('sin anular el cobro', fn () => DB::table('fund_receipts')->where('id', $recepcion->id)->update([
            'reversal_reason_code' => 'other', 'reversal_event_id' => $this->reversion($recepcion->financial_event_id),
            'reversed_at' => now(), 'reversed_by' => $this->quienAbre(), 'reversal_reason' => 'Directo',
        ]));
    }

    /** Un pago anulado no vuelve: el estado es final. */
    public function test_the_database_keeps_a_voided_payment_final(): void
    {
        $pago = $this->cobrar($this->escenario(), '400000.00');
        $this->anular($pago);

        $this->rechazaLaBase('no pasa a', fn () => DB::table('fine_payments')->where('id', $pago->id)->update(['status' => 'applied']));
        $this->rechazaLaBase('no se edita ni se borra', fn () => DB::table('fine_payment_voids')->where('fine_payment_id', $pago->id)->delete());
    }

    // ── Revertir un veredicto ───────────────────────────────────────────

    /** Prosperó con reducción: la reducción se revierte, la multa vuelve a congelarse y se resuelve de nuevo. */
    public function test_reverting_an_upheld_verdict_restores_the_amount_and_freezes_the_fine(): void
    {
        $multa = $this->escenario();
        $this->recurrir($multa, FinePaymentMedium::Cash, '280000.00');
        $this->resolver($multa, FineAppealStatus::Upheld);

        expect($this->saldo($multa, 'obligation'))->toBe('280000.00')
            ->and($this->estado($multa))->toBe(FineDebtStatus::Cancelled);

        $this->revertir($multa);

        $veredicto = FineAppealVerdict::query()->sole();
        $reversion = FineDebtEntry::query()->whereNotNull('fine_appeal_verdict_id')->where('kind', 'reversal')->sole();

        expect(FineAppeal::query()->sole()->status)->toBe(FineAppealStatus::Open)
            ->and($this->saldo($multa, 'obligation'))->toBe('1400000.00')
            ->and($this->saldo($multa, 'debt'))->toBe('1120000.00')
            ->and($this->estado($multa))->toBe(FineDebtStatus::InAppeal)
            ->and($veredicto->reverted_at)->not->toBeNull()
            ->and($veredicto->verdict)->toBe(FineAppealStatus::Upheld)
            ->and($reversion->fine_appeal_verdict_id)->toBe($veredicto->id)
            ->and($reversion->amount)->toBe('1120000.00');

        $this->rechazaElAction('amount', fn () => $this->cobrar($multa, '1000.00'));

        $this->resolver($multa, FineAppealStatus::Rejected);
        expect(FineAppeal::query()->sole()->status)->toBe(FineAppealStatus::Rejected);
    }

    /** Si cubría la multa no hubo reducción: revertir no la inventa y el excedente sigue a la vista. */
    public function test_reverting_an_upheld_verdict_without_reduction(): void
    {
        $multa = $this->escenario();
        $this->recurrir($multa, FinePaymentMedium::Cash, '1500000.00');
        $this->resolver($multa, FineAppealStatus::Upheld);

        $this->revertir($multa);

        expect(FineDebtEntry::query()->whereNotNull('fine_appeal_verdict_id')->exists())->toBeFalse()
            ->and($this->saldo($multa, 'excess'))->toBe('100000.00')
            ->and(FineAppeal::query()->sole()->status)->toBe(FineAppealStatus::Open);
    }

    public function test_reverting_a_rejected_verdict_freezes_the_fine_again(): void
    {
        $multa = $this->escenario();
        $this->recurrir($multa, FinePaymentMedium::Cash, '280000.00');
        $this->resolver($multa, FineAppealStatus::Rejected);

        $this->actingAs($this->operador())
            ->post(route('multas.recursos.revert', $multa), [
                'verdictId' => FineAppealVerdict::query()->sole()->id,
                'reasonCode' => 'data_entry_error',
                'reason' => 'Era el de otra multa',
            ])
            ->assertSessionHasNoErrors();

        expect($this->estado($multa))->toBe(FineDebtStatus::InAppeal);
        $this->rechazaElAction('amount', fn () => $this->cobrar($multa, '1000.00'));
    }

    /** Varias vueltas: cada reducción queda vinculada a su veredicto y se revierte una sola vez. */
    public function test_resolving_and_reverting_several_times_keeps_each_reduction_with_its_verdict(): void
    {
        $multa = $this->escenario();
        $this->recurrir($multa, FinePaymentMedium::Cash, '280000.00');

        foreach ([1, 2, 3] as $vuelta) {
            $this->resolver($multa, FineAppealStatus::Upheld);
            $this->verificarPendientes();
            expect($this->saldo($multa, 'obligation'))->toBe('280000.00');

            $this->revertir($multa);
            $this->verificarPendientes();
            expect($this->saldo($multa, 'obligation'))->toBe('1400000.00');
        }

        $this->resolver($multa, FineAppealStatus::Upheld);
        $this->verificarPendientes();

        $veredictos = FineAppealVerdict::query()->orderBy('id')->get();
        $reducciones = FineDebtEntry::query()->where('kind', 'reduction')->whereNotNull('fine_appeal_verdict_id')->get();
        $reversiones = FineDebtEntry::query()->where('kind', 'reversal')->whereNotNull('fine_appeal_verdict_id')->get();

        expect($veredictos)->toHaveCount(4)
            ->and($veredictos->whereNull('reverted_at'))->toHaveCount(1)
            ->and($reducciones->pluck('fine_appeal_verdict_id')->unique())->toHaveCount(4)
            ->and($reversiones)->toHaveCount(3)
            ->and($reversiones->pluck('reversal_of_id')->unique())->toHaveCount(3)
            ->and($this->saldo($multa, 'obligation'))->toBe('280000.00')
            ->and($this->saldo($multa, 'debt'))->toBe('0.00');
    }

    /** Un formulario viejo no revierte el veredicto que se dio después. */
    public function test_a_stale_form_does_not_revert_a_newer_verdict(): void
    {
        $multa = $this->escenario();
        $this->recurrir($multa, FinePaymentMedium::Cash, '280000.00');
        $this->resolver($multa, FineAppealStatus::Rejected);
        $visto = FineAppealVerdict::query()->sole()->id;
        $this->revertir($multa);
        $this->resolver($multa, FineAppealStatus::Upheld);

        $this->rechazaElAction('reason', fn () => app(RevertFineAppealVerdict::class)->handle(
            $multa, $visto, new Reason(FineReasonCode::DataEntryError, 'Formulario viejo'), $this->quienAbre(),
        ));

        expect(FineAppeal::query()->sole()->status)->toBe(FineAppealStatus::Upheld);
    }

    /** Un cobro posterior en la caja: se anula y recién ahí se revierte. */
    public function test_a_later_counter_collection_blocks_the_revert_until_voided(): void
    {
        $multa = $this->escenario();
        $this->recurrir($multa, FinePaymentMedium::Cash, '280000.00');
        $this->resolver($multa, FineAppealStatus::Rejected);
        $otro = $this->cobrar($multa, '100000.00');

        $this->rechazaConMensaje('anulalo', fn () => $this->revertir($multa));

        $this->anular($otro);
        $this->revertir($multa);

        expect(FineAppeal::query()->sole()->status)->toBe(FineAppealStatus::Open);
    }

    /** Un informado posterior: se descarta y recién ahí se revierte. */
    public function test_a_later_reported_payment_blocks_the_revert_until_discarded(): void
    {
        $multa = $this->escenario();
        $this->recurrir($multa, FinePaymentMedium::Cash, '280000.00');
        $this->resolver($multa, FineAppealStatus::Rejected);
        $informado = $this->informar($multa, '100000.00');

        $this->rechazaConMensaje('descartalo', fn () => $this->revertir($multa));

        $this->actingAs($this->operador('contador'))
            ->post(route('multas.pagos.discard', $informado), ['reasonCode' => 'not_credited', 'reason' => 'No llegó'])
            ->assertSessionHasNoErrors();
        $this->revertir($multa);

        expect(FineAppeal::query()->sole()->status)->toBe(FineAppealStatus::Open);
    }

    /** Un pago verificado se anula con «Corregir pago»: el mensaje lo dice. */
    public function test_a_later_bank_payment_blocks_the_revert_and_says_why(): void
    {
        $multa = $this->escenario();
        $this->recurrir($multa, FinePaymentMedium::Cash, '280000.00');
        $this->resolver($multa, FineAppealStatus::Rejected);
        $this->verificar($this->informar($multa, '100000.00'), '100000.00');

        $this->rechazaConMensaje('Corregir pago', fn () => $this->revertir($multa));
    }

    /** Un cobro ya depositado no tiene corrección todavía: el mensaje lo dice. */
    public function test_a_later_deposited_collection_blocks_the_revert_and_says_why(): void
    {
        $multa = $this->escenario();
        $this->recurrir($multa, FinePaymentMedium::Cash, '280000.00');
        $this->resolver($multa, FineAppealStatus::Rejected);
        $this->depositar($this->cobrar($multa, '50000.00'));

        $this->rechazaConMensaje('todavía no está disponible', fn () => $this->revertir($multa));
    }

    public function test_a_later_historical_movement_blocks_the_revert(): void
    {
        $multa = $this->escenario();
        $this->recurrir($multa, FinePaymentMedium::Cash, '280000.00');
        $this->resolver($multa, FineAppealStatus::Rejected);
        app(RecordFineHistoricalMovement::class)->handle(
            $multa, HistoricalMovementKind::Payment, '1000.00', PartialDate::parse('2026-05'), 'Planilla', $this->quienAbre(),
        );

        $this->rechazaConMensaje('rechazalo', fn () => $this->revertir($multa));
    }

    /** Un ajuste posterior: revertido, ya no bloquea. */
    public function test_a_later_adjustment_blocks_the_revert_until_reversed(): void
    {
        $multa = $this->escenario();
        $this->recurrir($multa, FinePaymentMedium::Cash, '280000.00');
        $this->resolver($multa, FineAppealStatus::Rejected);
        $ajuste = $this->corregirImporte($multa, '1000000.00');

        $this->rechazaConMensaje('revertí ese ajuste', fn () => $this->revertir($multa));

        app(ReverseFineDebtEntry::class)->handle($ajuste, new Reason(FineReasonCode::DataEntryError, 'Se revierte'), $this->quienAbre());
        $this->revertir($multa);

        expect(FineAppeal::query()->sole()->status)->toBe(FineAppealStatus::Open);
    }

    /** La ficha informa el veredicto vigente, y «Resolver» depende del pago aplicado. */
    public function test_the_screen_shows_the_verdict_and_when_it_can_be_resolved(): void
    {
        $multa = $this->escenario();
        $pago = $this->recurrir($multa, FinePaymentMedium::DirectDeposit, '280000.00');

        $this->actingAs($this->operador())
            ->get(route('multas.show', $multa->showParameters()))
            ->assertInertia(fn (Assert $page) => $page
                ->where('recurso.paymentApplied', false)
                ->where('recurso.verdictId', null));

        $this->verificar($pago, '280000.00');
        $this->resolver($multa, FineAppealStatus::Rejected);

        $this->actingAs($this->operador())
            ->get(route('multas.show', $multa->showParameters()))
            ->assertInertia(fn (Assert $page) => $page
                ->where('recurso.paymentApplied', true)
                ->where('recurso.verdictId', FineAppealVerdict::query()->sole()->id)
                ->where('can.revertVerdict', true));
    }

    // ── La base, con los veredictos ─────────────────────────────────────

    public function test_the_database_keeps_one_current_verdict(): void
    {
        $multa = $this->escenario();
        $recurso = (int) $this->recurrir($multa, FinePaymentMedium::Cash, '280000.00')->fine_appeal_id;

        $this->rechazaLaBase('sin resolver no tiene un veredicto vigente', fn () => $this->veredictoDirecto($multa, $recurso, 'rejected'));

        $this->resolver($multa, FineAppealStatus::Rejected);

        $this->rechazaLaBase('sobre un recurso en trámite', fn () => $this->veredictoDirecto($multa, $recurso, 'rejected'));
        $this->rechazaLaBase('no se borra', fn () => DB::table('fine_appeal_verdicts')->where('fine_appeal_id', $recurso)->delete());
        $this->rechazaLaBase('solo se completa su reversión', fn () => DB::table('fine_appeal_verdicts')->where('fine_appeal_id', $recurso)->update(['verdict' => 'upheld']));
    }

    /** La reducción del veredicto no se revierte sola, ni con una reversión común. */
    public function test_the_database_keeps_the_reduction_of_a_current_verdict(): void
    {
        $multa = $this->escenario();
        $this->recurrir($multa, FinePaymentMedium::Cash, '280000.00');
        $this->resolver($multa, FineAppealStatus::Upheld);
        $reduccion = FineDebtEntry::query()->where('kind', 'reduction')->whereNotNull('fine_appeal_verdict_id')->sole();

        $fila = [
            'fine_id' => $multa->id, 'kind' => 'reversal', 'amount' => $reduccion->amount, 'effective_on' => '2026-06-02',
            'effective_precision' => 'day', 'reversal_of_id' => $reduccion->id, 'provenance' => 'manual', 'created_at' => now(),
        ];

        $this->rechazaLaBase('se revierte solo revirtiendo el veredicto', fn () => DB::table('fine_debt_entries')->insert([
            ...$fila, 'fine_appeal_verdict_id' => $reduccion->fine_appeal_verdict_id, 'reason_code' => 'appeal_verdict_reverted', 'reason' => 'Directo',
        ]));

        // Un veredicto revertido deja revertida su reducción.
        $this->rechazaLaBase('deja revertida su reducción', function () use ($reduccion): void {
            DB::table('fine_appeal_verdicts')->where('id', $reduccion->fine_appeal_verdict_id)->update([
                'reverted_at' => now(), 'revert_reason_code' => 'data_entry_error',
            ]);
            DB::table('fine_appeals')->update(['status' => 'open']);
        });
    }

    /** Volver a trámite con operaciones posteriores no pasa ni escribiendo directo. */
    public function test_the_database_refuses_refreezing_after_other_operations(): void
    {
        $multa = $this->escenario();
        $recurso = (int) $this->recurrir($multa, FinePaymentMedium::Cash, '280000.00')->fine_appeal_id;
        $this->resolver($multa, FineAppealStatus::Rejected);
        $this->cobrar($multa, '100000.00');

        $this->rechazaLaBase('tuvo otras operaciones', function () use ($recurso): void {
            DB::table('fine_appeal_verdicts')->where('fine_appeal_id', $recurso)->update([
                'reverted_at' => now(), 'revert_reason_code' => 'data_entry_error',
            ]);
            DB::table('fine_appeals')->where('id', $recurso)->update(['status' => 'open']);
        });
    }

    // ── Migración ───────────────────────────────────────────────────────

    /** Un recurso resuelto pasa a una fila de veredicto y su reducción a citarla; ida, vuelta e ida. */
    public function test_the_migration_converts_resolved_appeals(): void
    {
        $multa = $this->escenario();
        $this->recurrir($multa, FinePaymentMedium::Cash, '280000.00');
        $this->resolver($multa, FineAppealStatus::Upheld);
        $resuelto = FineAppealVerdict::query()->sole();

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $migracion = require database_path('migrations/2026_11_04_000000_add_fine_payment_voids_and_appeal_verdicts.php');
        $migracion->down();

        expect(DB::table('fine_appeals')->value('resolved_at'))->not->toBeNull()
            ->and(DB::table('fine_debt_entries')->whereNotNull('fine_appeal_id')->count())->toBe(1);

        // Como corre `artisan migrate`: con las verificaciones diferidas.
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $migracion->up();

        $veredicto = FineAppealVerdict::query()->sole();
        expect($veredicto->verdict)->toBe(FineAppealStatus::Upheld)
            ->and($veredicto->resolved_by)->toBe($resuelto->resolved_by)
            ->and(FineDebtEntry::query()->where('kind', 'reduction')->whereNotNull('fine_appeal_verdict_id')->sole()->fine_appeal_verdict_id)->toBe($veredicto->id);

        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $this->revertir($multa);
        expect($this->saldo($multa, 'obligation'))->toBe('1400000.00');
    }

    /** Un ajuste posterior al veredicto, todavía vigente, sigue impidiendo revertirlo después de migrar. */
    public function test_the_migration_keeps_later_adjustments_after_the_verdict(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00:00'));
        $multa = $this->escenario();
        $anterior = $this->corregirImporte($multa, '1500000.00');

        $this->travelTo(CarbonImmutable::parse('2026-09-15 11:00:00'));
        $this->recurrir($multa, FinePaymentMedium::Cash, '300000.00');
        $this->resolver($multa, FineAppealStatus::Rejected);

        $this->travelTo(CarbonImmutable::parse('2026-09-15 12:00:00'));
        $posterior = $this->corregirImporte($multa, '1600000.00');

        $this->migrarDeNuevo();

        expect(FineAppealVerdict::query()->sole()->debt_entries_until_id)->toBe($anterior->id);
        $this->rechazaConMensaje('revertí ese ajuste', fn () => $this->revertir($multa));

        app(ReverseFineDebtEntry::class)->handle($posterior, new Reason(FineReasonCode::DataEntryError, 'Se revierte'), $this->quienAbre());
        $this->revertir($multa);

        expect(FineAppeal::query()->sole()->status)->toBe(FineAppealStatus::Open);
    }

    /** Sin nada después del veredicto, lo anterior a él no impide revertirlo. */
    public function test_the_migration_keeps_earlier_adjustments_before_the_verdict(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00:00'));
        $multa = $this->escenario();
        $anterior = $this->corregirImporte($multa, '1500000.00');

        $this->travelTo(CarbonImmutable::parse('2026-09-15 11:00:00'));
        $this->recurrir($multa, FinePaymentMedium::Cash, '300000.00');
        $this->resolver($multa, FineAppealStatus::Rejected);

        $this->migrarDeNuevo();

        expect(FineAppealVerdict::query()->sole()->debt_entries_until_id)->toBe($anterior->id);
        $this->revertir($multa);

        expect(FineAppeal::query()->sole()->status)->toBe(FineAppealStatus::Open);
    }

    /** Un prosperó no admitía movimientos después: lo del mismo segundo es anterior y no frena la migración. */
    public function test_the_migration_is_not_ambiguous_for_an_upheld_verdict(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00:00'));
        $multa = $this->escenario();
        $anterior = $this->corregirImporte($multa, '1500000.00');
        $this->recurrir($multa, FinePaymentMedium::Cash, '300000.00');
        $this->resolver($multa, FineAppealStatus::Upheld);

        $this->migrarDeNuevo();

        expect(FineAppealVerdict::query()->sole()->debt_entries_until_id)->toBe($anterior->id);
        $this->revertir($multa);

        expect($this->saldo($multa, 'obligation'))->toBe('1500000.00');
    }

    /** Un movimiento del mismo segundo que un «no prosperó» no se sabe de qué lado cae: se frena y lo nombra. */
    public function test_the_migration_stops_on_an_adjustment_in_the_same_second_as_a_rejected_verdict(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-15 10:00:00'));
        $multa = $this->escenario();
        $this->recurrir($multa, FinePaymentMedium::Cash, '280000.00');
        $this->resolver($multa, FineAppealStatus::Rejected);
        $this->corregirImporte($multa, '1500000.00');

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $migracion = require database_path('migrations/2026_11_04_000000_add_fine_payment_voids_and_appeal_verdicts.php');
        $migracion->down();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("mismo segundo que el veredicto (multas {$multa->id})");

        $migracion->up();
    }

    /** Un recurso resuelto con el depósito sin verificar contradice la regla nueva: se frena y lo nombra. */
    public function test_the_migration_stops_on_a_verdict_without_its_payment_applied(): void
    {
        $multa = $this->escenario();
        $this->recurrir($multa, FinePaymentMedium::DirectDeposit, '280000.00');

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $migracion = require database_path('migrations/2026_11_04_000000_add_fine_payment_voids_and_appeal_verdicts.php');
        $migracion->down();
        DB::table('fine_appeals')->update(['status' => 'rejected', 'resolved_at' => now()]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("multas {$multa->id}");

        $migracion->up();
    }

    public function test_the_migration_refuses_to_roll_back_a_void(): void
    {
        $this->anular($this->cobrar($this->escenario(), '400000.00'));

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $migracion = require database_path('migrations/2026_11_04_000000_add_fine_payment_voids_and_appeal_verdicts.php');

        $this->expectException(RuntimeException::class);
        $migracion->down();
    }

    // ── Escenario ───────────────────────────────────────────────────────

    /** Baja y vuelve a subir la migración, como `artisan migrate`, y deja diferido lo que sigue. */
    private function migrarDeNuevo(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $migracion = require database_path('migrations/2026_11_04_000000_add_fine_payment_voids_and_appeal_verdicts.php');
        $migracion->down();
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $migracion->up();
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    private function escenario(): Fine
    {
        $this->laCuenta = $this->cuenta();
        $this->abrirMultas($this->laCuenta, '2026-06-01');

        return $this->multa();
    }

    private function multa(string $expediente = '169607/24-0'): Fine
    {
        $this->actingAs($this->operador())->post(route('multas.store'), [
            'caseNumber' => $expediente,
            'resolution' => ['text' => 'N°0649/26', 'issuedOn' => '2026-05-07'],
            'acts' => [['actType' => 'HYS', 'text' => 'N°1089657', 'issuedOn' => null]],
            'caption' => 'CRISTOMAX S.A.S',
            'registeredOn' => '2026-05-07',
            'amount' => '1400000.00',
        ])->assertSessionHasNoErrors();

        return Fine::query()->latest('id')->firstOrFail();
    }

    private function cobrar(Fine $multa, string $importe): FinePayment
    {
        return app(CollectFinePayment::class)->handle($multa, FinePaymentMedium::Cash, $importe, (string) Str::uuid(), $this->quienAbre());
    }

    private function anular(FinePayment $pago): void
    {
        app(VoidFineCollection::class)->handle($pago, new Reason(FineReasonCode::DataEntryError, 'Se tipeó mal'), $this->quienAbre());
    }

    private function recurrir(Fine $multa, FinePaymentMedium $medio, string $externo): FinePayment
    {
        return app(RegisterFineAppeal::class)->handle(
            $multa, AppealKind::Hierarchical, $medio, 'R-2026-0042', $externo, (string) Str::uuid(), $this->quienAbre(),
        );
    }

    private function resolver(Fine $multa, FineAppealStatus $veredicto): void
    {
        $recurso = FineAppeal::query()->where('fine_id', $multa->id)->where('status', FineAppealStatus::Open)->sole();

        app(ResolveFineAppeal::class)->handle(
            $multa, $veredicto, $recurso->id, $recurso->payments()->live()->sole()->id, $this->quienAbre(),
        );
    }

    private function revertir(Fine $multa): void
    {
        app(RevertFineAppealVerdict::class)->handle(
            $multa,
            FineAppealVerdict::query()->where('fine_id', $multa->id)->whereNull('reverted_at')->sole()->id,
            new Reason(FineReasonCode::DataEntryError, 'Se cargó mal el veredicto'),
            $this->quienAbre(),
        );
    }

    private function informar(Fine $multa, string $importe): FinePayment
    {
        return app(ReportFineBankPayment::class)->handle(
            $multa, FinePaymentMedium::BankTransfer, $importe, (string) Str::uuid(), $this->quienAbre(),
        );
    }

    private function verificar(FinePayment $pago, string $importe): void
    {
        $credito = $this->movimiento($this->laCuenta, BusinessDate::today()->toDateString(), $importe);

        $this->actingAs($this->operador('contador'))
            ->post(route('multas.pagos.verify', $pago), ['bankTransactionId' => $credito->id])
            ->assertSessionHasNoErrors();
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

    private function corregirImporte(Fine $multa, string $nuevo): FineDebtEntry
    {
        return app(AdjustFineDebt::class)->handle(
            $multa,
            $nuevo,
            (string) FineBalance::query()->whereKey($multa->id)->value('obligation'),
            BusinessDate::today(),
            null,
            new Reason(FineReasonCode::DataEntryError, 'El importe estaba mal cargado'),
            $this->quienAbre(),
        );
    }

    private function cerrarDia(string $fecha): void
    {
        $quien = User::query()->findOrFail($this->quienAbre());
        $caja = $this->cajaDe(CashBox::MULTAS);

        $this->arqueoListoParaCerrar($caja, $fecha, $quien);

        app(ClosePeriod::class)->handle(
            cashBoxId: $caja,
            date: CarbonImmutable::parse($fecha),
            type: PeriodType::Daily,
            currency: Currency::Ars,
            actorId: $quien->id,
        );
    }

    /** Lo que correría al confirmar, sin salir de la transacción del test. */
    private function verificarPendientes(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    private function reciboDe(FinePayment $pago): Receipt
    {
        return $pago->paymentReceipt()->firstOrFail()->receipt()->firstOrFail();
    }

    private function saldo(Fine $multa, string $columna): string
    {
        return (string) DB::table('fine_balances')->where('fine_id', $multa->id)->value($columna);
    }

    private function estado(Fine $multa): FineDebtStatus
    {
        $saldo = FineBalance::query()->whereKey($multa->id)->firstOrFail();

        return FineDebtStatus::of($saldo->obligation, $saldo->debt, $saldo->appeal_status);
    }

    /** El saldo del libro de la caja de Multas en una cuenta. */
    private function libro(LedgerAccount $cuenta): string
    {
        return app(CashBalance::class)->of($cuenta, $this->cajaDe(CashBox::MULTAS), Currency::Ars, BusinessDate::today());
    }

    private function reversion(int $evento): int
    {
        return app(PostJournalEntry::class)->handle(
            type: FinancialEventType::Reversal,
            idempotencyKey: (string) Str::uuid(),
            lines: app(InverseEntry::class)->of($evento),
            date: BusinessDate::today(),
            cashBoxId: $this->cajaDe(CashBox::MULTAS),
            actorId: $this->quienAbre(),
            reversalOfId: $evento,
            reversalReason: 'Directo',
        )->id;
    }

    /** La fila de anulación con sus dos reversiones, sin pasar por el Action. */
    private function anulacionDirecta(FinePayment $pago): void
    {
        $aplicacion = $pago->application()->firstOrFail();
        $recepcion = FundReceipt::query()->findOrFail($aplicacion->fund_receipt_id);

        DB::table('fine_payment_voids')->insert([
            'fine_payment_id' => $pago->id, 'fine_id' => $pago->fine_id, 'reason_code' => 'data_entry_error',
            'application_reversal_event_id' => $this->reversion($aplicacion->application_event_id),
            'funds_exit_event_id' => $this->reversion($recepcion->financial_event_id),
        ]);
    }

    private function veredictoDirecto(Fine $multa, int $recurso, string $veredicto): int
    {
        return DB::table('fine_appeal_verdicts')->insertGetId([
            'fine_appeal_id' => $recurso, 'fine_id' => $multa->id, 'verdict' => $veredicto,
            'debt_entries_until_id' => 0, 'resolved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function rechazaElAction(string $campo, Closure $accion): void
    {
        try {
            DB::transaction($accion);
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey($campo);

            return;
        }

        $this->fail("El Action aceptó lo que tenía que rechazar ({$campo}).");
    }

    private function rechazaConMensaje(string $fragmento, Closure $accion): void
    {
        try {
            DB::transaction($accion);
        } catch (ValidationException $e) {
            expect(implode(' ', array_merge(...array_values($e->errors()))))->toContain($fragmento);

            return;
        }

        $this->fail("El Action aceptó lo que tenía que rechazar ({$fragmento}).");
    }

    private function rechazaLaBase(string $fragmento, Closure $accion): void
    {
        try {
            DB::transaction(function () use ($accion): void {
                $accion();
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
        } catch (QueryException $e) {
            expect(mb_strtolower($e->getMessage()))->toContain(mb_strtolower($fragmento));

            return;
        }

        $this->fail("La base aceptó lo que tenía que rechazar ({$fragmento}).");
    }
}
