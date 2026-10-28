<?php

declare(strict_types=1);

namespace Tests\Feature\Multas;

use App\Models\User;
use App\Modules\Ledger\Actions\ClosePeriod;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\PeriodType;
use App\Modules\Multas\Actions\CollectFinePayment;
use App\Modules\Multas\Enums\DatePrecision;
use App\Modules\Multas\Enums\FinePaymentMedium;
use App\Modules\Multas\Enums\FineTimeliness;
use App\Modules\Multas\Enums\PresentationPrecision;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Multas\Models\FinePaymentReceipt;
use App\Modules\Multas\Support\FinePaymentTimeliness;
use App\Modules\Shared\Actions\TakeNextDocumentNumber;
use App\Modules\Shared\Models\CashBox;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsSharedBankAccount;
use Tests\Concerns\CollectsInstallments;
use Tests\TestCase;

/**
 * El cobro de una multa en la caja: efectivo o cheque, aplicado en el acto y
 * con su recibo de Multas (M02, M05, M08, M09).
 */
class CobroEnCajaDeMultasTest extends TestCase
{
    use BuildsSharedBankAccount;
    use CollectsInstallments {
        cuota as cuotaDeHaberes;
        cobrar as cobrarCuota;
    }
    use RefreshDatabase;

    // ── El cobro ────────────────────────────────────────────────────────

    public function test_a_partial_cash_payment_is_applied_and_gets_its_own_receipt(): void
    {
        $multa = $this->escenario();

        $this->actingAs($this->operador())
            ->post(route('multas.cobros.store', $multa), $this->cobro('300000.00'))
            ->assertSessionHasNoErrors();

        $pago = FinePayment::query()->sole();
        $comprobante = FinePaymentReceipt::query()->with('receipt')->sole();

        expect($pago->status->value)->toBe('applied')
            ->and($this->saldo($multa, 'debt'))->toBe('1100000.00')
            ->and($this->saldo($multa, 'operational_paid'))->toBe('300000.00')
            ->and($comprobante->is_cancellation)->toBeFalse()
            ->and($comprobante->receipt->formatted_number)->toBe('0210/00000001')
            ->and($comprobante->receipt->person_id)->toBeNull()
            ->and($comprobante->receipt->counterparty_name_snapshot)->toBe('CRISTOMAX S.A.S')
            ->and($comprobante->receipt->concept_snapshot)->toBe('Pago parcial de la multa por Resolución 649/2026')
            ->and($comprobante->receipt->cash_box_id)->toBe($this->cajaDe(CashBox::MULTAS))
            ->and($this->libro())->toBe([
                'CASH_ON_HAND' => '300000.00',
                'COLLECTED_FUNDS' => '-300000.00',
                'UNASSIGNED_FUNDS' => '0.00',
            ]);

        // El papel sale con su formulario, y no por el visor de Haberes.
        $this->get(route('multas.recibos.view', $comprobante->receipt_id))
            ->assertOk()->assertSee('MULTAS')->assertSee('trescientos mil');
        $this->get(route('multas.recibos.print', $comprobante->receipt_id))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($this->operador('administrador'))
            ->get(route('recibos.view', $comprobante->receipt_id))->assertNotFound();
        $this->get(route('recepciones.show', $pago->application()->value('fund_receipt_id')))->assertNotFound();
    }

