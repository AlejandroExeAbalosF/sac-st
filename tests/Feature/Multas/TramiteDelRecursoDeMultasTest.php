<?php

declare(strict_types=1);

namespace Tests\Feature\Multas;

use App\Modules\Banking\Models\BankAccount;
use App\Modules\Multas\Actions\AdjustFineDebt;
use App\Modules\Multas\Actions\CollectFinePayment;
use App\Modules\Multas\Actions\RecordFineHistoricalMovement;
use App\Modules\Multas\Actions\RegisterFineAppeal;
use App\Modules\Multas\Actions\ReportFineBankPayment;
use App\Modules\Multas\Actions\ResolveFineAppeal;
use App\Modules\Multas\Actions\ReverseFineDebtEntry;
use App\Modules\Multas\Enums\AppealKind;
use App\Modules\Multas\Enums\FineAppealStatus;
use App\Modules\Multas\Enums\FineDebtStatus;
use App\Modules\Multas\Enums\FinePaymentMedium;
use App\Modules\Multas\Enums\FinePaymentStatus;
use App\Modules\Multas\Enums\FineReasonCode;
use App\Modules\Multas\Enums\HistoricalMovementKind;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineAppeal;
use App\Modules\Multas\Models\FineBalance;
use App\Modules\Multas\Models\FineDebtEntry;
use App\Modules\Multas\Models\FinePayment;
use App\Modules\Multas\Support\PartialDate;
use App\Modules\Shared\Support\Reason;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\Concerns\BuildsSharedBankAccount;
use Tests\TestCase;

/**
 * El trámite del recurso del 20 % (decisión del área, 03/11/2026): el
 * recurso es el primer pago, congela la multa hasta el veredicto y cae si
 * se descarta su depósito. Cada regla, en el Action y en la base.
 */
class TramiteDelRecursoDeMultasTest extends TestCase
{
    use BuildsSharedBankAccount;
    use RefreshDatabase;

    private BankAccount $laCuenta;

    // ── Admisión ────────────────────────────────────────────────────────

    public function test_registering_an_appeal_opens_its_proceeding_and_freezes_the_fine(): void
    {
        $multa = $this->escenario();
        $pago = $this->recurrir($multa, FinePaymentMedium::DirectDeposit, '280000.00');

        $recurso = FineAppeal::query()->sole();
        expect($recurso->status)->toBe(FineAppealStatus::Open)
            ->and($pago->fine_appeal_id)->toBe($recurso->id)
            ->and($this->estado($multa))->toBe(FineDebtStatus::InAppeal);
    }

    public function test_a_fine_with_an_earlier_payment_is_not_appealed(): void
    {
        $multa = $this->escenario();
        $this->cobrar($multa, '1000.00');

        $this->rechazaElAction('kind', fn () => $this->recurrir($multa, FinePaymentMedium::Cash, '280000.00'));
        $this->rechazaLaBase('el recurso se registra antes de cualquier pago', fn () => $this->abrirTramite($multa));
    }

    public function test_a_reported_payment_or_a_historical_one_also_count_as_earlier_payments(): void
    {
        $informada = $this->escenario();
        $this->informar($informada, '1000.00');
        $anterior = $this->multa('169608/24-0');
        app(RecordFineHistoricalMovement::class)->handle(
            $anterior, HistoricalMovementKind::Payment, '1000.00', PartialDate::parse('2026-05'), 'Planilla de 2026', $this->quienAbre(),
        );

        $this->rechazaElAction('kind', fn () => $this->recurrir($informada, FinePaymentMedium::Cash, '280000.00'));
        $this->rechazaElAction('kind', fn () => $this->recurrir($anterior, FinePaymentMedium::Cash, '280000.00'));
        $this->rechazaLaBase('el recurso se registra antes de cualquier pago', fn () => $this->abrirTramite($anterior));
    }

    public function test_a_discarded_attempt_does_not_prevent_the_appeal(): void
    {
        $multa = $this->escenario();
        $intento = $this->informar($multa, '1000.00');

        $this->actingAs($this->operador('contador'))
            ->post(route('multas.pagos.discard', $intento), ['reasonCode' => 'wrong_fine', 'reason' => 'Era de otra multa'])
            ->assertSessionHasNoErrors();

        expect($this->recurrir($multa, FinePaymentMedium::Cash, '280000.00')->status)->toBe(FinePaymentStatus::Applied);
    }

