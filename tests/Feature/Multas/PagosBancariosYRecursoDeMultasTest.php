<?php

declare(strict_types=1);

namespace Tests\Feature\Multas;

use App\Models\User;
use App\Modules\Banking\Actions\RegisterBankFundReceipt;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Ledger\Actions\ClosePeriod;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\PeriodType;
use App\Modules\Multas\Actions\ApplyFinePayment;
use App\Modules\Multas\Actions\CollectFinePayment;
use App\Modules\Multas\Actions\DiscardFinePayment;
use App\Modules\Multas\Actions\RegisterFineAppeal;
use App\Modules\Multas\Actions\ReportFineBankPayment;
use App\Modules\Multas\Data\FinePaymentData;
use App\Modules\Multas\Enums\AppealKind;
use App\Modules\Multas\Enums\FinePaymentMedium;
use App\Modules\Multas\Enums\FinePaymentStatus;
use App\Modules\Multas\Enums\FineReasonCode;
use App\Modules\Multas\Enums\FineTimeliness;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Multas\Support\AppealAmount;
use App\Modules\Multas\Support\FineBankCredits;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Models\Attachment;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Support\Reason;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsSharedBankAccount;
use Tests\TestCase;

/**
 * Los pagos de multas por el banco —informados y verificados contra el
 * extracto— y el pago del 20 % por recurso (M02, M06, M07, M08).
 */
class PagosBancariosYRecursoDeMultasTest extends TestCase
{
    use BuildsSharedBankAccount;
    use RefreshDatabase;

    private BankAccount $laCuenta;

    // ── Informar y verificar ────────────────────────────────────────────

    public function test_a_reported_transfer_reduces_the_debt_only_when_verified_against_its_credit(): void
    {
        $multa = $this->escenario();
        $credito = $this->movimiento($this->laCuenta, '2026-06-10', '300000.00');

        $this->actingAs($this->operador())
            ->post(route('multas.pagos-bancarios.store', $multa), $this->informe('300000.00'))
            ->assertSessionHasNoErrors();

        $pago = FinePayment::query()->sole();
        expect($pago->status)->toBe(FinePaymentStatus::Reported)
            ->and($this->saldo($multa, 'debt'))->toBe('1400000.00')
            ->and(DB::table('receipts')->count())->toBe(0);

        $this->actingAs($this->operador('contador'))
            ->post(route('multas.pagos.verify', $pago), ['bankTransactionId' => $credito->id])
            ->assertSessionHasNoErrors();

        $pago->refresh();
        $recibo = $pago->paymentReceipt()->with('receipt')->firstOrFail()->receipt;

        expect($pago->status)->toBe(FinePaymentStatus::Applied)
            ->and($this->saldo($multa, 'debt'))->toBe('1100000.00')
            ->and($recibo->medium_snapshot)->toBe('bank')
            ->and($recibo->formatted_number)->toBe('0210/00000001')
            ->and($this->libro())->toMatchArray(['BANK_ACCOUNT' => '300000.00', 'COLLECTED_FUNDS' => '-300000.00', 'UNASSIGNED_FUNDS' => '0.00'])
            ->and($credito->refresh()->reconciliation_status->value)->not->toBe('pending');

        // Ya identificado, el crédito deja de ofrecerse.
        expect(app(FineBankCredits::class)->available(''))->toBe([]);
    }

    /** El caso habitual: se reconoce el crédito en el extracto, sin informe previo. */
    public function test_a_credit_can_be_identified_and_verified_in_one_step(): void
    {
        $multa = $this->escenario();
        $credito = $this->movimiento($this->laCuenta, '2026-06-10', '1400000.00');

        $this->actingAs($this->operador('contador'))
            ->post(route('multas.pagos-bancarios.verificados.store', $multa), [
                'medium' => 'bank_transfer', 'bankTransactionId' => $credito->id,
                'judicialReference' => 'EXP-555/2025', 'talonarioNumber' => '18485', 'idempotencyKey' => (string) Str::uuid(),
            ])
            ->assertSessionHasNoErrors();

        $pago = FinePayment::query()->sole();
        $comprobante = $pago->paymentReceipt()->with('receipt')->firstOrFail();

        expect($pago->status)->toBe(FinePaymentStatus::Applied)
            ->and($pago->judicial_reference)->toBe('EXP-555/2025')
            ->and($pago->presented_precision->value)->toBe('instant')
            ->and($comprobante->is_cancellation)->toBeTrue()
            ->and($comprobante->receipt->talonario_number)->toBe('18485')
            ->and($this->saldo($multa, 'debt'))->toBe('0.00');
    }

