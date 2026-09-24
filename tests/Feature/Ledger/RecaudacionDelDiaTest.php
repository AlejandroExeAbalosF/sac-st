<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use App\Modules\Banking\Models\BankAccount;
use App\Modules\Haberes\Actions\DeliverToBeneficiary;
use App\Modules\Haberes\Actions\DepositCashToBank;
use App\Modules\Ledger\Actions\RegisterOpeningBalance;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Support\CashDayTakings;
use App\Modules\Shared\Models\CashBox;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CollectsInstallments;
use Tests\TestCase;

/**
 * La recaudación del día es lo que entró y **no volvió a salir**.
 *
 * Es el renglón que la planilla del área llama `RECAUDACION DEL DIA`, y la
 * distinción no es menor: el 02/06/2026 entraron 24.985.600 y la recaudación
 * del día fue 1.118.200, porque casi todo se cobró el mismo día que se
 * recibió —el empleador deposita y el beneficiario viene a buscarlo—.
 *
 * Mientras el saldo del día anterior se declare a mano, el arqueo siempre se
 * puede cuadrar: alcanza con escribir la diferencia que falte. Este número es
 * lo único que permite comparar el conteo contra algo que el operador no
 * eligió.
 */
class RecaudacionDelDiaTest extends TestCase
{
    use CollectsInstallments;
    use RefreshDatabase;

    private const DIA = '2026-06-02';

    public function test_lo_que_entro_y_se_cobro_el_mismo_dia_no_es_recaudacion(): void
    {
        $this->abrirLibros();

        $seQueda = $this->cuota('131010/2023', '500000.00');
        $seCobra = $this->cuota('233663/2024', '300000.00');

        $this->cobrar($seQueda, 72190, self::DIA);
        $this->cobrar($seCobra, 72191, self::DIA);

        // El segundo beneficiario pasa a buscar su plata ese mismo día.
        app(DeliverToBeneficiary::class)->handle(
            installment: $seCobra->fresh(),
            idempotencyKey: 'test:egreso:72191',
            actorId: $this->operador()->id,
            paymentDate: CarbonImmutable::parse(self::DIA),
        );

        $this->assertSame('500000.00', $this->recaudacion());
    }

    /** Sin nadie que venga a cobrar, la recaudación es todo lo que entró. */
    public function test_sin_egresos_la_recaudacion_es_todo_lo_que_entro(): void
    {
        $this->abrirLibros();

        $this->cobrar($this->cuota('131010/2023', '500000.00'), 72190, self::DIA);
        $this->cobrar($this->cuota('233663/2024', '300000.00'), 72191, self::DIA);

        $this->assertSame('800000.00', $this->recaudacion());
    }

    /** Un pago con plata de días anteriores no toca la recaudación de hoy. */
    public function test_pagar_con_plata_vieja_no_descuenta_de_la_recaudacion(): void
    {
        $this->abrirLibros();

        $vieja = $this->cuota('131010/2023', '500000.00');
        $this->cobrar($vieja, 72190, '2026-06-01');

        $this->cobrar($this->cuota('233663/2024', '300000.00'), 72191, self::DIA);

        // Cobra hoy, pero su plata entró ayer.
        app(DeliverToBeneficiary::class)->handle(
            installment: $vieja->fresh(),
            idempotencyKey: 'test:egreso:72190',
            actorId: $this->operador()->id,
            paymentDate: CarbonImmutable::parse(self::DIA),
        );

        $this->assertSame('300000.00', $this->recaudacion());
    }

    public function test_depositar_en_el_banco_lo_recibido_hoy_lo_quita_de_la_recaudacion(): void
    {
        Storage::fake('local');
        $this->abrirLibros();

        $cuota = $this->cuota('233663/2024', '300000.00');
        $this->cobrar($cuota, 72191, self::DIA);

        $cuenta = BankAccount::query()->create([
            'label' => 'Cta. Cte. — pruebas',
            'bank_name' => 'Banco Macro',
            'account_number' => '310000123456789',
            'currency' => 'ARS',
            'is_active' => true,
        ]);

        app(DepositCashToBank::class)->handle(
            installment: $cuota->fresh(),
            ticket: [
                'bankAccountId' => $cuenta->id,
                'depositDate' => CarbonImmutable::parse(self::DIA),
            ],
            foto: UploadedFile::fake()->image('ticket.jpg'),
            idempotencyKey: 'test:deposito:mismo-dia',
            actorId: $this->operador()->id,
        );

        $this->assertSame('0.00', $this->recaudacion());
    }

    private function recaudacion(): string
    {
        return app(CashDayTakings::class)->of(
            $this->caja(),
            CarbonImmutable::parse(self::DIA),
            Currency::Ars,
        );
    }

    private function abrirLibros(): void
    {
        app(RegisterOpeningBalance::class)->handle(
            cashBoxId: $this->caja(),
            balances: [LedgerAccount::CashOnHand->value => '1000000.00'],
            denominations: $this->billetesPara('1000000.00'),
            date: CarbonImmutable::parse('2026-05-31'),
        );
    }

    private function caja(): int
    {
        return (int) CashBox::query()->where('code', CashBox::HABERES)->value('id');
    }
}