    /** Sin deuda no hay recurso: tampoco en una multa sin pagos que quedó sin efecto. */
    public function test_a_fine_without_effect_is_not_appealed(): void
    {
        $multa = $this->escenario();
        $this->corregirImporte($multa, '0.00');

        $this->rechazaElAction('amount', fn () => $this->recurrir($multa, FinePaymentMedium::Cash, '280000.00'));
        $this->rechazaLaBase('no tiene saldo pendiente', fn () => $this->abrirTramite($multa));
    }

    // ── Congelamiento ───────────────────────────────────────────────────

    public function test_an_appealed_fine_admits_no_other_payment(): void
    {
        $multa = $this->escenario();
        $this->recurrir($multa, FinePaymentMedium::DirectDeposit, '280000.00');

        $this->rechazaElAction('amount', fn () => $this->cobrar($multa, '1000.00'));
        $this->rechazaElAction('amount', fn () => $this->informar($multa, '1000.00'));
        $this->rechazaElAction('amount', fn () => app(RecordFineHistoricalMovement::class)->handle(
            $multa, HistoricalMovementKind::Payment, '1000.00', PartialDate::parse('2026-05'), 'Planilla', $this->quienAbre(),
        ));
        $this->rechazaLaBase('no admite otros pagos', fn () => DB::table('fine_payments')->insert($this->filaInformada($multa)));
        $this->rechazaLaBase('no admite antecedentes', fn () => DB::table('fine_historical_movements')->insert([
            'fine_id' => $multa->id, 'kind' => 'payment', 'amount' => '1000.00', 'occurred_precision' => 'unknown',
            'evidence_text' => 'Planilla', 'status' => 'pending_review', 'provenance' => 'manual', 'created_at' => now(),
        ]));
    }

    public function test_an_appealed_fine_admits_no_amount_change(): void
    {
        $multa = $this->escenario();
        $ajuste = $this->corregirImporte($multa, '1500000.00');
        $this->recurrir($multa, FinePaymentMedium::DirectDeposit, '300000.00');

        $this->rechazaElAction('newAmount', fn () => $this->corregirImporte($multa, '1400000.00'));
        $this->rechazaElAction('reason', fn () => app(ReverseFineDebtEntry::class)->handle(
            $ajuste, new Reason(FineReasonCode::DataEntryError, 'Se cargó mal'), $this->quienAbre(),
        ));
        $this->rechazaLaBase('no admite cambios de importe', fn () => DB::table('fine_debt_entries')->insert(
            $this->filaDeReduccion($multa, '1000.00'),
        ));
    }

    /** El depósito del propio recurso sigue su curso: se verifica, y se corrige dentro del mismo trámite. */
    public function test_the_appeal_deposit_is_verified_and_corrected_within_its_proceeding(): void
    {
        $multa = $this->escenario();
        $anterior = $this->recurrir($multa, FinePaymentMedium::DirectDeposit, '28000.00');

        $this->actingAs($this->operador())->post(route('multas.recursos.store', $multa), [
            'kind' => 'hierarchical', 'medium' => 'direct_deposit', 'amount' => '280000.00', 'idempotencyKey' => (string) Str::uuid(),
            'replacesPaymentId' => $anterior->id, 'correctionReason' => 'El recibo externo dice 280.000',
        ])->assertSessionHasNoErrors();
        $corregido = FinePayment::query()->where('replaces_fine_payment_id', $anterior->id)->sole();

        $this->verificar($corregido, '280000.00');

        expect($corregido->refresh()->status)->toBe(FinePaymentStatus::Applied)
            ->and($corregido->fine_appeal_id)->toBe($anterior->fine_appeal_id)
            ->and(FineAppeal::query()->sole()->status)->toBe(FineAppealStatus::Open)
            ->and($this->estado($multa))->toBe(FineDebtStatus::InAppeal);
    }

    // ── Caída ───────────────────────────────────────────────────────────

    public function test_discarding_the_appeal_deposit_drops_the_appeal_and_thaws_the_fine(): void
    {
        $multa = $this->escenario();
        $pago = $this->recurrir($multa, FinePaymentMedium::DirectDeposit, '280000.00');

        $this->descartar($pago);

        expect(FineAppeal::query()->sole()->status)->toBe(FineAppealStatus::Dropped)
            ->and($this->estado($multa))->toBe(FineDebtStatus::Pending)
            ->and($this->recurrir($multa, FinePaymentMedium::Cash, '280000.00')->status)->toBe(FinePaymentStatus::Applied)
            ->and(FineAppeal::query()->where('status', FineAppealStatus::Open)->count())->toBe(1);
    }