    /** P14 provisorio: un crédito por pago, por su importe entero. */
    public function test_the_credit_has_to_be_the_whole_payment(): void
    {
        $multa = $this->escenario();
        $pago = $this->informar($multa, '300000.00');
        $credito = $this->movimiento($this->laCuenta, '2026-06-10', '320000.00');

        $this->actingAs($this->operador('contador'))
            ->post(route('multas.pagos.verify', $pago), ['bankTransactionId' => $credito->id])
            ->assertSessionHasErrors(['bankTransactionId' => 'El crédito es de $ 320.000,00 y el pago informado de $ 300.000,00. Un pago se verifica con un solo crédito, por su importe entero.']);

        expect($pago->refresh()->status)->toBe(FinePaymentStatus::Reported);
    }

    public function test_a_credit_before_the_box_opened_or_already_used_is_not_available(): void
    {
        $multa = $this->escenario();
        $otra = $this->multa('170000/24-0');
        $viejo = $this->movimiento($this->laCuenta, '2026-05-20', '1000.00');
        $credito = $this->movimiento($this->laCuenta, '2026-06-10', '1000.00');
        $contador = $this->operador('contador');

        $this->actingAs($contador)
            ->post(route('multas.pagos.verify', $this->informar($multa, '1000.00')), ['bankTransactionId' => $viejo->id])
            ->assertSessionHasErrors('bankTransactionId');

        $this->post(route('multas.pagos.verify', FinePayment::query()->sole()), ['bankTransactionId' => $credito->id])
            ->assertSessionHasNoErrors();

        // Una transferencia, una multa (M06).
        $this->post(route('multas.pagos.verify', $this->informar($otra, '1000.00')), ['bankTransactionId' => $credito->id])
            ->assertSessionHasErrors('bankTransactionId');

        expect(FinePayment::query()->where('status', 'applied')->count())->toBe(1);
    }

    /** La base: el crédito entero y de una sola multa, aunque nadie pase por el Action. */
    public function test_the_database_rejects_a_partial_credit_behind_a_bank_payment(): void
    {
        $multa = $this->escenario();
        $credito = $this->movimiento($this->laCuenta, '2026-06-10', '300000.00');
        $pago = $this->informar($multa, '100000.00');

        $this->rechaza('crédito entero', function () use ($pago, $credito): void {
            $pago->forceFill(['status' => FinePaymentStatus::Applied])->save();
            $recepcion = app(RegisterBankFundReceipt::class)->handle(
                $credito, '100000.00', (string) Str::uuid(), $this->cajaDe(CashBox::MULTAS), actorId: $this->quienAbre(),
            );
            app(ApplyFinePayment::class)->handle($pago, $recepcion, BusinessDate::today(), null, null, $this->quienAbre());
        });
    }

    public function test_a_reported_payment_that_does_not_belong_is_discarded_with_its_reason(): void
    {
        $multa = $this->escenario();
        $pago = $this->informar($multa, '5000.00');

        $this->actingAs($this->operador())
            ->post(route('multas.pagos.discard', $pago), ['reasonCode' => 'wrong_fine', 'reason' => 'Es de otra multa'])
            ->assertForbidden();

        $this->actingAs($this->operador('contador'))
            ->post(route('multas.pagos.discard', $pago), ['reasonCode' => 'wrong_fine', 'reason' => 'Es de otra multa'])
            ->assertSessionHasNoErrors();

        expect($pago->refresh()->status)->toBe(FinePaymentStatus::Discarded)
            ->and($pago->discard_reason)->toBe('Es de otra multa')
            ->and($pago->discard_reason_code)->toBe(FineReasonCode::WrongFine);
    }

