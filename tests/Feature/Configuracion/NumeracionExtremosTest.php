<?php

declare(strict_types=1);

namespace Tests\Feature\Configuracion;

use App\Modules\Haberes\Actions\VoidPaymentOrder;
use App\Modules\Haberes\Enums\HaberesReasonCode;
use App\Modules\Haberes\Models\PaymentOrder;
use App\Modules\Haberes\Pdf\PaymentOrderPdf;
use App\Modules\Shared\Actions\ConfigureDocumentNumbering;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Models\DocumentSeries;
use App\Modules\Shared\Pdf\ExpenseReceiptPdf;
use App\Modules\Shared\Pdf\IncomeReceiptPdf;
use App\Modules\Shared\Support\Reason;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\NumberingFixture;
use Tests\TestCase;

class NumeracionExtremosTest extends TestCase
{
    use RefreshDatabase;

    private NumberingFixture $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->abrirLibrosSinSaldo();
        $this->fixture = new NumberingFixture($this->quienAbre());
    }

    protected function tearDown(): void
    {
        try {
            // RefreshDatabase revierte al finalizar; las restricciones diferidas también deben comprobarse.
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        } finally {
            parent::tearDown();
        }
    }

    /** @return iterable<string, array{DocumentType}> */
    public static function types(): iterable
    {
        foreach (DocumentType::cases() as $type) {
            yield $type->value => [$type];
        }
    }

    /** @param array<string, mixed> $changes */
    private function configure(DocumentType $type, array $changes): DocumentSeries
    {
        $series = DocumentSeries::activeForCode($type->seriesCode());

        return app(ConfigureDocumentNumbering::class)->handle($type->seriesCode(), [
            'prefix' => $series->display_prefix ?? $series->code, 'prefixDigits' => $series->code_padding,
            'numberDigits' => $series->number_padding, 'separator' => str_contains($series->format_pattern, '/') ? '/' : '-',
            'nextNumber' => $series->next_number, 'reason' => 'Validación de casos extremos.', 'token' => $series->configurationToken(),
            ...$changes,
        ], $this->fixture->actorId);
    }

    #[DataProvider('types')]
    public function test_regresos_repetidos_consultan_toda_la_historia(DocumentType $type): void
    {
        $documents = [];
        foreach ([[$type->seriesCode(), 1], ['0900', 1], [$type->seriesCode(), 2], ['0900', 2], [$type->seriesCode(), 3]] as [$prefix, $number]) {
            $this->configure($type, ['prefix' => $prefix, 'nextNumber' => $number]);
            $document = $this->fixture->emit($type, $this->fixture->prepare($type));
            $this->assertSame($prefix.'/'.str_pad((string) $number, 8, '0', STR_PAD_LEFT), $document->formatted_number);
            $documents[] = [$document, $document->formatted_number, $document->document_series_id];
        }
        foreach ($documents as [$document, $number, $seriesId]) {
            $this->assertSame($number, $document->refresh()->formatted_number);
            $this->assertSame($seriesId, $document->document_series_id);
        }
        $this->assertSame(1, DocumentSeries::query()->where('code', $type->seriesCode())->where('is_active', true)->count());
        $this->expectException(ValidationException::class);
        $this->configure($type, ['prefix' => '0900', 'nextNumber' => 2]);
    }

    #[DataProvider('types')]
    public function test_prefijos_equivalentes_no_permiten_reiniciar(DocumentType $type): void
    {
        $this->fixture->emit($type, $this->fixture->prepare($type));
        try {
            $this->configure($type, ['prefix' => ltrim($type->seriesCode(), '0'), 'nextNumber' => 1]);
            $this->fail('El prefijo equivalente permitió repetir el número.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('nextNumber', $exception->errors());
        }
        $this->configure($type, ['prefix' => ltrim($type->seriesCode(), '0'), 'nextNumber' => 2]);
        $document = $this->fixture->emit($type, $this->fixture->prepare($type));
        $this->assertSame($type->seriesCode().'/00000002', $document->formatted_number);
    }

    #[DataProvider('types')]
    public function test_anular_no_libera_el_numero(DocumentType $type): void
    {
        $document = $this->fixture->emit($type, $this->fixture->prepare($type));
        if ($document instanceof PaymentOrder) {
            app(VoidPaymentOrder::class)->handle($document, new Reason(HaberesReasonCode::WrongAmount, 'Corrección del comprobante.'), $this->fixture->actorId);
        } else {
            // Anulación del papel; no se revierte el hecho monetario para probar la numeración.
            $document->forceFill(['status' => 'voided', 'voided_by' => $this->fixture->actorId, 'voided_at' => now(), 'void_reason' => 'Corrección del comprobante.'])->save();
        }
        try {
            $this->configure($type, ['nextNumber' => 1]);
            $this->fail('Se reutilizó el número de un documento anulado.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('nextNumber', $exception->errors());
        }
        $next = $this->fixture->emit($type, $this->fixture->prepare($type));
        $this->assertSame(2, $next->number);
        $this->assertSame($type->seriesCode().'/00000001', $document->refresh()->formatted_number);
    }

    #[DataProvider('types')]
    public function test_reducir_digitos_no_trunca_el_correlativo(DocumentType $type): void
    {
        $this->configure($type, ['nextNumber' => 12345]);
        $first = $this->fixture->emit($type, $this->fixture->prepare($type));
        $this->configure($type, ['numberDigits' => 1]);
        $next = $this->fixture->emit($type, $this->fixture->prepare($type));
        $this->assertSame($type->seriesCode().'/12346', $next->formatted_number);
        $this->assertSame($type->seriesCode().'/00012345', $first->refresh()->formatted_number);
    }

    #[DataProvider('types')]
    public function test_el_limite_emite_una_vez_y_el_siguiente_no_deja_efectos_parciales(DocumentType $type): void
    {
        $first = $this->fixture->prepare($type);
        $second = $this->fixture->prepare($type);
        $this->configure($type, ['prefix' => '999999999999', 'prefixDigits' => 12, 'numberDigits' => 12, 'nextNumber' => 999999999999]);
        $last = $this->fixture->emit($type, $first);
        $this->assertSame('999999999999/999999999999', $last->formatted_number);
        $before = $this->counts();
        try {
            $this->fixture->emit($type, $second);
            $this->fail('Se emitió por encima del límite.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('numbering', $exception->errors());
        }
        $this->assertSame($before, $this->counts());
        $this->assertSame(1000000000000, DocumentSeries::activeForCode($type->seriesCode())->next_number);
        $this->configure($type, ['prefix' => '0900', 'prefixDigits' => 4, 'nextNumber' => 1]);
        $this->assertSame(1, $this->fixture->emit($type, $second->refresh())->number);
    }

    #[DataProvider('types')]
    public function test_fallar_despues_de_emitir_revierte_comprobante_numero_y_movimientos(DocumentType $type): void
    {
        $installment = $this->fixture->prepare($type);
        $before = $this->counts();
        try {
            DB::transaction(function () use ($type, $installment): void {
                $this->fixture->emit($type, $installment);
                throw new RuntimeException('Fallo simulado posterior a la emisión.');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Fallo simulado posterior a la emisión.', $exception->getMessage());
        }
        $this->assertSame($before, $this->counts());
        $this->assertSame(1, DocumentSeries::activeForCode($type->seriesCode())->next_number);
        $this->assertSame(1, $this->fixture->emit($type, $installment->refresh())->number);
    }

    #[DataProvider('types')]
    public function test_regenerar_el_pdf_conserva_numero_contenido_y_nombre(DocumentType $type): void
    {
        $document = $this->fixture->emit($type, $this->fixture->prepare($type));
        $pdf = match ($type) {
            DocumentType::ReceiptIncome => app(IncomeReceiptPdf::class),
            DocumentType::ReceiptExpense => app(ExpenseReceiptPdf::class),
            DocumentType::PaymentOrder => app(PaymentOrderPdf::class),
        };
        $html = $pdf->preview($document)->render();
        $filename = $pdf->filename($document);
        $before = $pdf->render($document);
        $this->assertStringStartsWith('%PDF-', $before->output());
        $this->configure($type, ['prefix' => '0900', 'numberDigits' => 3, 'separator' => '-', 'nextNumber' => 500]);
        $document->refresh();
        $after = $pdf->render($document);
        $this->assertStringStartsWith('%PDF-', $after->output());
        $this->assertSame($before->getDomPDF()->getCanvas()->get_page_count(), $after->getDomPDF()->getCanvas()->get_page_count());
        $this->assertSame($html, $pdf->preview($document)->render());
        $this->assertStringContainsString($document->formatted_number, $html);
        $this->assertSame($filename, $pdf->filename($document));
        $this->assertStringContainsString(str_replace('/', '-', $document->formatted_number), $filename);
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = [];
        foreach (['receipts', 'payment_orders', 'pases', 'disbursements', 'financial_events', 'journal_lines', 'audit_events'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    #[DataProvider('types')]
    public function test_la_base_impide_dos_versiones_activas_y_retroceder_el_numero(DocumentType $type): void
    {
        $this->fixture->emit($type, $this->fixture->prepare($type));
        $series = DocumentSeries::activeForCode($type->seriesCode());
        foreach (['duplicate', 'rewind'] as $operation) {
            try {
                DB::transaction(function () use ($series, $operation): void {
                    if ($operation === 'duplicate') {
                        $series->replicate()->save();
                    } else {
                        DB::table('document_series')->where('id', $series->id)->update(['next_number' => 1]);
                    }
                });
                $this->fail('La base aceptó una configuración incompatible con lo emitido.');
            } catch (QueryException $exception) {
                $this->assertSame($operation === 'duplicate' ? '23505' : '23514', $exception->errorInfo[0]);
            }
        }
        $this->assertSame(2, $series->refresh()->next_number);
    }

    #[DataProvider('types')]
    public function test_un_prefijo_historico_no_puede_asignarse_a_otro_tipo(DocumentType $type): void
    {
        $this->fixture->emit($type, $this->fixture->prepare($type));
        $this->configure($type, ['prefix' => '0900']);
        $other = $type === DocumentType::ReceiptIncome ? DocumentType::ReceiptExpense : DocumentType::ReceiptIncome;
        try {
            $this->configure($other, ['prefix' => ltrim($type->seriesCode(), '0'), 'prefixDigits' => 4]);
            $this->fail('Se reutilizó el prefijo histórico de otro tipo.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('prefix', $exception->errors());
        }
    }
}