    /** La base: el depósito de un recurso en trámite no se descarta sin que el recurso caiga. */
    public function test_the_database_keeps_an_open_appeal_with_its_payment(): void
    {
        $multa = $this->escenario();
        $pago = $this->recurrir($multa, FinePaymentMedium::DirectDeposit, '280000.00');

        $this->rechazaLaBase('el recurso cae', fn () => DB::table('fine_payments')->where('id', $pago->id)->update([
            'status' => 'discarded', 'discarded_by' => $this->quienAbre(), 'discarded_at' => now(),
            'discard_reason_code' => 'wrong_fine', 'discard_reason' => 'Era de otra multa',
        ]));
    }

    // ── Veredicto ───────────────────────────────────────────────────────

    /**
     * Prospera con lo cobrado según el recibo externo, no con el 20 % de
     * referencia: $ 1.400.000 y un recibo de $ 279.999 reducen $ 1.120.001.
     */
    public function test_an_upheld_appeal_cancels_the_fine_with_what_was_collected(): void
    {
        $multa = $this->escenario();
        $this->recurrir($multa, FinePaymentMedium::Cash, '279999.00');

        $this->actingAs($this->operador())
            ->post(route('multas.recursos.resolve', $multa), $this->veredicto($multa, 'upheld'))
            ->assertSessionHasNoErrors();

        $reduccion = FineDebtEntry::query()->whereNotNull('fine_appeal_verdict_id')->sole();
        expect($reduccion->amount)->toBe('1120001.00')
            ->and($reduccion->reason_code)->toBe(FineReasonCode::AppealAdmitted)
            ->and(FineAppeal::query()->sole()->status)->toBe(FineAppealStatus::Upheld)
            ->and($this->saldo($multa, 'debt'))->toBe('0.00')
            ->and($this->saldo($multa, 'excess'))->toBe('0.00')
            ->and($this->estado($multa))->toBe(FineDebtStatus::Cancelled);
    }

    public function test_an_upheld_appeal_that_already_covers_the_fine_needs_no_reduction(): void
    {
        $multa = $this->escenario();
        $this->recurrir($multa, FinePaymentMedium::Cash, '1500000.00');

        $this->resolver($multa, FineAppealStatus::Upheld);

        expect(FineDebtEntry::query()->whereNotNull('fine_appeal_verdict_id')->exists())->toBeFalse()
            ->and($this->saldo($multa, 'debt'))->toBe('0.00')
            ->and($this->saldo($multa, 'excess'))->toBe('100000.00')
            ->and($this->estado($multa))->toBe(FineDebtStatus::Cancelled);
    }

    public function test_an_appeal_with_an_unverified_deposit_does_not_prosper(): void
    {
        $multa = $this->escenario();
        $this->recurrir($multa, FinePaymentMedium::DirectDeposit, '280000.00');

        $this->rechazaElAction('verdict', fn () => $this->resolver($multa, FineAppealStatus::Upheld));
        expect(FineAppeal::query()->sole()->status)->toBe(FineAppealStatus::Open);
    }

    /** Después de prosperar el importe no se mueve: ni un ajuste ni la reversión del veredicto. */
    public function test_an_upheld_appeal_closes_the_amount(): void
    {
        $multa = $this->escenario();
        $this->recurrir($multa, FinePaymentMedium::Cash, '280000.00');
        $this->resolver($multa, FineAppealStatus::Upheld);
        $reduccion = FineDebtEntry::query()->whereNotNull('fine_appeal_verdict_id')->sole();

        $this->rechazaElAction('newAmount', fn () => $this->corregirImporte($multa, '1400000.00'));
        $this->rechazaElAction('reason', fn () => app(ReverseFineDebtEntry::class)->handle(
            $reduccion, new Reason(FineReasonCode::DataEntryError, 'Se cargó mal'), $this->quienAbre(),
        ));
        $this->rechazaLaBase('prosperó', fn () => DB::table('fine_debt_entries')->insert(
            $this->filaDeReduccion($multa, '1000.00'),
        ));
    }

