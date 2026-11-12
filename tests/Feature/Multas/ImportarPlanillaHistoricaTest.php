<?php

declare(strict_types=1);

namespace Tests\Feature\Multas;

use App\Models\User;
use App\Modules\Ledger\Models\FinancialEvent;
use App\Modules\Multas\Actions\ConsolidateFineImportRow;
use App\Modules\Multas\Actions\ResolveFineImportCell;
use App\Modules\Multas\Actions\ResolveFineImportIssue;
use App\Modules\Multas\Actions\ResolveFineImportIssueInBulk;
use App\Modules\Multas\Actions\ReviewFineHistoricalMovement;
use App\Modules\Multas\Actions\StartFineImport;
use App\Modules\Multas\Actions\StartFineImportConsolidation;
use App\Modules\Multas\Enums\FineImportAction;
use App\Modules\Multas\Enums\FineImportCellStatus;
use App\Modules\Multas\Enums\FineImportIssue;
use App\Modules\Multas\Enums\FineImportStatus;
use App\Modules\Multas\Enums\FineProvenance;
use App\Modules\Multas\Enums\FineReasonCode;
use App\Modules\Multas\Enums\HistoricalMovementKind;
use App\Modules\Multas\Enums\HistoricalMovementStatus;
use App\Modules\Multas\Import\RowClassifier;
use App\Modules\Multas\Import\SheetLayout;
use App\Modules\Multas\Models\Fine;
use App\Modules\Multas\Models\FineHistoricalMovement;
use App\Modules\Multas\Models\FineImportBatch;
use App\Modules\Multas\Models\FineImportCell;
use App\Modules\Multas\Models\FineImportRow;
use Closure;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use RuntimeException;
use Tests\Concerns\BuildsSharedBankAccount;
use Tests\TestCase;

/**
 * La importación de la planilla histórica de Multas (Etapa 9; decisiones del
 * usuario, 11/11/2026): lectura, problemas por columna, decisiones, consolidación
 * y reimportación, cada regla en el Action y en la base.
 */
