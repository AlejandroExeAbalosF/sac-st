<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Modules\Haberes\Actions\AllocateFundsToInstallment;
use App\Modules\Haberes\Actions\IssueIncomeReceipt;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Ledger\Actions\RegisterCashFundReceipt;
use App\Modules\Shared\Enums\ReceiptType;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Support\TalonarioSequence;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El último número de talonario cargado en cada serie.
 *
 * **No es una secuencia que el sistema controle.** El número viene de un
 * lote de papel impreso afuera: es opcional, el sistema no lo genera y no
 * se espera que sea correlativo. Esto existe solo para que la pantalla
 * pueda avisar cuando el que se tipea no sigue al anterior — un `72912`
 * escrito donde iba `72192` se caza en el momento y no tres meses después.
 */
class UltimoTalonarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HaberesDemoSeeder::class);
    }

    public function test_sin_comprobantes_no_hay_ultimo(): void
    {
        $this->assertNull(app(TalonarioSequence::class)->lastUsed(ReceiptType::Income));
    }

    public function test_devuelve_el_ultimo_cargado_en_la_serie(): void
    {
        $this->emitir('00071513');
        $this->emitir('00071514');

        $this->assertSame(
            '00071514',
            app(TalonarioSequence::class)->lastUsed(ReceiptType::Income),
        );
    }

    /**
     * El orden es numérico, no alfabético.
     *
     * Comparando como texto, `9` es mayor que `72192` y el último de un
     * talonario de cinco cifras quedaría siendo el `9`. Y tampoco puede
     * compararse como entero: el número del papel trae ceros a la izquierda
     * que forman parte de cómo el área lo escribe.
     */
    public function test_el_orden_no_es_alfabetico(): void
    {
        $this->emitir('9');
        $this->emitir('72192');

        $this->assertSame(
            '72192',
            app(TalonarioSequence::class)->lastUsed(ReceiptType::Income),
        );
    }

    /** Un recibo sin número de talonario no cuenta: el número es opcional. */
    public function test_los_comprobantes_sin_talonario_no_pesan(): void
    {
        $this->emitir('72192');
        $this->emitir(null);

        $this->assertSame(
            '72192',
            app(TalonarioSequence::class)->lastUsed(ReceiptType::Income),
        );
    }

    /** Las dos series se cuentan por separado: son dos talonarios distintos. */
    public function test_cada_serie_lleva_el_suyo(): void
    {
        $this->emitir('72192');

        $ultimos = app(TalonarioSequence::class)->all();

        $this->assertSame('72192', $ultimos[ReceiptType::Income->value]);
        $this->assertNull($ultimos[ReceiptType::Expense->value]);
    }

    private function emitir(?string $talonario): void
    {
        $cuota = BeneficiaryInstallment::query()
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('funding_allocations')
                ->whereColumn('funding_allocations.beneficiary_installment_id', 'beneficiary_installments.id'))
            ->whereHas('haber.expediente', fn ($q) => $q->whereNotNull('employer_id'))
            ->orderBy('id')
            ->firstOrFail();

        $importe = $cuota->importeEsperado();

        $recepcion = app(RegisterCashFundReceipt::class)->handle(
            amount: $importe,
            idempotencyKey: 'efectivo-'.Str::random(10),
            cashBoxId: (int) CashBox::query()->where('code', CashBox::HABERES)->value('id'),
            receivedDate: now(),
        );

        app(AllocateFundsToInstallment::class)->handle(
            receipt: $recepcion,
            installment: $cuota,
            amount: $importe,
            idempotencyKey: 'asignacion-'.Str::random(10),
        );

        app(IssueIncomeReceipt::class)->handle($cuota->refresh(), talonarioNumber: $talonario);
    }
}