    /**
     * El recibo cita la resolución que impuso la multa, no un acta: las dos
     * son de origen. Acá el acta llegó antes que la resolución y su documento
     * es el primero del expediente, que es lo que «el primer documento de
     * origen» devolvía.
     */
    public function test_the_receipt_cites_the_origin_resolution_even_when_the_act_came_first(): void
    {
        $this->abrirMultas($this->cuenta(), '2026-06-01');
        $operador = $this->operador();

        $this->actingAs($operador)->post(route('multas.store'), [
            'caseNumber' => '169607/24-0',
            'resolution' => ['text' => 'N°0649/26', 'issuedOn' => '2026-05-07'],
            'acts' => [['actType' => 'HYS', 'text' => 'N°1089657', 'issuedOn' => null]],
            'caption' => 'PRIMERA S.A.',
            'registeredOn' => '2026-05-07',
            'amount' => '1400000.00',
        ])->assertSessionHasNoErrors();
        $primera = Fine::query()->sole();

        // El acta de la segunda multa ya estaba en el expediente; su resolución llega después.
        $this->post(route('multas.expedientes.documentos.store', $primera->fineCase), [
            'document' => ['kind' => 'act', 'actType' => 'LAB', 'text' => 'N°2000/25'],
        ])->assertSessionHasNoErrors();
        $acta = $primera->fineCase->documents()->where('original_text', 'N°2000/25')->sole();

        $this->post(route('multas.store'), [
            'fineCaseId' => $primera->fine_case_id,
            'resolution' => ['text' => 'N°0700/26', 'issuedOn' => '2026-05-08'],
            'actIds' => [$acta->id],
            'acts' => [],
            'caption' => 'SEGUNDA S.A.',
            'registeredOn' => '2026-05-08',
            'amount' => '500000.00',
        ])->assertSessionHasNoErrors();
        $segunda = Fine::query()->latest('id')->firstOrFail();

        $this->post(route('multas.cobros.store', $segunda), $this->cobro('100000.00'))->assertSessionHasNoErrors();

        expect(FinePaymentReceipt::query()->with('receipt')->sole()->receipt->concept_snapshot)
            ->toBe('Pago parcial de la multa por Resolución 700/2026');
    }