class ImportarPlanillaHistoricaTest extends TestCase
{
    use BuildsSharedBankAccount;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['multas.historical_import_enabled' => true]);
    }

    /** En pausa: ni las rutas ni las Actions de entrada responden. */
    public function test_a_paused_import_is_closed(): void
    {
        config(['multas.historical_import_enabled' => false]);
        $this->abrirMultas($this->cuenta(), '2026-06-01');
        $admin = User::query()->findOrFail($this->quienAbre());

        $this->actingAs($admin)->get(route('multas.importaciones.index'))->assertNotFound();
        $this->actingAs($admin)->get(route('multas.index'))
            ->assertInertia(fn ($page) => $page->where('canImport', false));
        $this->rechazaElAction('file', fn () => app(StartFineImport::class)->handle($this->planillaSimple(), $this->quienAbre()));
    }

    // ── Clasificación ───────────────────────────────────────────────────

    /** Los meses vienen como fecha o como texto, y el acta puede no tener título. */
    public function test_the_layout_reads_text_months_and_an_untitled_act(): void
    {
        $layout = SheetLayout::detect([
            'A' => 'FECHA', 'B' => 'EXPTE.', 'C' => 'RESOL.', 'D' => '', 'E' => 'CARATULA', 'F' => 'IMPORTE',
            'G' => '2018-01-01', 'H' => 'SET-08', 'I' => 'En-15', 'J' => 'AGO.18', 'K' => 'SET-2018', 'L' => 'TOTAL PAGADO', 'M' => 'SALDO', 'N' => 'PLAN DE PAGOS', 'O' => 'RESTITUCION DE MULTAS',
        ]);

        expect($layout)->not->toBeNull()
            ->and($layout?->act)->toBe('D')
            ->and($layout?->months)->toBe(['G' => '2018-01-01', 'H' => '2008-09-01', 'I' => '2015-01-01', 'J' => '2018-08-01', 'K' => '2018-09-01'])
            ->and($layout?->total)->toBe('L')
            ->and($layout?->balance)->toBe('M')
            ->and($layout?->plan)->toBe('N')
            ->and($layout?->refund)->toBe('O');
    }

    /** Una fila limpia: pagos por mes, controles en orden, sin problemas que bloqueen. */
    public function test_a_clean_row_has_its_payments_and_no_blocking_issue(): void
    {
        $fila = $this->clasificar(['G' => '1000', 'H' => '500', 'L' => '1500', 'M' => '1500']);

        expect($fila['kind'])->toBe('fine')
            ->and($fila['identity_key'])->toBe('221681/2015/0|29/2018')
            ->and($fila['normalized']['payments'])->toBe([
                ['column' => 'G', 'month' => '2018-01-01', 'amount' => '1000.00'],
                ['column' => 'H', 'month' => '2018-09-01', 'amount' => '500.00'],
            ])
            ->and($this->bloqueos($fila))->toBe([]);
    }

    /** Cada control dice en qué columna está el problema. */
    public function test_each_issue_names_its_column(): void
    {
        expect($this->codigos($this->clasificar(['G' => '1000', 'L' => '900', 'M' => '2000'])))->toContain('total_mismatch')
            ->and($this->codigos($this->clasificar(['G' => '1000', 'L' => '1000'])))->toContain('balance_missing')
            ->and($this->codigos($this->clasificar(['H' => 'JUICIO APREMIO', 'L' => '0', 'M' => '3000'])))->toContain('note_in_payment')
            ->and($this->codigos($this->clasificar(['G' => '-100', 'L' => '-100', 'M' => '3100'])))->toContain('negative_payment')
            ->and($this->codigos($this->clasificar(['G' => '3125', 'L' => '3125', 'M' => '-125'])))->toContain('payment_exceeds_amount')
            ->and($this->codigos($this->clasificar(['N' => '500', 'L' => '0', 'M' => '3000'])))->toContain('plan_column')
            ->and($this->codigos($this->clasificar(['Q' => 'algo', 'L' => '0', 'M' => '3000'])))->toContain('unknown_column');

        $problema = collect($this->clasificar(['G' => '1000', 'L' => '900', 'M' => '2000'])['issues'])->firstWhere('code', 'total_mismatch');
        expect($problema['column'])->toBe('L')->and($problema['value'])->toBe('900');
    }

    /** Una fórmula sin resultado guardado no es un cero. */
    public function test_a_formula_without_result_is_not_a_zero(): void
    {
        $fila = (new RowClassifier)->classify($this->layout(), '2018', 5, [
            ...$this->identidad(),
            'L' => ['value' => '', 'formula' => '=SUM(G5:K5)', 'missing' => true],
            'M' => ['value' => '3000', 'formula' => null, 'missing' => false],
        ], '2026-06-01');

        $problema = collect($fila['issues'])->firstWhere('code', 'formula_without_result');
        expect($problema['column'])->toBe('L')->and($problema['value'])->toBe('=SUM(G5:K5)');
    }

    /** Desde el mes de la apertura, el pago no se importa: queda para identificar. */
    public function test_a_payment_from_the_opening_month_is_left_to_identify(): void
    {
        $fila = $this->clasificar(['G' => '1000', 'K' => '500', 'L' => '1500', 'M' => '1500'], corte: '2018-09-01');

        expect($fila['normalized']['payments'])->toHaveCount(1)
            ->and($fila['normalized']['after_cutoff'])->toBe([['column' => 'K', 'month' => '2018-09-01', 'amount' => '500.00']])
            ->and($this->bloqueos($fila))->toBe([])
            ->and($this->codigos($fila))->toContain('after_cutoff');
    }

    /** Sin año, la identidad queda incompleta y el texto se conserva; el año de la hoja no se usa. */
    public function test_an_incomplete_identity_keeps_the_text(): void
    {
        $fila = (new RowClassifier)->classify($this->layout(), '2018', 5, [
            'A' => $this->celda('2018-01-08'), 'B' => $this->celda('82394'), 'C' => $this->celda('2773'),
            'D' => $this->celda('RUBRICA'), 'E' => $this->celda('HABIR S.A.'), 'F' => $this->celda('800'),
            'L' => $this->celda('0'), 'M' => $this->celda('800'),
        ], '2026-06-01');

        expect($fila['identity_key'])->toBeNull()
            ->and($fila['normalized']['case'])->toBe(['number' => 82394, 'year' => null, 'suffix' => null])
            ->and($fila['normalized']['resolution'])->toBe(['number' => 2773, 'year' => null, 'type' => null])
            ->and($this->codigos($fila))->toContain('case_incomplete', 'resolution_incomplete', 'act_incomplete')
            ->and($this->bloqueos($fila))->toBe([]);
    }

    /** Los pies de control no son multas: con rótulo en cualquier columna, o sin nada que identifique. */
    public function test_footers_are_control_rows(): void
    {
        $clasificador = new RowClassifier;

        expect($clasificador->classify($this->layout(), '2018', 9, ['E' => $this->celda('TOTALES'), 'F' => $this->celda('9000')], '2026-06-01')['kind'])->toBe('footer')
            ->and($clasificador->classify($this->layout(), '2018', 10, ['F' => $this->celda('9000'), 'G' => $this->celda('100')], '2026-06-01')['kind'])->toBe('footer')
            ->and($clasificador->classify($this->layout(), '2018', 11, ['F' => $this->celda('0')], '2026-06-01')['kind'])->toBe('empty');
    }

    // ── Recorrido completo ──────────────────────────────────────────────

    /** Subir, leer, decidir, consolidar: multas e historia sin asientos ni recibos. */
    public function test_the_whole_import(): void
    {
        $this->abrirMultas($this->cuenta(), '2026-06-01');
        $manual = $this->multaManual();
        $eventos = FinancialEvent::query()->count();

        $lote = $this->subir($this->planillaBase());

        expect($lote->status)->toBe(FineImportStatus::Ready)
            ->and($lote->cutoff_month->toDateString())->toBe('2026-06-01')
            ->and(FineImportRow::query()->where('kind', 'fine')->count())->toBe(8)
            ->and(FineImportRow::query()->where('kind', 'footer')->count())->toBe(2);

        $pie = collect($lote->reconciliation)->firstWhere('sheet', '2018')['footers'][0];
        expect($pie['amount_matches'])->toBeTrue()->and($pie['paid_matches'])->toBeTrue();

        expect($this->fila(4)->blockingIssues())->toBe([FineImportIssue::TotalMismatch])
            ->and($this->fila(5)->blockingIssues())->toBe([FineImportIssue::PaymentExceedsAmount])
            ->and($this->fila(7)->blockingIssues())->toBe([FineImportIssue::NoteInPayment])
            ->and($this->fila(8)->blockingIssues())->toBe([FineImportIssue::DuplicateManual])
            ->and($this->fila(9)->blockingIssues())->toBe([FineImportIssue::PlanColumn]);

        // No se consolida con problemas sin decidir: lo dice el Action y lo repite la base.
        $this->rechazaLaBase('problemas sin resolver', function () use ($lote): void {
            DB::table('fine_import_batches')->where('id', $lote->id)->update(['status' => 'consolidating']);
            DB::table('fine_import_rows')->where('id', $this->fila(7)->id)->update(['fine_id' => $this->multaManual('169608/24-0')->id, 'consolidated_at' => now()]);
        });

        $resolver = app(ResolveFineImportIssue::class);
        expect(app(ResolveFineImportIssueInBulk::class)->handle($lote, FineImportIssue::TotalMismatch, FineImportAction::Accept, 'La planilla sumó mal el total', $this->quienAbre()))->toBe(1);
        $resolver->handle($this->fila(5), FineImportIssue::PaymentExceedsAmount, FineImportAction::Accept, 'Pagó con intereses', $this->quienAbre());
        $resolver->handle($this->fila(7), FineImportIssue::NoteInPayment, FineImportAction::WithoutPayments, null, $this->quienAbre());
        $resolver->handle($this->fila(8), FineImportIssue::DuplicateManual, FineImportAction::Link, null, $this->quienAbre());
        $resolver->handle($this->fila(9), FineImportIssue::PlanColumn, FineImportAction::IgnoreColumns, null, $this->quienAbre());

        app(StartFineImportConsolidation::class)->handle($lote, $this->quienAbre());

        expect($lote->refresh()->status)->toBe(FineImportStatus::Consolidated)
            ->and(FinancialEvent::query()->count())->toBe($eventos)
            ->and(Fine::query()->where('provenance', FineProvenance::Import)->count())->toBe(7)
            ->and($this->fila(8)->fine_id)->toBe($manual->id)
            ->and(DB::table('receipts')->count())->toBe(0);

        // Limpia: pagos provisionales que cuentan.
        $limpia = $this->fila(2)->fine_id;
        expect($this->saldo($limpia, 'historical_paid'))->toBe('1500.00')
            ->and($this->saldo($limpia, 'debt'))->toBe('1500.00')
            ->and(FineHistoricalMovement::query()->where('fine_id', $limpia)->pluck('status')->unique()->all())->toBe([HistoricalMovementStatus::Provisional])
            ->and(FineHistoricalMovement::query()->where('fine_id', $limpia)->orderBy('occurred_on')->value('evidence_text'))->toBe('Planilla 2018, fila 2, columna G (01/2018)');

        // Con pago desde la apertura: no entra, queda pendiente de identificar y la deuda lo muestra.
        $conPosterior = $this->fila(6)->fine_id;
        expect($this->saldo($conPosterior, 'historical_paid'))->toBe('1000.00')
            ->and($this->saldo($conPosterior, 'debt'))->toBe('2000.00')
            ->and(FineImportCell::query()->sole()->status)->toBe(FineImportCellStatus::Pending);

        // Sin pagos por la nota; con intereses aceptados queda excedente anterior.
        expect($this->saldo($this->fila(7)->fine_id, 'historical_paid'))->toBe('0.00')
            ->and($this->saldo($this->fila(5)->fine_id, 'excess'))->toBe('1425.00');

        app(ResolveFineImportCell::class)->handle(FineImportCell::query()->sole(), FineImportCellStatus::Identified, 'Transferencia del 05/06/2026, verificada en el sistema', $this->quienAbre());
        expect(FineImportCell::query()->sole()->status)->toBe(FineImportCellStatus::Identified);
    }

    /** Una segunda importación del mismo archivo no duplica: lo ya importado se saltea. */
    public function test_importing_again_does_not_duplicate(): void
    {
        $this->abrirMultas($this->cuenta(), '2026-06-01');
        $this->importarYConsolidar($this->planillaSimple());
        $multas = Fine::query()->count();

        $segundo = $this->subir($this->planillaSimple());

        $fila = FineImportRow::query()->where('fine_import_batch_id', $segundo->id)->where('row_number', 2)->sole();
        expect($fila->hasIssue(FineImportIssue::Unchanged))->toBeTrue()
            ->and($fila->blockingIssues())->toBe([]);

        $this->rechazaElAction('batch', fn () => app(StartFineImportConsolidation::class)->handle($segundo, $this->quienAbre()));
        expect(Fine::query()->count())->toBe($multas);
    }

    /** Un mes nuevo se agrega a la multa ya importada; un cambio de importe, no. */
    public function test_a_later_file_adds_only_the_new_months(): void
    {
        $this->abrirMultas($this->cuenta(), '2026-06-01');
        $this->importarYConsolidar($this->planillaSimple());
        $multa = FineImportRow::query()->where('row_number', 2)->value('fine_id');

        $segundo = $this->subir($this->planillaSimple(conFebrero: true));
        $fila = FineImportRow::query()->where('fine_import_batch_id', $segundo->id)->where('row_number', 2)->sole();

        expect($fila->blockingIssues())->toBe([FineImportIssue::Changed]);

        app(ResolveFineImportIssue::class)->handle($fila, FineImportIssue::Changed, FineImportAction::AddMonths, null, $this->quienAbre());
        app(StartFineImportConsolidation::class)->handle($segundo, $this->quienAbre());

        expect($this->saldo($multa, 'historical_paid'))->toBe('1700.00')
            ->and(FineHistoricalMovement::query()->where('fine_id', $multa)->count())->toBe(2);

        $tercero = $this->subir($this->planillaSimple(importe: '4000'));
        $cambiada = FineImportRow::query()->where('fine_import_batch_id', $tercero->id)->where('row_number', 2)->sole();

        $this->rechazaElAction('action', fn () => app(ResolveFineImportIssue::class)->handle($cambiada, FineImportIssue::Changed, FineImportAction::AddMonths, null, $this->quienAbre()));
    }

    /** Sin la apertura de la caja no hay corte, y no se importa. */
    public function test_importing_needs_the_opening(): void
    {
        $this->rechazaElAction('file', fn () => app(StartFineImport::class)->handle($this->planillaSimple(), $this->quienAbre()));
    }

    // ── Decisiones ──────────────────────────────────────────────────────

    /** Cada problema admite sus acciones; en bloque, solo los que se contradicen en la planilla. */
    public function test_each_issue_admits_only_its_actions(): void
    {
        $this->abrirMultas($this->cuenta(), '2026-06-01');
        $this->multaManual();
        $lote = $this->subir($this->planillaBase());
        $resolver = app(ResolveFineImportIssue::class);

        $this->rechazaElAction('action', fn () => $resolver->handle($this->fila(8), FineImportIssue::DuplicateManual, FineImportAction::Accept, null, $this->quienAbre()));
        $this->rechazaElAction('action', fn () => $resolver->handle($this->fila(7), FineImportIssue::NoteInPayment, FineImportAction::Accept, null, $this->quienAbre()));
        $this->rechazaElAction('action', fn () => app(ResolveFineImportIssueInBulk::class)->handle($lote, FineImportIssue::PaymentExceedsAmount, FineImportAction::Accept, null, $this->quienAbre()));
        $this->rechazaElAction('reason', fn () => $resolver->handle($this->fila(4), FineImportIssue::TotalMismatch, FineImportAction::Exclude, null, $this->quienAbre()));

        // La base repite la lista.
        $this->rechazaLaBase('no se resuelve con', fn () => DB::table('fine_import_resolutions')->insert([
            'fine_import_row_id' => $this->fila(7)->id, 'issue_code' => 'note_in_payment', 'action' => 'accept',
        ]));
        $this->rechazaLaBase('no tiene el problema', fn () => DB::table('fine_import_resolutions')->insert([
            'fine_import_row_id' => $this->fila(2)->id, 'issue_code' => 'total_mismatch', 'action' => 'accept',
        ]));
    }

    /** Excluida, una fila no se consolida aunque se intente directo. */
    public function test_an_excluded_row_is_never_consolidated(): void
    {
        $this->abrirMultas($this->cuenta(), '2026-06-01');
        $this->multaManual();
        $lote = $this->subir($this->planillaBase());
        app(ResolveFineImportIssue::class)->handle($this->fila(7), FineImportIssue::NoteInPayment, FineImportAction::Exclude, 'Está en apremio', $this->quienAbre());

        DB::table('fine_import_batches')->where('id', $lote->id)->update(['status' => 'consolidating']);
        expect(app(ConsolidateFineImportRow::class)->handle($this->fila(7), $this->quienAbre()))->toBeFalse();

        $this->rechazaLaBase('se excluyo', fn () => DB::table('fine_import_rows')->where('id', $this->fila(7)->id)
            ->update(['fine_id' => Fine::query()->value('id'), 'consolidated_at' => now()]));
    }

    // ── Pagos provisionales ─────────────────────────────────────────────

    /** Validar un provisional no lo vuelve a descontar; rechazarlo sí cambia el saldo. */
    public function test_reviewing_a_provisional_payment(): void
    {
        $this->abrirMultas($this->cuenta(), '2026-06-01');
        $this->importarYConsolidar($this->planillaSimple(conFebrero: true));
        $multa = (int) FineImportRow::query()->where('row_number', 2)->value('fine_id');
        [$enero, $febrero] = FineHistoricalMovement::query()->where('fine_id', $multa)->orderBy('occurred_on')->get()->all();
        $revisar = app(ReviewFineHistoricalMovement::class);

        $revisar->handle($enero, HistoricalMovementStatus::Validated, null, $this->quienAbre());
        expect($this->saldo($multa, 'historical_paid'))->toBe('1700.00');

        $revisar->handle($febrero, HistoricalMovementStatus::Rejected, 'No figura en el talonario', $this->quienAbre(), FineReasonCode::NoEvidence);
        expect($this->saldo($multa, 'historical_paid'))->toBe('1000.00')
            ->and($this->saldo($multa, 'debt'))->toBe('2000.00');

        $this->rechazaElAction('note', fn () => $revisar->handle($febrero->refresh(), HistoricalMovementStatus::Validated, null, $this->quienAbre()));
    }

    /** Un pago provisional que cubre una devolución no se rechaza: lo dice el Action y lo repite la base. */
    public function test_a_provisional_payment_covering_a_refund_is_not_rejected(): void
    {
        $this->abrirMultas($this->cuenta(), '2026-06-01');
        $this->importarYConsolidar($this->planillaSimple());
        $multa = Fine::query()->findOrFail((int) FineImportRow::query()->where('row_number', 2)->value('fine_id'));
        $pago = FineHistoricalMovement::query()->where('fine_id', $multa->id)->sole();

        $devolucion = FineHistoricalMovement::query()->create([
            'fine_id' => $multa->id, 'kind' => HistoricalMovementKind::Refund, 'amount' => '400.00',
            'occurred_on' => null, 'occurred_precision' => 'unknown', 'evidence_text' => 'Restitución', 'status' => HistoricalMovementStatus::PendingReview,
            'provenance' => FineProvenance::Manual, 'created_by' => $this->quienAbre(),
        ]);
        app(ReviewFineHistoricalMovement::class)->handle($devolucion, HistoricalMovementStatus::Validated, null, $this->quienAbre());

        $this->rechazaElAction('note', fn () => app(ReviewFineHistoricalMovement::class)->handle($pago, HistoricalMovementStatus::Rejected, 'Duplicado', $this->quienAbre(), FineReasonCode::Duplicate));
        $this->rechazaLaBase('sin cobertura', fn () => DB::table('fine_historical_movements')->where('id', $pago->id)->update([
            'status' => 'rejected', 'reviewed_at' => now(), 'review_reason_code' => 'duplicate', 'review_note' => 'Directo',
        ]));
    }

    // ── La base, sin pasar por el Action ────────────────────────────────

    /** Provisional solo con origen import; un importado entra provisional, por mes y antes del corte, una vez por mes. */
    public function test_the_database_guards_imported_payments(): void
    {
        $this->abrirMultas($this->cuenta(), '2026-06-01');
        $this->importarYConsolidar($this->planillaSimple());
        $fila = FineImportRow::query()->where('row_number', 2)->sole();
        $base = ['fine_id' => $fila->fine_id, 'kind' => 'payment', 'amount' => '100.00', 'occurred_precision' => 'month', 'evidence_text' => 'Directo', 'created_at' => now()];

        $this->rechazaLaBase('provisional_check', fn () => DB::table('fine_historical_movements')->insert([...$base, 'occurred_on' => '2018-03-01', 'status' => 'provisional', 'provenance' => 'manual']));
        $this->rechazaLaBase('apertura', fn () => DB::table('fine_historical_movements')->insert([...$base, 'occurred_on' => '2026-07-01', 'status' => 'provisional', 'provenance' => 'import', 'import_row_id' => $fila->id]));
        $this->rechazaLaBase('one_import_per_month', fn () => DB::table('fine_historical_movements')->insert([...$base, 'occurred_on' => '2018-01-01', 'status' => 'provisional', 'provenance' => 'import', 'import_row_id' => $fila->id]));
        $this->rechazaLaBase('entra provisional', fn () => DB::table('fine_historical_movements')->insert([...$base, 'occurred_on' => '2018-03-01', 'status' => 'validated', 'provenance' => 'import', 'import_row_id' => $fila->id, 'reviewed_at' => now()]));
    }

    /** Lo que dijo la planilla no se edita, y los problemas solo se agregan mientras se lee. */
    public function test_the_database_keeps_what_the_file_said(): void
    {
        $this->abrirMultas($this->cuenta(), '2026-06-01');
        $this->subir($this->planillaSimple());
        $fila = FineImportRow::query()->where('row_number', 2)->sole();

        $this->rechazaLaBase('no se edita', fn () => DB::table('fine_import_rows')->where('id', $fila->id)->update(['cells' => '{}']));
        $this->rechazaLaBase('se agregan mientras se lee', fn () => DB::table('fine_import_rows')->where('id', $fila->id)->update(['issues' => '[{"code":"overpaid","blocking":false}]']));
        $this->rechazaLaBase('no se borra', fn () => DB::table('fine_import_rows')->where('id', $fila->id)->delete());
    }

    /** Solo el administrador importa. */
    public function test_only_the_administrator_imports(): void
    {
        $this->actingAs($this->operador('contador'))->get(route('multas.importaciones.index'))->assertForbidden();
        $this->actingAs($this->operador('administrador'))->get(route('multas.importaciones.index'))->assertOk();
    }

    // ── Escenario ───────────────────────────────────────────────────────

    private function layout(): SheetLayout
    {
        return SheetLayout::detect([
            'A' => 'FECHA', 'B' => 'EXPTE.', 'C' => 'RESOL.', 'D' => '', 'E' => 'CARATULA', 'F' => 'IMPORTE',
            'G' => '2018-01-01', 'H' => 'SET-18', 'K' => '2018-09-01', 'L' => 'TOTAL PAGADO', 'M' => 'SALDO', 'N' => 'PLAN DE PAGOS',
        ]) ?? throw new RuntimeException('Encabezado de prueba inválido.');
    }

    /** @return array<string, array{value: string, formula: string|null, missing: bool}> */
    private function identidad(): array
    {
        return [
            'A' => $this->celda('2018-01-08'), 'B' => $this->celda('221681/15-0'), 'C' => $this->celda('0029/18'),
            'D' => $this->celda('LAB Nº 24442/15'), 'E' => $this->celda('MIRANDA ANIBAL'), 'F' => $this->celda('3000'),
        ];
    }

    /**
     * @param  array<string, string>  $resto
     * @return array<string, mixed>
     */
    private function clasificar(array $resto, string $corte = '2026-06-01'): array
    {
        $celdas = $this->identidad();

        foreach ($resto as $letra => $valor) {
            $celdas[$letra] = $this->celda($valor);
        }

        return (new RowClassifier)->classify($this->layout(), '2018', 5, $celdas, $corte);
    }

    /** @return array{value: string, formula: string|null, missing: bool} */
    private function celda(string $valor): array
    {
        return ['value' => $valor, 'formula' => null, 'missing' => false];
    }

    /**
     * @param  array<string, mixed>  $fila
     * @return list<string>
     */
    private function codigos(array $fila): array
    {
        return array_column($fila['issues'], 'code');
    }

    /**
     * @param  array<string, mixed>  $fila
     * @return list<string>
     */
    private function bloqueos(array $fila): array
    {
        return array_values(array_column(array_filter($fila['issues'], fn (array $i): bool => $i['blocking']), 'code'));
    }

    /** La planilla de prueba: una fila por caso, dos pies de control. */
    private function planillaBase(): UploadedFile
    {
        $f = fn (int $fila): string => "=G{$fila}+H{$fila}+I{$fila}+J{$fila}";
        $s = fn (int $fila): string => "=F{$fila}-K{$fila}";

        return $this->planilla([
            2 => ['B' => '221681/15-0', 'C' => '0029/18', 'D' => 'LAB Nº 24442/15', 'E' => 'MIRANDA ANIBAL', 'F' => 3000, 'G' => 1000, 'I' => 500, 'K' => $f(2), 'L' => $s(2)],
            3 => ['B' => '82394', 'C' => 2773, 'D' => 'RUBRICA', 'E' => 'HABIR S.A.', 'F' => 800, 'K' => 0, 'L' => 800],
            4 => ['B' => '221687/15-0', 'C' => '0030/18', 'D' => 'LAB N° 1/15', 'E' => 'ROCCI GRACIELA', 'F' => 3000, 'G' => 1000, 'K' => 900, 'L' => 2100],
            5 => ['B' => '257319/15-0', 'C' => '0031/18', 'D' => 'LAB N° 2/15', 'E' => 'MEDINA CLAUDIA', 'F' => 1700, 'H' => 3125, 'K' => $f(5), 'L' => $s(5)],
            6 => ['B' => '223734/15-0', 'C' => '0032/18', 'D' => 'HYS N° 3/15', 'E' => 'IDEM S.R.L.', 'F' => 3000, 'G' => 1000, 'J' => 500, 'K' => $f(6), 'L' => $s(6)],
            7 => ['B' => '145713/15-0', 'C' => '0033/18', 'D' => 'LAB N° 4/15', 'E' => 'REMISES UNION', 'F' => 2500, 'H' => 'JUICIO APREMIO', 'K' => 0, 'L' => 2500],
            8 => ['B' => '169607/24-0', 'C' => 'N°0649/26', 'D' => 'H Y S N°169607', 'E' => 'CRISTOMAX S.A.S', 'F' => 1400000, 'K' => 0, 'L' => 1400000],
            9 => ['B' => '300000/15-0', 'C' => '0034/18', 'D' => 'LAB N° 5/15', 'E' => 'PLAN S.R.L.', 'F' => 1000, 'M' => 500, 'K' => 0, 'L' => 1000],
            10 => ['E' => 'TOTALES', 'F' => '=SUM(F2:F9)', 'G' => '=SUM(G2:G9)', 'H' => '=SUM(H2:H9)', 'I' => '=SUM(I2:I9)', 'J' => '=SUM(J2:J9)'],
            11 => ['F' => '=SUM(F2:F9)'],
        ]);
    }

    private function planillaSimple(bool $conFebrero = false, int|string $importe = 3000): UploadedFile
    {
        return $this->planilla([
            2 => ['B' => '221681/15-0', 'C' => '0029/18', 'D' => 'LAB Nº 24442/15', 'E' => 'MIRANDA ANIBAL', 'F' => (float) $importe, 'G' => 1000,
                'H' => $conFebrero ? 700 : null, 'K' => '=G2+H2+I2+J2', 'L' => '=F2-K2'],
        ]);
    }

    /**
     * La hoja 2018: fecha, expediente, resolución, acta sin título, carátula,
     * importe, enero y febrero de 2018 como fecha, setiembre como texto, junio
     * de 2026 como fecha, total, saldo y plan de pagos.
     *
     * @param  array<int, array<string, int|float|string|null>>  $filas
     */
    private function planilla(array $filas): UploadedFile
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('2018');

        $encabezado = ['A' => 'FECHA', 'B' => 'EXPTE.', 'C' => 'RESOL.', 'E' => 'CARATULA', 'F' => 'IMPORTE',
            'G' => new DateTimeImmutable('2018-01-01'), 'H' => new DateTimeImmutable('2018-02-01'), 'I' => 'SET-18',
            'J' => new DateTimeImmutable('2026-06-01'), 'K' => 'TOTAL PAGADO', 'L' => 'SALDO', 'M' => 'PLAN DE PAGOS'];

        foreach ($encabezado as $letra => $valor) {
            $this->escribir($hoja, "{$letra}1", $valor);
        }

        foreach ($filas as $numero => $celdas) {
            if (! isset($celdas['A']) && isset($celdas['B'])) {
                $celdas = ['A' => new DateTimeImmutable('2018-01-08'), ...$celdas];
            }

            foreach ($celdas as $letra => $valor) {
                if ($valor !== null) {
                    $this->escribir($hoja, "{$letra}{$numero}", $valor);
                }
            }
        }

        $ruta = tempnam(sys_get_temp_dir(), 'planilla-').'.xls';
        (new Xls($libro))->save($ruta);

        return new UploadedFile($ruta, 'cuenta-corriente.xls', 'application/vnd.ms-excel', null, true);
    }

    private function escribir(Worksheet $hoja, string $celda, mixed $valor): void
    {
        if ($valor instanceof DateTimeImmutable) {
            $hoja->setCellValue($celda, Date::PHPToExcel($valor));
            $hoja->getStyle($celda)->getNumberFormat()->setFormatCode('yyyy-mm-dd');

            return;
        }

        $hoja->setCellValue($celda, $valor);
    }

    private function subir(UploadedFile $archivo): FineImportBatch
    {
        return app(StartFineImport::class)->handle($archivo, $this->quienAbre())->refresh();
    }

    private function importarYConsolidar(UploadedFile $archivo): FineImportBatch
    {
        $lote = $this->subir($archivo);
        app(StartFineImportConsolidation::class)->handle($lote, $this->quienAbre());

        return $lote->refresh();
    }

    private function fila(int $numero): FineImportRow
    {
        return FineImportRow::query()->where('row_number', $numero)->orderBy('id')->firstOrFail();
    }

    private function multaManual(string $expediente = '169607/24-0'): Fine
    {
        $this->actingAs($this->operador())->post(route('multas.store'), [
            'caseNumber' => $expediente,
            'resolution' => ['text' => $expediente === '169607/24-0' ? 'N°0649/26' : 'N°0650/26', 'issuedOn' => '2026-05-07'],
            'acts' => [['actType' => 'HYS', 'text' => 'N°1089657', 'issuedOn' => null]],
            'caption' => 'CRISTOMAX S.A.S',
            'registeredOn' => '2026-05-07',
            'amount' => '1400000.00',
        ])->assertSessionHasNoErrors();

        return Fine::query()->latest('id')->firstOrFail();
    }

    private function saldo(int|string|null $multa, string $columna): string
    {
        return (string) DB::table('fine_balances')->where('fine_id', $multa)->value($columna);
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
