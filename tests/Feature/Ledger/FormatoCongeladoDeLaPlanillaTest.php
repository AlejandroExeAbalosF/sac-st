<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use App\Modules\Ledger\Actions\ClosePeriod;
use App\Modules\Ledger\Actions\ExportCashSheet;
use App\Modules\Ledger\Actions\ReopenPeriod;
use App\Modules\Ledger\Excel\CashSheet;
use App\Modules\Ledger\Excel\CashSheetBuilder;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Shared\Models\CashBox;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * La evidencia del cierre congela el formato de su reverso (`schema 2`), y
 * la de antes —toda de Haberes— se sigue leyendo con sus columnas.
 */
class FormatoCongeladoDeLaPlanillaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_haberes_closing_freezes_its_columns_and_no_bank_note(): void
    {
        $cierre = $this->cerrarHaberes();

        $payload = json_decode((string) DB::table('period_closing_evidence')->where('period_closing_id', $cierre)->value('payload'), true);

        expect($payload['schema'])->toBe(2)
            ->and($payload['chequeColumns'])->toEqual(CashSheet::HABERES_CHEQUE_COLUMNS)
            ->and($payload)->toHaveKey('bankColumnNote')
            ->and($payload['bankColumnNote'])->toBeNull();
    }

    /** La evidencia anterior a esta etapa no dice sus columnas: son las de Haberes. */
    public function test_schema_one_evidence_is_still_read_with_the_haberes_columns(): void
    {
        $cierre = $this->cerrarHaberes();
        $payload = json_decode((string) DB::table('period_closing_evidence')->where('period_closing_id', $cierre)->value('payload'), true);
        unset($payload['chequeColumns'], $payload['bankColumnNote']);
        $payload['schema'] = 1;

        $this->recerrarCon($cierre, $payload);

        $planilla = app(CashSheetBuilder::class)->build(
            PeriodClosing::query()->findOrFail($cierre),
        );

        expect($planilla->chequeColumns)->toBe(CashSheet::HABERES_CHEQUE_COLUMNS)
            ->and($planilla->bankColumnNote)->toBeNull();
    }

    /** Y se exporta con el reverso de siempre, cada dato en su columna. */
    public function test_schema_one_evidence_exports_the_haberes_reverse(): void
    {
        $cierre = $this->cerrarHaberes();
        $payload = json_decode((string) DB::table('period_closing_evidence')->where('period_closing_id', $cierre)->value('payload'), true);
        unset($payload['chequeColumns'], $payload['bankColumnNote']);
        $payload['schema'] = 1;
        $payload['cheques'] = [[
            'receipt' => '64685', 'expediente' => 'EXP-1/2026', 'company' => 'ACME S.A.', 'beneficiary' => 'PÉREZ, ANA',
            'number' => '61197673', 'bank' => 'CREDICOOP', 'date' => '01/06/2026', 'amount' => '1500.00',
        ]];

        $this->recerrarCon($cierre, $payload);

        $adjunto = app(ExportCashSheet::class)->handle(PeriodClosing::query()->findOrFail($cierre));
        $filas = IOFactory::load(Storage::disk('local')->path($adjunto->object_key))
            ->getSheetByName('REVERSO 010626')?->toArray(null, false, false) ?? [];
        $encabezado = array_values(array_filter($filas, fn (array $fila): bool => $fila[0] === 'RECIBO'))[0] ?? [];
        $cheque = array_values(array_filter($filas, fn (array $fila): bool => $fila[0] === '64685'))[0] ?? [];

        expect(array_slice($encabezado, 0, 8))->toBe(['RECIBO', 'EXPTE', 'EMPRESA', 'BENEFICIARIO', 'CHEQUE Nº', 'BANCO', 'FECHA', 'IMPORTE'])
            ->and(array_slice($cheque, 0, 7))->toBe(['64685', 'EXP-1/2026', 'ACME S.A.', 'PÉREZ, ANA', '61197673', 'CREDICOOP', '01/06/2026'])
            ->and((float) $cheque[7])->toBe(1500.0);
    }

    /** La base no acepta una evidencia nueva que no diga cómo es su reverso. */
    public function test_the_database_rejects_schema_two_without_its_format(): void
    {
        $cierre = $this->cerrarHaberes();
        $payload = json_decode((string) DB::table('period_closing_evidence')->where('period_closing_id', $cierre)->value('payload'), true);

        $sinNota = $payload;
        unset($sinNota['bankColumnNote']);

        $rotos = [
            'sin columnas' => [...$payload, 'chequeColumns' => []],
            'sin la aclaración de banco' => $sinNota,
            'de un formato desconocido' => [...$payload, 'schema' => 3],
            // El lector espera el entero: la base no puede aceptar lo que después no se lee.
            'con la versión como texto' => [...$payload, 'schema' => '2'],
            'con la versión decimal' => [...$payload, 'schema' => 2.5],
            'con una columna sin encabezado' => [...$payload, 'chequeColumns' => [['key' => 'receipt']]],
            'con un encabezado vacío' => [...$payload, 'chequeColumns' => [['key' => 'receipt', 'header' => ' ']]],
            'con una columna nula' => [...$payload, 'chequeColumns' => [null]],
            'con una clave repetida' => [...$payload, 'chequeColumns' => [
                ['key' => 'receipt', 'header' => 'RECIBO'], ['key' => 'receipt', 'header' => 'OTRO'],
            ]],
        ];

        $this->reabrir($cierre);

        foreach ($rotos as $que => $roto) {
            try {
                $this->recerrarCon($cierre, $roto, reabrir: false);
                $this->fail("La base aceptó una evidencia {$que}.");
            } catch (QueryException $e) {
                expect(mb_strtolower($e->getMessage()))->toContain('evidencia');
            }
        }
    }

    /**
     * Vuelve a cerrar el período con esta evidencia, escrita a mano: es la
     * única forma de tener hoy un cierre vigente con evidencia de antes.
     *
     * @param  array<string, mixed>  $payload
     */
    private function recerrarCon(int $cierre, array $payload, bool $reabrir = true): void
    {
        if ($reabrir) {
            $this->reabrir($cierre);
        }

        DB::transaction(function () use ($cierre, $payload): void {
            // Como `ClosePeriod`: cabecera y evidencia se escriben juntas.
            DB::statement('SET CONSTRAINTS period_closing_has_evidence DEFERRED');
            DB::table('period_closings')->where('id', $cierre)->update([
                'status' => 'closed', 'evidence_version' => 2, 'closed_at' => now(),
                'reopened_by' => null, 'reopened_at' => null, 'reopen_reason' => null,
            ]);
            DB::table('period_closing_evidence')->insert([
                'period_closing_id' => $cierre, 'version' => 2, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ]);
            DB::statement('SET CONSTRAINTS period_closing_has_evidence IMMEDIATE');
        });
    }

    /** Reabre el día y lo vuelve a contar, como antes de cualquier recierre. */
    private function reabrir(int $cierre): void
    {
        app(ReopenPeriod::class)->handle(PeriodClosing::query()->findOrFail($cierre), $this->quienAbre(), 'Probar el formato de la evidencia.');
        $this->arqueoListoParaCerrar((int) CashBox::query()->where('code', CashBox::HABERES)->value('id'), '2026-06-01');
    }

    private function cerrarHaberes(): int
    {
        $this->abrirLibrosSinSaldo();
        $caja = (int) CashBox::query()->where('code', CashBox::HABERES)->value('id');
        $this->arqueoListoParaCerrar($caja, '2026-06-01');

        return (int) app(ClosePeriod::class)->handle($caja, CarbonImmutable::parse('2026-06-01'))->id;
    }
}