    public function test_a_rejected_appeal_thaws_the_fine_and_its_payment_stays_on_account(): void
    {
        $multa = $this->escenario();
        $this->recurrir($multa, FinePaymentMedium::Cash, '280000.00');

        $this->actingAs($this->operador())
            ->post(route('multas.recursos.resolve', $multa), $this->veredicto($multa, 'rejected'))
            ->assertSessionHasNoErrors();
        $this->cobrar($multa, '1120000.00');

        expect(FineAppeal::query()->sole()->status)->toBe(FineAppealStatus::Rejected)
            ->and($this->saldo($multa, 'debt'))->toBe('0.00')
            ->and($this->estado($multa))->toBe(FineDebtStatus::Cancelled);
        $this->rechazaElAction('kind', fn () => $this->recurrir($multa, FinePaymentMedium::Cash, '280000.00'));
    }

    /** No prosperó y después se descarta su depósito informado: el veredicto se conserva. */
    /** Los dos veredictos exigen el 20 % cobrado o verificado; la base repite la regla. */
    public function test_a_verdict_needs_the_payment_applied(): void
    {
        $multa = $this->escenario();
        $pago = $this->recurrir($multa, FinePaymentMedium::DirectDeposit, '280000.00');

        $this->rechazaElAction('verdict', fn () => $this->resolver($multa, FineAppealStatus::Rejected));
        $this->rechazaElAction('verdict', fn () => $this->resolver($multa, FineAppealStatus::Upheld));

        $this->rechazaLaBase('con su pago cobrado o verificado', function () use ($multa, $pago): void {
            $this->veredictoDirecto($multa, (int) $pago->fine_appeal_id, 'rejected');
            DB::table('fine_appeals')->where('id', $pago->fine_appeal_id)->update(['status' => 'rejected']);
        });

        expect(FineAppeal::query()->sole()->status)->toBe(FineAppealStatus::Open);
    }

    /** Un formulario viejo no resuelve el recurso que se registró después de que el suyo cayó. */
    public function test_a_stale_form_does_not_resolve_a_newer_appeal(): void
    {
        $multa = $this->escenario();
        $this->recurrir($multa, FinePaymentMedium::DirectDeposit, '280000.00');
        $visto = $this->veredicto($multa, 'rejected');

        $this->descartar(FinePayment::query()->sole());
        $this->recurrir($multa, FinePaymentMedium::Cash, '300000.00');

        $this->actingAs($this->operador())
            ->post(route('multas.recursos.resolve', $multa), $visto)
            ->assertSessionHasErrors('verdict');

        expect(FineAppeal::query()->where('status', FineAppealStatus::Open)->count())->toBe(1);
    }

    /** Tampoco resuelve con un pago que una corrección reemplazó dentro del mismo recurso. */
    public function test_a_stale_form_does_not_resolve_after_the_payment_was_corrected(): void
    {
        $multa = $this->escenario();
        $anterior = $this->recurrir($multa, FinePaymentMedium::DirectDeposit, '28000.00');
        $visto = $this->veredicto($multa, 'rejected');

        app(RegisterFineAppeal::class)->handle(
            $multa, AppealKind::Hierarchical, FinePaymentMedium::DirectDeposit, null, '280000.00', (string) Str::uuid(),
            $this->quienAbre(), replaces: $anterior, correctionReason: 'El recibo externo dice 280.000',
        );

        $this->rechazaElAction('verdict', fn () => app(ResolveFineAppeal::class)->handle(
            $multa, FineAppealStatus::Rejected, $visto['appealId'], $visto['paymentId'], $this->quienAbre(),
        ));
        expect(FineAppeal::query()->sole()->status)->toBe(FineAppealStatus::Open);
    }

    public function test_only_those_who_collect_resolve_the_appeal(): void
    {
        $multa = $this->escenario();
        $this->recurrir($multa, FinePaymentMedium::Cash, '280000.00');

        $this->actingAs($this->operador('contador'))
            ->post(route('multas.recursos.resolve', $multa), $this->veredicto($multa, 'upheld'))
            ->assertForbidden();
    }