    public function test_who_can_report_verify_and_see_the_queue(): void
    {
        $multa = $this->escenario();

        $this->actingAs($this->operador('consulta'))
            ->post(route('multas.pagos-bancarios.store', $multa), $this->informe('100.00'))
            ->assertForbidden();

        $administrativo = $this->operador();
        $this->actingAs($administrativo)
            ->post(route('multas.pagos-bancarios.store', $multa), $this->informe('100.00'))
            ->assertSessionHasNoErrors();
        // Quien no concilia ve los pendientes sin la pestaña de informados.
        $this->get(route('multas.pendientes.index', ['pestana' => 'informados']))
            ->assertInertia(fn (Assert $page) => $page->where('tab', 'depositar')->has('informados', 0)->where('can.verify', false));
        $this->post(route('multas.pagos.verify', FinePayment::query()->sole()), ['bankTransactionId' => 1])->assertForbidden();

        $this->withoutVite();
        $this->actingAs($this->operador('contador'))
            ->get(route('multas.pendientes.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('multas/pendientes')
                ->where('tab', 'informados')
                ->has('informados', 1)
                ->where('informados.0.fineCaption', 'CRISTOMAX S.A.S'));
    }

    /** Una presentación de un día anterior lleva su respaldo, y queda con el día. */
    public function test_an_earlier_presentation_needs_its_evidence(): void
    {
        $multa = $this->escenario();
        $ayer = BusinessDate::today()->subDay()->toDateString();

        $this->actingAs($this->operador())
            ->post(route('multas.pagos-bancarios.store', $multa), [...$this->informe('100.00'), 'presentedOn' => $ayer])
            ->assertSessionHasErrors('presentationEvidence');

        $this->post(route('multas.pagos-bancarios.store', $multa), [
            ...$this->informe('100.00'), 'presentedOn' => $ayer, 'presentationEvidence' => 'Sello de Mesa de Entradas',
        ])->assertSessionHasNoErrors();

        $pago = FinePayment::query()->sole();
        expect($pago->presented_precision->value)->toBe('day')
            ->and($pago->presentation_evidence)->toBe('Sello de Mesa de Entradas');
    }

    public function test_the_payment_proof_is_attached_to_the_payment(): void
    {
        $multa = $this->escenario();
        $pago = $this->informar($multa, '100.00');

        $this->actingAs($this->operador())
            ->post(route('multas.pagos.adjuntos.store', $pago), ['file' => UploadedFile::fake()->image('transferencia.jpg')])
            ->assertSessionHasNoErrors();

        $adjunto = Attachment::query()->sole();
        expect($adjunto->subject_type)->toBe(AttachmentSubject::FinePayment)
            ->and($adjunto->subject_id)->toBe($pago->id);
    }

    // ── Corregir un informado ───────────────────────────────────────────

    /** Un informado mal cargado se corrige: el anterior queda descartado y el nuevo apunta a él. */
    public function test_a_reported_payment_is_corrected_keeping_its_presentation(): void
    {
        $multa = $this->escenario();
        $ayer = BusinessDate::today()->subDay()->toDateString();
        $usuario = $this->operador();

        $this->actingAs($usuario)->post(route('multas.pagos-bancarios.store', $multa), [
            ...$this->informe('30000.00'), 'presentedOn' => $ayer, 'presentationEvidence' => 'Sello de Mesa de Entradas',
        ])->assertSessionHasNoErrors();
        $anterior = FinePayment::query()->sole();

        $this->post(route('multas.pagos-bancarios.store', $multa), [
            ...$this->informe('300000.00'), 'replacesPaymentId' => $anterior->id,
        ])->assertSessionHasErrors('correctionReason');

        $this->post(route('multas.pagos-bancarios.store', $multa), [
            ...$this->informe('300000.00'), 'replacesPaymentId' => $anterior->id, 'correctionReason' => 'Faltaba un cero en el importe',
        ])->assertSessionHasNoErrors();

        $corregido = FinePayment::query()->latest('id')->firstOrFail();

        expect($anterior->refresh()->status)->toBe(FinePaymentStatus::Discarded)
            ->and($anterior->discard_reason_code)->toBe(FineReasonCode::ReplacedByCorrection)
            ->and($anterior->discard_reason)->toBe('Faltaba un cero en el importe')
            ->and($corregido->status)->toBe(FinePaymentStatus::Reported)
            ->and($corregido->amount)->toBe('300000.00')
            ->and($corregido->replaces_fine_payment_id)->toBe($anterior->id)
            ->and($corregido->presented_precision->value)->toBe('day')
            ->and($corregido->presented_at?->toDateString())->toBe($ayer)
            ->and($corregido->presentation_evidence)->toBe('Sello de Mesa de Entradas');
    }

    /** Lo aplicado ya tiene recibo: no se corrige así (anular es P13, pendiente). */
    public function test_an_applied_payment_is_not_corrected(): void
    {
        $multa = $this->escenario();
        $pago = app(CollectFinePayment::class)->handle($multa, FinePaymentMedium::Cash, '1000.00', (string) Str::uuid(), $this->quienAbre());

        $this->actingAs($this->operador())->post(route('multas.pagos-bancarios.store', $multa), [
            ...$this->informe('1000.00'), 'replacesPaymentId' => $pago->id, 'correctionReason' => 'Era una transferencia',
        ])->assertSessionHasErrors('replacesPaymentId');

        expect($pago->refresh()->status)->toBe(FinePaymentStatus::Applied);
    }

    /**
     * La presentación decide el término: una corrección no la cambia, aunque la
     * petición traiga otra. Informado después del cierre de junio, sigue fuera
     * de término.
     */
    public function test_a_correction_cannot_bring_another_presentation(): void
    {
        $multa = $this->escenario(registrada: '2026-06-05');
        $this->cerrarJunio();
        $credito = $this->movimiento($this->laCuenta, '2026-06-29', '280000.00');
        $anterior = $this->informar($multa, '28000.00');
        $correccion = [
            ...$this->informe('280000.00'), 'replacesPaymentId' => $anterior->id, 'correctionReason' => 'Faltaba un cero',
        ];

        $this->actingAs($this->operador())->post(route('multas.pagos-bancarios.store', $multa), [
            ...$correccion, 'presentedOn' => '2026-06-29', 'presentationEvidence' => 'Sello de Mesa de Entradas',
        ])->assertSessionHasErrors('presentedOn');
        expect($anterior->refresh()->status)->toBe(FinePaymentStatus::Reported);

        $this->post(route('multas.pagos-bancarios.store', $multa), $correccion)->assertSessionHasNoErrors();
        $corregido = FinePayment::query()->latest('id')->firstOrFail();

        expect($corregido->presented_at?->format('Y-m-d H:i:s.u'))->toBe($anterior->presented_at?->format('Y-m-d H:i:s.u'))
            ->and($corregido->presented_precision)->toBe($anterior->presented_precision);

        $this->actingAs($this->operador('contador'))
            ->post(route('multas.pagos.verify', $corregido), ['bankTransactionId' => $credito->id])
            ->assertSessionHasNoErrors();

        expect($corregido->application()->firstOrFail()->timeliness)->toBe(FineTimeliness::Late);
    }

    /**
     * Se informó $ 28.000 por un depósito de $ 280.000 y otro pago canceló la
     * multa: la corrección no pide saldo, y el depósito entra como excedente al
     * verificarlo.
     */
    public function test_a_reported_payment_is_corrected_after_another_one_settles_the_fine(): void
    {
        $multa = $this->escenario();
        $credito = $this->movimiento($this->laCuenta, '2026-06-10', '280000.00');
        $anterior = $this->informar($multa, '28000.00');
        app(CollectFinePayment::class)->handle($multa, FinePaymentMedium::Cash, '1400000.00', (string) Str::uuid(), $this->quienAbre());

        $this->actingAs($this->operador())->post(route('multas.pagos-bancarios.store', $multa), $this->informe('1000.00'))
            ->assertSessionHasErrors('amount');
        $this->post(route('multas.pagos-bancarios.store', $multa), [
            ...$this->informe('280000.00'), 'replacesPaymentId' => $anterior->id, 'correctionReason' => 'El depósito es de 280.000',
        ])->assertSessionHasNoErrors();

        $corregido = FinePayment::query()->where('replaces_fine_payment_id', $anterior->id)->sole();
        $this->actingAs($this->operador('contador'))
            ->post(route('multas.pagos.verify', $corregido), ['bankTransactionId' => $credito->id])
            ->assertSessionHasNoErrors();

        expect($corregido->application()->firstOrFail()->excess_amount)->toBe('280000.00')
            ->and($this->saldo($multa, 'operational_excess'))->toBe('280000.00');
    }

    /** La base: una corrección apunta a un pago descartado de la misma multa y del mismo tipo. */
    public function test_the_database_keeps_corrections_pointing_to_a_discarded_payment(): void
    {
        $multa = $this->escenario();
        $vigente = $this->informar($multa, '1000.00');

        $this->rechaza('reemplaza a un pago descartado', fn () => DB::table('fine_payments')->insert([
            ...$this->filaInformada($multa), 'replaces_fine_payment_id' => $vigente->id,
        ]));
    }

    /** La base: el corregido nace informado y con la presentación del que reemplaza. */
    public function test_the_database_keeps_the_presentation_of_a_correction(): void
    {
        $multa = $this->escenario();
        $descartado = app(DiscardFinePayment::class)->handle($this->informar($multa, '1000.00'), new Reason(FineReasonCode::Duplicate), $this->quienAbre());
        $fila = [
            ...$this->filaInformada($multa),
            'replaces_fine_payment_id' => $descartado->id,
            'presented_at' => $descartado->presented_at,
            'presented_precision' => $descartado->presented_precision->value,
        ];

        $this->rechaza('conserva la presentación', fn () => DB::table('fine_payments')->insert([
            ...$fila, 'presented_at' => BusinessDate::startOfDay('2026-06-29'), 'presented_precision' => 'day',
            'presentation_evidence' => 'Sello de Mesa de Entradas',
        ]));
        $this->rechaza('nace informada', fn () => DB::table('fine_payments')->insert([
            ...$fila, 'medium' => 'cash', 'status' => 'applied',
        ]));
    }

    // ── Recurso del 20 % ────────────────────────────────────────────────

    /** El 20 % calculado, de referencia: pesos enteros a favor del deudor. */
    public function test_the_appeal_amount_drops_the_cents(): void
    {
        expect(AppealAmount::required('100002.50'))->toBe('20000.00')
            ->and(AppealAmount::required('1400000.00'))->toBe('280000.00')
            ->and(AppealAmount::required('94555.20'))->toBe('18911.00')
            ->and(AppealAmount::required('4.99'))->toBe('0.00');
    }

    public function test_an_appeal_in_cash_is_collected_for_its_external_receipt(): void
    {
        $multa = $this->escenario();

        $this->actingAs($this->operador())
            ->post(route('multas.recursos.store', $multa), $this->recurso('cash', '280000.00'))
            ->assertSessionHasNoErrors();

        $pago = FinePayment::query()->sole();

        expect($pago->status)->toBe(FinePaymentStatus::Applied)
            ->and($pago->appeal_kind)->toBe(AppealKind::Hierarchical)
            ->and($pago->appeal_base_amount)->toBe('1400000.00')
            ->and($pago->appeal_required_amount)->toBe('280000.00')
            ->and($pago->amount)->toBe('280000.00')
            ->and($pago->paymentReceipt()->exists())->toBeTrue()
            ->and($this->saldo($multa, 'debt'))->toBe('1120000.00');
    }

    public function test_an_appeal_can_be_registered_without_an_external_receipt_number(): void
    {
        $multa = $this->escenario();
        $datos = $this->recurso('direct_deposit', '280000.00');
        unset($datos['externalReference']);

        $this->actingAs($this->operador())
            ->post(route('multas.recursos.store', $multa), $datos)
            ->assertSessionHasNoErrors();

        $pago = FinePayment::query()->sole();
        expect($pago->external_receipt_reference)->toBeNull()
            ->and($pago->amount)->toBe('280000.00');
    }

    public function test_a_cash_appeal_without_an_external_number_still_issues_its_own_receipt(): void
    {
        $multa = $this->escenario();
        $datos = $this->recurso('cash', '280000.00');
        $datos['externalReference'] = '';

        $this->actingAs($this->operador())
            ->post(route('multas.recursos.store', $multa), $datos)
            ->assertSessionHasNoErrors();

        $pago = FinePayment::query()->sole();
        expect($pago->external_receipt_reference)->toBeNull()
            ->and($pago->paymentReceipt()->exists())->toBeTrue();
    }

    /**
     * U, 21/10/2026: se cobra lo que dice el recibo externo, aunque difiera del
     * 20 % calculado; la diferencia queda a la vista.
     */
    public function test_an_appeal_that_differs_from_the_twenty_percent_is_collected_as_written(): void
    {
        $multa = $this->escenario();

        $this->actingAs($this->operador())
            ->post(route('multas.recursos.store', $multa), $this->recurso('cash', '280000.50'))
            ->assertSessionHasNoErrors();

        $pago = FinePayment::query()->sole();

        expect($pago->status)->toBe(FinePaymentStatus::Applied)
            ->and($pago->amount)->toBe('280000.50')
            ->and($pago->appeal_required_amount)->toBe('280000.00')
            ->and(FinePaymentData::fromModel($pago->load(['application.fundReceipt', 'paymentReceipt.receipt', 'creator']))->appealDiffers)->toBeTrue()
            ->and($this->saldo($multa, 'debt'))->toBe('1119999.50');
    }

    /** U, 21/10/2026: una multa admite un solo recurso. */
    public function test_a_fine_admits_a_single_appeal(): void
    {
        $multa = $this->escenario();

        $this->actingAs($this->operador())
            ->post(route('multas.recursos.store', $multa), $this->recurso('direct_deposit', '280000.00'))
            ->assertSessionHasNoErrors();
        $this->post(route('multas.recursos.store', $multa), $this->recurso('cash', '280000.00'))
            ->assertSessionHasErrors('kind');

        expect(FinePayment::query()->count())->toBe(1);
    }

    /** Un intento descartado no cuenta: después se registra el que corresponde. */
    public function test_a_discarded_appeal_does_not_count(): void
    {
        $multa = $this->escenario();
        $intento = $this->recurrir($multa, FinePaymentMedium::DirectDeposit, '28000.00');

        $this->actingAs($this->operador('contador'))
            ->post(route('multas.pagos.discard', $intento), ['reasonCode' => 'wrong_fine', 'reason' => 'El recibo externo era de otra multa'])
            ->assertSessionHasNoErrors();

        expect($this->recurrir($multa, FinePaymentMedium::Cash, '280000.00')->status)->toBe(FinePaymentStatus::Applied);
    }

    /** Un depósito de recurso mal cargado se corrige; sigue habiendo un solo recurso. */
    public function test_a_reported_appeal_deposit_is_corrected(): void
    {
        $multa = $this->escenario();
        $anterior = $this->recurrir($multa, FinePaymentMedium::DirectDeposit, '28000.00');

        $this->actingAs($this->operador())->post(route('multas.recursos.store', $multa), [
            ...$this->recurso('direct_deposit', '280000.00'),
            'replacesPaymentId' => $anterior->id, 'correctionReason' => 'El recibo externo dice 280.000',
        ])->assertSessionHasNoErrors();

        $corregido = FinePayment::query()->latest('id')->firstOrFail();

        expect($anterior->refresh()->status)->toBe(FinePaymentStatus::Discarded)
            ->and($corregido->amount)->toBe('280000.00')
            ->and($corregido->replaces_fine_payment_id)->toBe($anterior->id);
    }

    /**
     * La corrección de un recurso es otro depósito con la misma presentación:
     * no se convierte en un cobro en efectivo ni trae otra fecha. Y no pide
     * saldo: otro pago pudo haber cancelado la multa.
     */
    public function test_an_appeal_correction_stays_a_deposit_with_its_presentation(): void
    {
        $multa = $this->escenario();
        $anterior = $this->recurrir($multa, FinePaymentMedium::DirectDeposit, '28000.00');
        app(CollectFinePayment::class)->handle($multa, FinePaymentMedium::Cash, '1400000.00', (string) Str::uuid(), $this->quienAbre());
        $correccion = fn (string $medio): array => [
            ...$this->recurso($medio, '280000.00'), 'replacesPaymentId' => $anterior->id, 'correctionReason' => 'El recibo externo dice 280.000',
        ];

        $this->actingAs($this->operador())->post(route('multas.recursos.store', $multa), $correccion('cash'))
            ->assertSessionHasErrors('medium');
        $this->post(route('multas.recursos.store', $multa), [
            ...$correccion('direct_deposit'),
            'presentedOn' => BusinessDate::today()->subDay()->toDateString(), 'presentationEvidence' => 'Sello de Mesa de Entradas',
        ])->assertSessionHasErrors('presentedOn');
        expect($anterior->refresh()->status)->toBe(FinePaymentStatus::Reported);

        $this->post(route('multas.recursos.store', $multa), $correccion('direct_deposit'))->assertSessionHasNoErrors();
        $corregido = FinePayment::query()->where('replaces_fine_payment_id', $anterior->id)->sole();

        expect($corregido->status)->toBe(FinePaymentStatus::Reported)
            ->and($corregido->presented_at?->format('Y-m-d H:i:s.u'))->toBe($anterior->presented_at?->format('Y-m-d H:i:s.u'))
            ->and($this->saldo($multa, 'debt'))->toBe('0.00');
    }

    /** La base: dos recursos vigentes de la misma multa no entran. */
    public function test_the_database_keeps_a_single_appeal_per_fine(): void
    {
        $multa = $this->escenario();
        $this->recurrir($multa, FinePaymentMedium::DirectDeposit, '280000.00');

        $this->rechaza('fine_payments_one_appeal_per_fine', fn () => DB::table('fine_payments')->insert(
            $this->filaDeRecurso($multa, base: '1400000.00', exigido: '280000.00'),
        ));
    }

    /** El depósito del recurso conserva su presentación anterior: se presentó antes del cierre de junio. */
    public function test_an_appeal_deposit_keeps_its_earlier_presentation(): void
    {
        $multa = $this->escenario(registrada: '2026-06-05');
        $this->cerrarJunio();
        $credito = $this->movimiento($this->laCuenta, '2026-06-29', '280000.00');
        $usuario = $this->operador();

        $this->actingAs($usuario)
            ->post(route('multas.recursos.store', $multa), [...$this->recurso('direct_deposit', '280000.00'), 'presentedOn' => '2026-06-29'])
            ->assertSessionHasErrors('presentationEvidence');
        $this->post(route('multas.recursos.store', $multa), [
            ...$this->recurso('direct_deposit', '280000.00'), 'presentedOn' => '2026-06-29', 'presentationEvidence' => 'Sello de Mesa de Entradas',
        ])->assertSessionHasNoErrors();

        $pago = FinePayment::query()->sole();
        expect($pago->presented_precision->value)->toBe('day')
            ->and($pago->presented_at?->toDateString())->toBe('2026-06-29');

        $this->actingAs($this->operador('contador'))
            ->post(route('multas.pagos.verify', $pago), ['bankTransactionId' => $credito->id])
            ->assertSessionHasNoErrors();

        expect($pago->application()->firstOrFail()->timeliness)->toBe(FineTimeliness::OnTime);
    }

    public function test_an_appeal_in_cash_is_presented_now(): void
    {
        $multa = $this->escenario();

        $this->actingAs($this->operador())
            ->post(route('multas.recursos.store', $multa), [
                ...$this->recurso('cash', '280000.00'),
                'presentedOn' => BusinessDate::today()->subDay()->toDateString(), 'presentationEvidence' => 'Nota',
            ])
            ->assertSessionHasErrors('presentedOn');
    }

    public function test_an_appeal_is_paid_only_in_cash_or_by_direct_deposit(): void
    {
        $multa = $this->escenario();

        $this->actingAs($this->operador())
            ->post(route('multas.recursos.store', $multa), $this->recurso('bank_transfer', '280000.00'))
            ->assertSessionHasErrors('medium');
    }

    /** La base congela la base del 20 % y la cuenta de referencia, aunque nadie pase por el Action. */
    public function test_the_database_keeps_the_appeal_base_and_its_twenty_percent(): void
    {
        $multa = $this->escenario();

        $this->rechaza('importe vigente', fn () => DB::table('fine_payments')->insert(
            $this->filaDeRecurso($multa, base: '1000000.00', exigido: '200000.00'),
        ));
        $this->rechaza('fine_payments_appeal_check', fn () => DB::table('fine_payments')->insert(
            $this->filaDeRecurso($multa, base: '1400000.00', exigido: '280001.00'),
        ));
    }

    // ── Escenario ───────────────────────────────────────────────────────

    private function escenario(string $registrada = '2026-05-07'): Fine
    {
        $this->laCuenta = $this->cuenta();
        $this->abrirMultas($this->laCuenta, '2026-06-01');

        return $this->multa(registrada: $registrada);
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

    private function multa(string $expediente = '169607/24-0', string $registrada = '2026-05-07'): Fine
    {
        $this->actingAs($this->operador())->post(route('multas.store'), [
            'caseNumber' => $expediente,
            'resolution' => ['text' => 'N°0649/26', 'issuedOn' => '2026-05-07'],
            'acts' => [['actType' => 'HYS', 'text' => 'N°1089657', 'issuedOn' => null]],
            'caption' => 'CRISTOMAX S.A.S',
            'registeredOn' => $registrada,
            'amount' => '1400000.00',
        ])->assertSessionHasNoErrors();

        return Fine::query()->latest('id')->firstOrFail();
    }

    private function informar(Fine $multa, string $importe): FinePayment
    {
        return app(ReportFineBankPayment::class)->handle(
            $multa, FinePaymentMedium::BankTransfer, $importe, (string) Str::uuid(), $this->quienAbre(),
        );
    }

    private function recurrir(Fine $multa, FinePaymentMedium $medio, string $externo): FinePayment
    {
        return app(RegisterFineAppeal::class)->handle(
            $multa, AppealKind::Hierarchical, $medio, 'R-2026-0042', $externo, (string) Str::uuid(), $this->quienAbre(),
        );
    }

    /** @return array<string, mixed> */
    private function informe(string $importe): array
    {
        return ['medium' => 'bank_transfer', 'amount' => $importe, 'idempotencyKey' => (string) Str::uuid()];
    }

    /** @return array<string, mixed> */
    private function recurso(string $medio, string $externo): array
    {
        return [
            'kind' => 'hierarchical', 'medium' => $medio, 'externalReference' => 'R-2026-0042',
            'amount' => $externo, 'idempotencyKey' => (string) Str::uuid(),
        ];
    }

    /** @return array<string, mixed> */
    private function filaDeRecurso(Fine $multa, string $base, string $exigido): array
    {
        $ahora = CarbonImmutable::now();

        return [
            'fine_id' => $multa->id, 'medium' => 'direct_deposit', 'status' => 'reported', 'amount' => $exigido,
            'presented_at' => $ahora, 'presented_precision' => 'instant', 'reported_at' => $ahora,
            'payer_name' => 'CRISTOMAX S.A.S', 'appeal_kind' => 'appeal', 'appeal_base_amount' => $base,
            'appeal_required_amount' => $exigido, 'external_receipt_reference' => 'R-1',
            'idempotency_key' => (string) Str::uuid(), 'created_at' => $ahora, 'updated_at' => $ahora,
        ];
    }

    /** @return array<string, mixed> */
    private function filaInformada(Fine $multa): array
    {
        $ahora = CarbonImmutable::now();

        return [
            'fine_id' => $multa->id, 'medium' => 'bank_transfer', 'status' => 'reported', 'amount' => '1000.00',
            'presented_at' => $ahora, 'presented_precision' => 'instant', 'reported_at' => $ahora,
            'payer_name' => 'CRISTOMAX S.A.S', 'idempotency_key' => (string) Str::uuid(),
            'created_at' => $ahora, 'updated_at' => $ahora,
        ];
    }

    private function saldo(Fine $multa, string $columna): string
    {
        return (string) DB::table('fine_balances')->where('fine_id', $multa->id)->value($columna);
    }

    /** @return array<string, string> */
    private function libro(): array
    {
        return DB::table('journal_lines')
            ->where('cash_box_id', $this->cajaDe(CashBox::MULTAS))
            ->groupBy('account_code')
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