    /** El cheque entra entero; lo que supera la deuda es excedente (M05, M06). */
    public function test_a_cheque_that_exceeds_the_debt_cancels_it_and_keeps_the_excess(): void
    {
        $multa = $this->escenario();
        $this->cobrar($multa, FinePaymentMedium::Cash, '1000000.00');

        $pago = $this->cobrar($multa, FinePaymentMedium::Cheque, '500000.00', cheque: ['number' => '00123', 'bank' => 'Macro', 'issueDate' => null]);

        $aplicacion = $pago->application()->with('fundReceipt')->firstOrFail();
        $comprobante = $pago->paymentReceipt()->firstOrFail();

        expect($aplicacion->applied_amount)->toBe('400000.00')
            ->and($aplicacion->excess_amount)->toBe('100000.00')
            ->and($aplicacion->fundReceipt->cheque_status?->value)->toBe('in_custody')
            ->and($this->saldo($multa, 'debt'))->toBe('0.00')
            ->and($this->saldo($multa, 'excess'))->toBe('100000.00')
            ->and($comprobante->is_cancellation)->toBeTrue()
            ->and($comprobante->snapshot['previousPayments'])->toHaveCount(1)
            ->and($comprobante->snapshot['previousPayments'][0]['amount'])->toBe('1000000.00')
            ->and($comprobante->snapshot['excess'])->toBe('100000.00')
            ->and($this->libro())->toMatchArray([
                'CHEQUES_IN_CUSTODY' => '500000.00',
                'COLLECTED_FUNDS' => '-1400000.00',
                'PENDING_EXCESS' => '-100000.00',
            ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('no tiene saldo pendiente');
        $this->cobrar($multa, FinePaymentMedium::Cash, '1.00');
    }

    public function test_the_same_click_twice_collects_once(): void
    {
        $multa = $this->escenario();
        $datos = $this->cobro('1000.00');

        $this->actingAs($this->operador())->post(route('multas.cobros.store', $multa), $datos);
        $this->post(route('multas.cobros.store', $multa), $datos);

        expect(FinePayment::query()->count())->toBe(1)
            ->and(DB::table('receipts')->count())->toBe(1);
    }

    public function test_the_counter_rejects_what_it_cannot_collect(): void
    {
        $multa = $this->escenario();
        $usuario = $this->operador();

        $this->actingAs($usuario)
            ->post(route('multas.cobros.store', $multa), [...$this->cobro('100.00'), 'medium' => 'bank_transfer'])
            ->assertSessionHasErrors('medium');
        $this->post(route('multas.cobros.store', $multa), [...$this->cobro('100.00'), 'receivedOn' => BusinessDate::today()->subDay()->toDateString()])
            ->assertSessionHasErrors('talonarioNumber');
        $this->post(route('multas.cobros.store', $multa), [...$this->cobro('100.00'), 'judicialReference' => 'EXP-1/26'])
            ->assertSessionHasErrors('judicialReference');
        $this->post(route('multas.cobros.store', $multa), [...$this->cobro('100.00'), 'medium' => 'cheque'])
            ->assertSessionHasErrors('cheque.number');

        $this->actingAs($this->operador('consulta'))
            ->post(route('multas.cobros.store', $multa), $this->cobro('100.00'))
            ->assertForbidden();

        expect(FinePayment::query()->count())->toBe(0);
    }

    public function test_without_the_box_opened_nothing_is_collected(): void
    {
        $multa = $this->multa();

        $this->actingAs($this->operador())
            ->post(route('multas.cobros.store', $multa), $this->cobro('100.00'))
            ->assertSessionHasErrors('amount');

        expect(FinePayment::query()->count())->toBe(0);
    }

    /** Un cobro de un día anterior se presentó ese día, con el talonario de respaldo. */
    public function test_a_past_collection_keeps_its_day_and_its_paper(): void
    {
        $multa = $this->escenario();
        $ayer = BusinessDate::today()->subDay();

        $pago = $this->cobrar($multa, FinePaymentMedium::Cash, '1000.00', fecha: $ayer, talonario: '18470');
        $recibo = $pago->paymentReceipt()->with('receipt')->firstOrFail()->receipt;

        expect($pago->presented_precision->value)->toBe('day')
            ->and($pago->presentation_evidence)->toBe('Recibo de talonario N.º 18470')
            ->and($recibo->issue_date->toDateString())->toBe($ayer->toDateString())
            ->and($recibo->talonario_number)->toBe('18470')
            ->and($recibo->issue_mode->value)->toBe('offline_talonario');
    }

    /** Revisión 4a/4b, R3: la misma hoja dos veces es un error del campo, no del sistema. */
    public function test_a_talonario_page_already_used_is_a_form_error(): void
    {
        $multa = $this->escenario();
        $usuario = $this->operador();

        $this->actingAs($usuario)
            ->post(route('multas.cobros.store', $multa), [...$this->cobro('100.00'), 'talonarioNumber' => '18470'])
            ->assertSessionHasNoErrors();
        $this->post(route('multas.cobros.store', $multa), [...$this->cobro('200.00'), 'talonarioNumber' => '18470'])
            ->assertSessionHasErrors(['talonarioNumber' => 'La hoja 18470 del talonario ya se usó en el recibo 0210/00000001. Revisá el número: cada hoja se carga una sola vez, aunque su recibo se haya anulado.']);

        expect(FinePayment::query()->count())->toBe(1)
            ->and(DB::table('receipts')->count())->toBe(1);
    }

    /** Revisión 4a/4b, R1: un pago anterior conocido por el mes no se imprime con un día. */
    public function test_the_cancellation_receipt_keeps_the_precision_of_earlier_payments(): void
    {
        $multa = $this->escenario();
        $quien = $this->operador('administrador');

        $this->actingAs($quien)->post(route('multas.antecedentes.store', $multa), [
            'kind' => 'payment', 'amount' => '400000.00', 'occurredOn' => '2026-05',
            'evidence' => 'Planilla de caja de mayo',
        ])->assertSessionHasNoErrors();
        $antecedente = DB::table('fine_historical_movements')->where('fine_id', $multa->id)->value('id');
        $this->post(route('multas.antecedentes.review', $antecedente), ['status' => 'validated'])->assertSessionHasNoErrors();

        $comprobante = $this->cobrar($multa, FinePaymentMedium::Cash, '1000000.00')->paymentReceipt()->firstOrFail();

        expect($comprobante->is_cancellation)->toBeTrue()
            ->and($comprobante->snapshot['previousPayments'][0])->toMatchArray(['date' => '2026-05-01', 'precision' => 'month']);

        $this->get(route('multas.recibos.view', $comprobante->receipt_id))
            ->assertOk()->assertSee('05/2026')->assertDontSee('01/05/2026');
    }

    /** Revisión 4a/4b, R2: corregir la cuenta en el maestro no cambia un recibo emitido. */
    public function test_a_reprint_keeps_the_account_number_it_was_issued_with(): void
    {
        $multa = $this->escenario();
        $comprobante = $this->cobrar($multa, FinePaymentMedium::Cash, '1000.00')->paymentReceipt()->firstOrFail();

        DB::table('bank_accounts')->where('account_number', '310000999888777')->update(['account_number' => '310000999888778']);

        expect($comprobante->snapshot['bankAccountNumber'])->toBe('310000999888777');
        $this->actingAs($this->operador())->get(route('multas.recibos.view', $comprobante->receipt_id))
            ->assertOk()->assertSee('310000999888777')->assertDontSee('310000999888778');
    }

    // ── El excedente sigue a la obligación ──────────────────────────────

    public function test_adjusting_the_debt_reclassifies_what_was_already_collected(): void
    {
        $multa = $this->escenario();
        $this->cobrar($multa, FinePaymentMedium::Cash, '1000000.00');
        $quien = $this->operador('administrador');

        $this->actingAs($quien)->post(route('multas.ajustes.store', $multa), $this->ajuste('reduction', '600000.00'))
            ->assertSessionHasNoErrors();

        expect($this->saldo($multa, 'operational_excess'))->toBe('200000.00')
            ->and($this->libro())->toMatchArray(['COLLECTED_FUNDS' => '-800000.00', 'PENDING_EXCESS' => '-200000.00']);

        $this->post(route('multas.ajustes.store', $multa), $this->ajuste('increase', '150000.00'))->assertSessionHasNoErrors();

        expect($this->saldo($multa, 'operational_excess'))->toBe('50000.00')
            ->and($this->libro())->toMatchArray(['COLLECTED_FUNDS' => '-950000.00', 'PENDING_EXCESS' => '-50000.00'])
            ->and(DB::table('fine_excess_reclassifications')->orderBy('id')->pluck('amount')->all())
            ->toBe(['200000.00', '-150000.00']);
    }

    /** Un antecedente consume la obligación primero: lo cobrado después pasa a excedente. */
    public function test_validating_an_earlier_payment_moves_the_collected_money_to_excess(): void
    {
        $multa = $this->escenario();
        $this->cobrar($multa, FinePaymentMedium::Cash, '1400000.00');
        $quien = $this->operador('administrador');

        $this->actingAs($quien)->post(route('multas.antecedentes.store', $multa), [
            'kind' => 'payment', 'amount' => '400000.00', 'occurredOn' => '2026-05',
            'evidence' => 'Planilla de caja de mayo',
        ])->assertSessionHasNoErrors();
        $antecedente = DB::table('fine_historical_movements')->where('fine_id', $multa->id)->value('id');
        $this->post(route('multas.antecedentes.review', $antecedente), ['status' => 'validated'])->assertSessionHasNoErrors();

        expect($this->saldo($multa, 'operational_excess'))->toBe('400000.00')
            ->and($this->libro())->toMatchArray(['COLLECTED_FUNDS' => '-1000000.00', 'PENDING_EXCESS' => '-400000.00']);
    }

    public function test_the_database_rejects_a_debt_change_without_its_reclassification(): void
    {
        $multa = $this->escenario();
        $this->cobrar($multa, FinePaymentMedium::Cash, '1000000.00');

        $this->rechaza('falta reclasificar', function () use ($multa): void {
            $original = (array) DB::table('fine_debt_entries')->where('fine_id', $multa->id)->first();
            unset($original['id']);
            // Un ajuste cita su resolución como modificación, como lo deja `AdjustFineDebt`.
            DB::table('fine_document_links')->insert([
                'fine_id' => $multa->id, 'fine_case_id' => $multa->fine_case_id,
                'fine_document_id' => $original['fine_document_id'], 'role' => 'modification',
            ]);
            DB::table('fine_debt_entries')->insert([...$original, 'kind' => 'reduction', 'amount' => '600000.00', 'reason_code' => 'other', 'reason' => 'Directo']);
        });
    }

    // ── El término ──────────────────────────────────────────────────────

    public function test_a_payment_presented_after_the_month_closed_is_late(): void
    {
        $multa = $this->escenario(registrada: '2026-06-05');
        $this->cerrarJunio();

        $pago = $this->cobrar($multa, FinePaymentMedium::Cash, '1000.00');
        $aplicacion = $pago->application()->firstOrFail();

        expect($aplicacion->timeliness)->toBe(FineTimeliness::Late)
            ->and($aplicacion->origin_closing_evidence_id)->not->toBeNull()
            ->and($aplicacion->origin_closed_at)->not->toBeNull();
    }

    public function test_without_a_closing_the_payment_is_on_time_and_without_a_date_it_is_reviewed(): void
    {
        $multa = $this->escenario();

        expect($this->cobrar($multa, FinePaymentMedium::Cash, '1000.00')->application()->firstOrFail()->timeliness)
            ->toBe(FineTimeliness::OnTime);

        // Una multa importada sin fecha de registro no tiene período de origen.
        $sinFecha = $multa->replicate()->forceFill(['registered_on' => null, 'registered_precision' => DatePrecision::Unknown]);

        expect(app(FinePaymentTimeliness::class)->judge($sinFecha, now(), PresentationPrecision::Instant)['timeliness'])
            ->toBe(FineTimeliness::Undetermined);
    }

    /** Con solo el día de presentación, el mismo día del cierre no se decide solo (P-T1). */
    public function test_a_presentation_on_the_closing_day_is_left_for_review(): void
    {
        $multa = $this->escenario(registrada: '2026-06-05');
        $this->cerrarJunio();
        $cerrado = CarbonImmutable::parse((string) DB::table('period_closings')->where('period_type', 'monthly')->value('closed_at'));
        $juez = app(FinePaymentTimeliness::class);

        expect($juez->judge($multa, $cerrado->subDay(), PresentationPrecision::Day)['timeliness'])->toBe(FineTimeliness::OnTime)
            ->and($juez->judge($multa, $cerrado, PresentationPrecision::Day)['timeliness'])->toBe(FineTimeliness::Undetermined)
            ->and($juez->judge($multa, $cerrado->subSecond(), PresentationPrecision::Instant)['timeliness'])->toBe(FineTimeliness::OnTime)
            ->and($juez->judge($multa, $cerrado->addSecond(), PresentationPrecision::Instant)['timeliness'])->toBe(FineTimeliness::Late);
    }

    // ── La base ─────────────────────────────────────────────────────────

    public function test_the_database_rejects_an_applied_payment_without_its_application_and_receipt(): void
    {
        $multa = $this->escenario();

        $this->rechaza('sin su aplicación', function () use ($multa): void {
            DB::table('fine_payments')->insert($this->filaDePago($multa));
        });
    }

    public function test_the_database_keeps_payments_from_being_edited_or_reopened(): void
    {
        $multa = $this->escenario();
        $pago = $this->cobrar($multa, FinePaymentMedium::Cash, '1000.00');

        $this->rechaza('no se editan', fn () => DB::table('fine_payments')->where('id', $pago->id)->update(['amount' => '2000.00']));
        $this->rechaza('no pasa a', fn () => DB::table('fine_payments')->where('id', $pago->id)->update([
            'status' => 'discarded', 'discarded_by' => $this->quienAbre(), 'discarded_at' => now(), 'discard_reason' => 'Error',
        ]));
        $this->rechaza('documenta un cobro', fn () => DB::table('fine_payment_applications')->where('fine_payment_id', $pago->id)->delete());
    }

    public function test_the_database_rejects_a_bank_payment_born_applied_and_a_cash_one_born_reported(): void
    {
        $multa = $this->escenario();

        $this->rechaza('nace informado', fn () => DB::table('fine_payments')->insert([...$this->filaDePago($multa), 'medium' => 'bank_transfer']));
        $this->rechaza('se aplica en el acto', fn () => DB::table('fine_payments')->insert([...$this->filaDePago($multa), 'status' => 'reported']));
    }

    public function test_the_database_rejects_a_multas_receipt_that_documents_no_payment(): void
    {
        $this->escenario();

        $this->rechaza('no documenta ningún pago', function (): void {
            $numero = app(TakeNextDocumentNumber::class)->handle('0210');
            DB::table('receipts')->insert([
                'document_series_id' => $numero['series_id'], 'number' => $numero['number'],
                'formatted_number' => $numero['formatted'], 'receipt_type' => 'income', 'medium_snapshot' => 'cash',
                'counterparty_name_snapshot' => 'CRISTOMAX S.A.S', 'amount' => '100.00', 'issue_date' => '2026-10-20',
                'status' => 'issued', 'issue_mode' => 'online', 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function test_money_collected_for_a_fine_does_not_fund_an_installment_nor_is_reversed(): void
    {
        $multa = $this->escenario();
        $pago = $this->cobrar($multa, FinePaymentMedium::Cash, '1000.00');
        $recepcion = (int) $pago->application()->value('fund_receipt_id');

        $cuota = $this->cuotaDeHaberes('100/26', '1000.00');

        $this->rechaza('no se asigna a una cuota', fn () => DB::table('funding_allocations')->insert([
            'fund_receipt_id' => $recepcion, 'beneficiary_installment_id' => $cuota->id, 'haber_id' => $cuota->haber_id, 'amount' => '1000.00',
        ]));
        $this->rechaza('no se revierte', fn () => DB::table('fund_receipts')->where('id', $recepcion)->update([
            'reversal_event_id' => $pago->application()->value('application_event_id'),
            'reversed_at' => now(), 'reversal_reason' => 'Prueba',
        ]));
    }

    // ── Escenario ───────────────────────────────────────────────────────

    /** La cuenta cortada, la caja de Multas abierta el 01/06 y una multa de 1.400.000. */
    private function escenario(string $registrada = '2026-05-07'): Fine
    {
        $cuenta = $this->cuenta();
        $this->abrirMultas($cuenta, '2026-06-01');

        return $this->multa($registrada);
    }

    private function multa(string $registrada = '2026-05-07'): Fine
    {
        $this->actingAs($this->operador())->post(route('multas.store'), [
            'caseNumber' => '169607/24-0',
            'resolution' => ['text' => 'N°0649/26', 'issuedOn' => '2026-05-07'],
            'acts' => [['actType' => 'HYS', 'text' => 'N°1089657', 'issuedOn' => null]],
            'caption' => 'CRISTOMAX S.A.S',
            'registeredOn' => $registrada,
            'amount' => '1400000.00',
        ])->assertSessionHasNoErrors();

        return Fine::query()->latest('id')->firstOrFail();
    }

    /**
     * @param  array{number: string, bank: string|null, issueDate: string|null}|null  $cheque
     */
    private function cobrar(
        Fine $multa,
        FinePaymentMedium $medio,
        string $importe,
        ?array $cheque = null,
        ?CarbonImmutable $fecha = null,
        ?string $talonario = null,
    ): FinePayment {
        return app(CollectFinePayment::class)->handle(
            $multa, $medio, $importe, (string) Str::uuid(), $this->quienAbre(),
            receivedOn: $fecha, cheque: $cheque, talonarioNumber: $talonario,
        );
    }

    /** @return array<string, mixed> */
    private function cobro(string $importe): array
    {
        return ['medium' => 'cash', 'amount' => $importe, 'idempotencyKey' => (string) Str::uuid()];
    }

    /** @return array<string, mixed> */
    private function ajuste(string $tipo, string $importe): array
    {
        return [
            'kind' => $tipo, 'amount' => $importe, 'effectiveOn' => '2026-06-01',
            'document' => ['text' => 'N°0900/26', 'issuedOn' => '2026-06-01'],
            'reasonCode' => 'appeal_admitted',
            'reason' => 'Recurso jerárquico parcialmente admitido',
        ];
    }

    /** @return array<string, mixed> */
    private function filaDePago(Fine $multa): array
    {
        $ahora = now();

        return [
            'fine_id' => $multa->id, 'medium' => 'cash', 'status' => 'applied', 'amount' => '100.00',
            'presented_at' => $ahora, 'presented_precision' => 'instant', 'reported_at' => $ahora,
            'payer_name' => 'CRISTOMAX S.A.S', 'idempotency_key' => (string) Str::uuid(),
            'created_at' => $ahora, 'updated_at' => $ahora,
        ];
    }

    private function cerrarJunio(): void
    {
        $quien = User::query()->findOrFail($this->quienAbre());
        $caja = $this->cajaDe(CashBox::MULTAS);

        $this->arqueoListoParaCerrar($caja, '2026-06-30', $quien);
        app(ClosePeriod::class)->handle(
            cashBoxId: $caja,
            date: CarbonImmutable::parse('2026-06-30'),
            type: PeriodType::Monthly,
            currency: Currency::Ars,
            actorId: $quien->id,
        );
    }

    private function saldo(Fine $multa, string $columna): string
    {
        return (string) DB::table('fine_balances')->where('fine_id', $multa->id)->value($columna);
    }

    /**
     * Saldo de cada cuenta de la caja de Multas, débito menos crédito.
     *
     * @return array<string, string>
     */
    private function libro(): array
    {
        return DB::table('journal_lines')
            ->where('cash_box_id', $this->cajaDe(CashBox::MULTAS))
            ->groupBy('account_code')
            ->orderBy('account_code')
            ->selectRaw('account_code, SUM(debit - credit)::numeric(19,2)::text AS saldo')
            ->pluck('saldo', 'account_code')
            ->all();
    }

    private function rechaza(string $fragmento, callable $accion): void
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