    public function test_the_fine_screen_shows_the_proceeding(): void
    {
        $multa = $this->escenario();

        $this->actingAs($this->operador())->get(route('multas.show', $multa->showParameters()))
            ->assertInertia(fn (Assert $page) => $page->where('recurso.allowed', true)->where('recurso.status', null));

        $this->recurrir($multa, FinePaymentMedium::DirectDeposit, '280000.00');

        $this->get(route('multas.show', $multa->showParameters()))
            ->assertInertia(fn (Assert $page) => $page
                ->where('recurso.allowed', false)
                ->where('recurso.status', 'open')
                ->where('recurso.paidAmount', '280000.00')
                ->where('recurso.paymentApplied', false)
                ->where('multa.balance.status', 'in_appeal')
                ->where('can.resolveAppeal', true));

        $otra = $this->multa('169608/24-0');

        $this->get(route('multas.index', ['estado' => 'en-recurso', 'vista' => 'multas']))
            ->assertInertia(fn (Assert $page) => $page->has('multas', 1)->where('multas.0.id', $multa->id));
        $this->get(route('multas.index', ['estado' => 'en-recurso', 'vista' => 'expedientes']))
            ->assertInertia(fn (Assert $page) => $page->has('expedientes', 1)->where('expedientes.0.id', $multa->fine_case_id));
        expect($otra->fine_case_id)->not->toBe($multa->fine_case_id);
    }

    // ── La base, por su cuenta ──────────────────────────────────────────

    /** El recurso, su pago y su reducción son de la misma multa. */
    public function test_the_database_keeps_the_appeal_within_its_fine(): void
    {
        $multa = $this->escenario();
        $otra = $this->multa('169608/24-0');
        // Cubre la multa entera: al prosperar no hay reducción propia que ocupe el índice único.
        $recurso = $this->recurrir($multa, FinePaymentMedium::Cash, '1500000.00')->fine_appeal_id;

        $this->rechazaLaBase('fine_payments_appeal_fk', fn () => DB::table('fine_payments')->insert(
            [...$this->filaDeRecurso($otra), 'fine_appeal_id' => $recurso],
        ));
        // Con el recurso ya resuelto, la reducción que lo cita en otra multa llega hasta la clave foránea.
        $this->resolver($multa, FineAppealStatus::Upheld);
        $veredicto = (int) DB::table('fine_appeal_verdicts')->where('fine_appeal_id', $recurso)->value('id');
        $this->rechazaLaBase('fine_debt_entries_verdict_fk', fn () => DB::table('fine_debt_entries')->insert(
            [...$this->filaDeReduccion($otra, '1400000.00'), 'fine_appeal_verdict_id' => $veredicto, 'reason' => null, 'reason_code' => 'appeal_admitted'],
        ));
    }

    /** La reducción del veredicto: solo de un recurso que prosperó, una sola y exactamente hasta lo cobrado. */
    public function test_the_database_keeps_the_verdict_reduction_exact(): void
    {
        $multa = $this->escenario();
        $recurso = $this->recurrir($multa, FinePaymentMedium::Cash, '280000.00')->fine_appeal_id;
        $reduccion = fn (int $veredicto, string $importe): array => [
            ...$this->filaDeReduccion($multa, $importe), 'fine_appeal_verdict_id' => $veredicto, 'reason' => null, 'reason_code' => 'appeal_admitted',
        ];

        $this->rechazaLaBase('solo un recurso que prosperó', function () use ($multa, $recurso, $reduccion): void {
            $veredicto = $this->veredictoDirecto($multa, $recurso, 'rejected');
            DB::table('fine_appeals')->where('id', $recurso)->update(['status' => 'rejected']);
            DB::table('fine_debt_entries')->insert($reduccion($veredicto, '1120000.00'));
        });

        $this->rechazaLaBase('lleva la multa a lo cobrado', function () use ($multa, $recurso, $reduccion): void {
            $veredicto = $this->veredictoDirecto($multa, $recurso, 'upheld');
            DB::table('fine_appeals')->where('id', $recurso)->update(['status' => 'upheld']);
            DB::table('fine_debt_entries')->insert($reduccion($veredicto, '1000000.00'));
        });

        $this->rechazaLaBase('deja la multa cancelada', function () use ($multa, $recurso): void {
            $this->veredictoDirecto($multa, $recurso, 'upheld');
            DB::table('fine_appeals')->where('id', $recurso)->update(['status' => 'upheld']);
        });

        // Una segunda reducción ya no lleva la multa a lo cobrado: se rechaza antes del índice único.
        $this->resolver($multa, FineAppealStatus::Upheld);
        $vigente = (int) DB::table('fine_appeal_verdicts')->where('fine_appeal_id', $recurso)->value('id');
        $this->rechazaLaBase('lleva la multa a lo cobrado', fn () => DB::table('fine_debt_entries')->insert($reduccion($vigente, '0.01')));
    }

