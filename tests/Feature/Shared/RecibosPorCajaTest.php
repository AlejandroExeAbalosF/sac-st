<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Models\User;
use App\Modules\Shared\Actions\ConfigureDocumentNumbering;
use App\Modules\Shared\Actions\TakeNextDocumentNumber;
use App\Modules\Shared\Enums\ReceiptType;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Models\DocumentSeries;
use App\Modules\Shared\Support\TalonarioSequence;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Series, talonarios y «recibí de» por caja.
 *
 * Multas numera aparte y extiende el recibo a la carátula del expediente,
 * que no siempre es una persona del maestro. Haberes sigue igual: su caja
 * exige la persona.
 */
class RecibosPorCajaTest extends TestCase
{
    use RefreshDatabase;

    public function test_multas_numera_con_su_propia_serie(): void
    {
        $this->tomar('0010');

        $multas = $this->tomar('0210');

        $this->assertSame(1, $multas['number']);
        $this->assertSame('0210/00000001', $multas['formatted']);
        $this->assertSame(2, $this->tomar('0010')['number']);
    }

    public function test_la_base_admite_una_serie_activa_por_tipo_en_cada_caja(): void
    {
        $this->expectException(QueryException::class);

        DocumentSeries::query()->create([
            'code' => '0211', 'document_type' => 'receipt_income', 'label' => 'Otra serie de Multas',
            'cash_box_id' => $this->caja(CashBox::MULTAS), 'origin' => 'system', 'is_active' => true,
        ]);
    }

    public function test_el_recibo_toma_la_caja_de_su_serie(): void
    {
        $id = $this->insertar('0210', ['person_id' => null, 'counterparty_name_snapshot' => 'Empresa SA']);

        $this->assertSame($this->caja(CashBox::MULTAS), (int) DB::table('receipts')->where('id', $id)->value('cash_box_id'));
    }

    public function test_la_base_rechaza_una_caja_distinta_de_la_de_su_serie(): void
    {
        $this->expectException(QueryException::class);

        $this->insertar('0210', [
            'person_id' => null, 'counterparty_name_snapshot' => 'Empresa SA',
            'cash_box_id' => $this->caja(CashBox::HABERES),
        ]);
    }

    public function test_haberes_sigue_exigiendo_la_persona(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('persona del maestro');

        $this->insertar('0010', ['person_id' => null, 'counterparty_name_snapshot' => 'Empresa SA']);
    }

    public function test_un_recibo_sin_persona_dice_a_quien_se_extendio(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('a quién se le extendió');

        $this->insertar('0210', ['person_id' => null, 'counterparty_name_snapshot' => '  ']);
    }

    public function test_la_caja_y_el_recibi_de_no_se_editan(): void
    {
        $id = $this->insertar('0210', ['person_id' => null, 'counterparty_name_snapshot' => 'Empresa SA']);

        $this->expectException(QueryException::class);

        DB::table('receipts')->where('id', $id)->update(['counterparty_name_snapshot' => 'Otra empresa']);
    }

    /** Cada caja tiene su talonario de papel: el mismo número puede existir en las dos. */
    public function test_el_talonario_se_cuenta_por_caja(): void
    {
        $persona = $this->persona();
        $this->insertar('0010', ['person_id' => $persona, 'talonario_number' => '18467']);
        $this->insertar('0210', ['person_id' => null, 'counterparty_name_snapshot' => 'Empresa SA', 'talonario_number' => '18467']);
        $this->insertar('0210', ['person_id' => null, 'counterparty_name_snapshot' => 'Empresa SA', 'talonario_number' => '18470']);

        $talonario = app(TalonarioSequence::class);
        $this->assertSame('18467', $talonario->lastUsed(ReceiptType::Income, $this->caja(CashBox::HABERES)));
        $this->assertSame('18470', $talonario->lastUsed(ReceiptType::Income, $this->caja(CashBox::MULTAS)));

        $this->expectException(QueryException::class);
        $this->insertar('0210', ['person_id' => null, 'counterparty_name_snapshot' => 'Empresa SA', 'talonario_number' => '18470']);
    }

    public function test_la_serie_de_multas_se_configura_por_su_codigo(): void
    {
        $this->actingAs(User::factory()->create()->assignRole('administrador'))
            ->put('/configuracion/numeracion/0210', $this->ajuste('0210', ['prefix' => '2100']))
            ->assertSessionHasNoErrors();

        $this->assertSame('2100/00000001', $this->tomar('0210')['formatted']);
        $this->assertSame('0010/00000001', $this->tomar('0010')['formatted']);
    }

    /** El número formateado es único en todo el sistema: el prefijo no se comparte entre cajas. */
    public function test_multas_no_toma_el_prefijo_de_haberes(): void
    {
        try {
            app(ConfigureDocumentNumbering::class)->handle('0210', $this->ajuste('0210', ['prefix' => '10']), User::factory()->create()->id);
            $this->fail('Multas tomó el prefijo de Haberes.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('otra caja', $exception->errors()['prefix'][0]);
        }
    }

    /**
     * @param  array<string, mixed>  $cambios
     * @return array<string, mixed>
     */
    private function ajuste(string $code, array $cambios): array
    {
        return ['prefix' => $code, 'prefixDigits' => 4, 'numberDigits' => 8, 'separator' => '/',
            'nextNumber' => 1, 'reason' => 'Ajuste de la serie.',
            'token' => DocumentSeries::activeForCode($code)?->configurationToken(), ...$cambios];
    }

    /** @param array<string, mixed> $fila */
    private function insertar(string $code, array $fila): int
    {
        $numero = $this->tomar($code);

        return (int) DB::table('receipts')->insertGetId([
            'document_series_id' => $numero['series_id'], 'number' => $numero['number'],
            'formatted_number' => $numero['formatted'], 'receipt_type' => 'income', 'medium_snapshot' => 'cash',
            'amount' => '100.00', 'issue_date' => '2026-10-20', 'status' => 'issued', 'issue_mode' => 'online',
            'created_at' => now(), 'updated_at' => now(), ...$fila,
        ]);
    }

    /** @return array{number: int, formatted: string, series_id: int} */
    private function tomar(string $code): array
    {
        return DB::transaction(fn (): array => app(TakeNextDocumentNumber::class)->handle($code));
    }

    private function caja(string $code): int
    {
        return (int) CashBox::query()->where('code', $code)->value('id');
    }

    private function persona(): int
    {
        return (int) DB::table('people')->insertGetId([
            'type' => 'company', 'legal_name' => 'Empresa del maestro', 'document' => '30700000001',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