    /** Una corrección queda en el mismo recurso; un recurso resuelto no vuelve a abrirse sin revertir su veredicto. */
    public function test_the_database_keeps_corrections_and_verdicts_in_place(): void
    {
        $multa = $this->escenario();
        $pago = $this->recurrir($multa, FinePaymentMedium::DirectDeposit, '280000.00');
        $otra = $this->multa('169608/24-0');
        $ajeno = $this->recurrir($otra, FinePaymentMedium::DirectDeposit, '280000.00')->fine_appeal_id;

        $this->rechazaLaBase('mismo recurso', fn () => DB::table('fine_payments')->insert(
            [...$this->filaDeRecurso($otra), 'fine_appeal_id' => $ajeno, 'replaces_fine_payment_id' => $pago->id],
        ));

        $this->verificar($pago, '280000.00');
        $this->resolver($multa, FineAppealStatus::Rejected);
        $this->rechazaLaBase('no pasa a', fn () => DB::table('fine_appeals')->where('fine_id', $multa->id)->update(['status' => 'upheld']));
        $this->rechazaLaBase('sin resolver no tiene un veredicto vigente', fn () => DB::table('fine_appeals')->where('fine_id', $multa->id)->update(['status' => 'open']));
        $this->rechazaLaBase('no se borra', fn () => DB::table('fine_appeals')->where('fine_id', $multa->id)->delete());
    }

    // ── Migración de los recursos existentes ────────────────────────────

    /** Una cadena de correcciones es un solo trámite; un recurso descartado suelto, uno que cayó. */
    public function test_the_migration_turns_each_correction_chain_into_one_proceeding(): void
    {
        $multa = $this->escenario();
        $otra = $this->multa('169608/24-0');
        $migracion = $this->migracionDelTramite();

        $original = DB::table('fine_payments')->insertGetId($this->filaDeRecurso($multa));
        $this->descartarDirecto($original);
        $corregido = DB::table('fine_payments')->insertGetId([...$this->filaDeRecurso($multa), 'replaces_fine_payment_id' => $original]);
        $suelto = DB::table('fine_payments')->insertGetId($this->filaDeRecurso($otra));
        $this->descartarDirecto($suelto);

        // Como corre `artisan migrate`: con las verificaciones diferidas, el backfill las deja pendientes.
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
        $migracion->up();

        $tramite = FineAppeal::query()->where('fine_id', $multa->id)->sole();
        expect($tramite->status)->toBe(FineAppealStatus::Open)
            ->and(FinePayment::query()->whereIn('id', [$original, $corregido])->pluck('fine_appeal_id')->unique()->all())->toBe([$tramite->id])
            ->and(FineAppeal::query()->where('fine_id', $otra->id)->sole()->status)->toBe(FineAppealStatus::Dropped)
            ->and(FinePayment::query()->findOrFail($suelto)->fine_appeal_id)->not->toBeNull();
    }

    /** Un recurso vivo que convive con otro pago contradice la regla: la migración se detiene y nombra la multa. */
    public function test_the_migration_stops_on_an_appeal_alongside_other_payments(): void
    {
        $multa = $this->escenario();
        $migracion = $this->migracionDelTramite();

        DB::table('fine_payments')->insert($this->filaInformada($multa));
        DB::table('fine_payments')->insert($this->filaDeRecurso($multa));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("multas {$multa->id}");

        $migracion->up();
    }

    // ── Escenario ───────────────────────────────────────────────────────

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

    private function recurrir(Fine $multa, FinePaymentMedium $medio, string $externo): FinePayment
    {
        return app(RegisterFineAppeal::class)->handle(
            $multa, AppealKind::Hierarchical, $medio, 'R-2026-0042', $externo, (string) Str::uuid(), $this->quienAbre(),
        );
    }

    /** Resuelve el recurso que la multa tiene ahora, con su pago vigente. */
    private function resolver(Fine $multa, FineAppealStatus $veredicto): FineAppeal
    {
        $visto = $this->veredicto($multa, $veredicto->value);

        return app(ResolveFineAppeal::class)->handle($multa, $veredicto, $visto['appealId'], $visto['paymentId'], $this->quienAbre());
    }

    /**
     * Lo que envía el diálogo: el veredicto, con el recurso y el pago que se ven.
     *
     * @return array{verdict: string, appealId: int, paymentId: int}
     */
    private function veredicto(Fine $multa, string $veredicto): array
    {
        $recurso = FineAppeal::query()->where('fine_id', $multa->id)->where('status', FineAppealStatus::Open)->sole();

        return [
            'verdict' => $veredicto,
            'appealId' => $recurso->id,
            'paymentId' => $recurso->payments()->where('status', '<>', FinePaymentStatus::Discarded)->sole()->id,
        ];
    }

    private function cobrar(Fine $multa, string $importe): FinePayment
    {
        return app(CollectFinePayment::class)->handle($multa, FinePaymentMedium::Cash, $importe, (string) Str::uuid(), $this->quienAbre());
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

    private function descartar(FinePayment $pago): void
    {
        $this->actingAs($this->operador('contador'))
            ->post(route('multas.pagos.discard', $pago), ['reasonCode' => 'not_credited', 'reason' => 'El banco nunca lo acreditó'])
            ->assertSessionHasNoErrors();
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

    /**
     * La migración, ya revertida junto con la que la sigue: los triggers
     * diferidos de lo cargado se disparan antes, porque PostgreSQL no altera
     * una tabla con eventos pendientes.
     */
    private function migracionDelTramite(): Migration
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $siguiente = require database_path('migrations/2026_11_04_000000_add_fine_payment_voids_and_appeal_verdicts.php');
        $siguiente->down();
        $migracion = require database_path('migrations/2026_11_03_000000_add_fine_appeals.php');
        $migracion->down();

        return $migracion;
    }

    /** Un veredicto escrito directo: lo que la base acepta o no sin pasar por el Action. */
    private function veredictoDirecto(Fine $multa, int $recurso, string $veredicto): int
    {
        return DB::table('fine_appeal_verdicts')->insertGetId([
            'fine_appeal_id' => $recurso, 'fine_id' => $multa->id, 'verdict' => $veredicto,
            'debt_entries_until_id' => 0, 'resolved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function descartarDirecto(int $pago): void
    {
        DB::table('fine_payments')->where('id', $pago)->update([
            'status' => 'discarded', 'discarded_by' => $this->quienAbre(), 'discarded_at' => now(),
            'discard_reason_code' => 'data_entry_error', 'discard_reason' => 'Mal cargado',
        ]);
    }

    /** Abre un trámite sin pasar por el Action, para probar la guarda de la base. */
    private function abrirTramite(Fine $multa): void
    {
        DB::table('fine_appeals')->insert(['fine_id' => $multa->id, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function estado(Fine $multa): FineDebtStatus
    {
        $saldo = FineBalance::query()->whereKey($multa->id)->firstOrFail();

        return FineDebtStatus::of($saldo->obligation, $saldo->debt, $saldo->appeal_status);
    }

    private function saldo(Fine $multa, string $columna): string
    {
        return (string) DB::table('fine_balances')->where('fine_id', $multa->id)->value($columna);
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

    /** @return array<string, mixed> */
    private function filaDeRecurso(Fine $multa): array
    {
        $ahora = CarbonImmutable::now();

        return [
            'fine_id' => $multa->id, 'medium' => 'direct_deposit', 'status' => 'reported', 'amount' => '280000.00',
            'presented_at' => $ahora, 'presented_precision' => 'instant', 'reported_at' => $ahora,
            'payer_name' => 'CRISTOMAX S.A.S', 'appeal_kind' => 'appeal', 'appeal_base_amount' => '1400000.00',
            'appeal_required_amount' => '280000.00', 'idempotency_key' => (string) Str::uuid(), 'created_at' => $ahora, 'updated_at' => $ahora,
        ];
    }

    /** @return array<string, mixed> */
    private function filaDeReduccion(Fine $multa, string $importe): array
    {
        return [
            'fine_id' => $multa->id, 'kind' => 'reduction', 'amount' => $importe,
            'effective_on' => BusinessDate::today()->toDateString(), 'effective_precision' => 'day',
            'reason_code' => 'data_entry_error', 'reason' => 'Corrección directa', 'provenance' => 'manual', 'created_at' => now(),
        ];
    }

    /** @param  Closure(): mixed  $accion */
    private function rechazaElAction(string $campo, Closure $accion): void
    {
        try {
            $accion();
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey($campo);

            return;
        }

        $this->fail("El Action aceptó lo que tenía que rechazar ({$campo}).");
    }

    /** @param  Closure(): mixed  $accion */
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
