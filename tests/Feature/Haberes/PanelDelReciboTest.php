<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Models\User;
use App\Modules\Haberes\Actions\AllocateFundsToInstallment;
use App\Modules\Haberes\Actions\IssueIncomeReceipt;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Ledger\Actions\RegisterCashFundReceipt;
use App\Modules\Ledger\Actions\RegisterOpeningBalance;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Models\Person;
use App\Modules\Shared\Models\Receipt;
use Carbon\CarbonImmutable;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * De quién es este comprobante.
 *
 * El libro del día lista números de recibo y nada más. La pregunta que
 * aparece conciliando la caja —«¿a qué haber corresponde este?»— obligaba
 * a salir de la pantalla; el panel lateral la contesta sin moverse.
 *
 * Lo que se prueba acá es **la traducción**: `receipts` guarda
 * `beneficiary_installment_id` como un entero suelto, sin clave foránea,
 * porque vive en `Shared` y `Shared` no puede depender de `Haberes`.
 * Convertir ese número en una cuota, un haber y un expediente es trabajo
 * de este módulo, y es lo único que hay entre el papel y la pantalla.
 */
class PanelDelReciboTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HaberesDemoSeeder::class);
    }

    public function test_el_panel_resuelve_el_haber_del_comprobante(): void
    {
        $cuota = $this->cuotaFinanciada();
        $recibo = app(IssueIncomeReceipt::class)->handle($cuota);

        $haber = $cuota->haber;
        $expediente = $haber->expediente;

        $this->actingAs($this->operador())
            ->getJson(route('recibos.panel', $recibo))
            ->assertOk()
            ->assertJsonPath('receipt.formattedNumber', $recibo->formatted_number)
            ->assertJsonPath('medium', 'cash')
            ->assertJsonPath('subject.installmentId', $cuota->id)
            ->assertJsonPath('subject.installmentNumber', $cuota->installment_number)
            ->assertJsonPath('subject.haberId', $haber->id)
            ->assertJsonPath('subject.haberNumber', $haber->haber_number)
            ->assertJsonPath('subject.expedienteId', $expediente->id)
            ->assertJsonPath('subject.expedienteNumber', $expediente->display_number)
            ->assertJsonPath('subject.beneficiaryName', $haber->beneficiary->name);
    }

    /**
     * Un pago de haber anterior no tiene a dónde apuntar, y lo dice.
     *
     * Su número de expediente es la referencia al registro manual, no un
     * expediente del sistema. Devolver `subject` en null es lo que permite
     * que la pantalla muestre el dato sin ofrecer un enlace que no lleva a
     * ninguna parte.
     */
    public function test_un_pago_de_haber_anterior_no_trae_sujeto(): void
    {
        $recibo = $this->pagoDeHaberAnterior();

        $this->actingAs($this->operador())
            ->getJson(route('recibos.panel', $recibo))
            ->assertOk()
            ->assertJsonPath('subject', null)
            ->assertJsonPath('expedienteNumberOnPaper', '131010/2023');
    }

    public function test_sin_permiso_de_ver_recibos_no_hay_panel(): void
    {
        $cuota = $this->cuotaFinanciada();
        $recibo = app(IssueIncomeReceipt::class)->handle($cuota);

        /*
         * Sin rol, no sin permisos: `can:` los resuelve a través del rol,
         * así que sacárselos al usuario no cambia nada mientras el rol se
         * los siga dando.
         */
        $this->actingAs(User::factory()->create())
            ->getJson(route('recibos.panel', $recibo))
            ->assertForbidden();
    }

    /*
    |---------------------------------------------------------------------
    | Andamiaje
    |---------------------------------------------------------------------
    */

    private function cuotaFinanciada(): BeneficiaryInstallment
    {
        $cuota = Expediente::query()
            ->whereNotNull('employer_id')
            ->orderBy('id')
            ->firstOrFail()
            ->haberes()->firstOrFail()
            ->installments()->firstOrFail();

        $importe = $cuota->importeEsperado();

        app(AllocateFundsToInstallment::class)->handle(
            receipt: $this->recepcionEnEfectivo($importe),
            installment: $cuota,
            amount: $importe,
            idempotencyKey: 'asignacion-'.Str::random(10),
        );

        return $cuota->refresh();
    }

    private function recepcionEnEfectivo(string $importe): FundReceipt
    {
        return app(RegisterCashFundReceipt::class)->handle(
            amount: $importe,
            idempotencyKey: 'efectivo-'.Str::random(10),
            cashBoxId: (int) CashBox::query()->where('code', CashBox::HABERES)->value('id'),
            receivedDate: now(),
        );
    }

    /** El circuito viejo, que emite recibo de egreso y no cuelga de ninguna cuota. */
    private function pagoDeHaberAnterior(): Receipt
    {
        $caja = (int) CashBox::query()->where('code', CashBox::HABERES)->value('id');

        app(RegisterOpeningBalance::class)->handle(
            cashBoxId: $caja,
            balances: [LedgerAccount::CashOnHand->value => '1000000.00'],
            date: CarbonImmutable::parse('2026-06-01'),
        );

        $this->actingAs($this->operador('contador'))
            ->post('/caja/pagos-anteriores', [
                'cashBoxId' => $caja,
                'personId' => Person::query()->firstOrFail()->id,
                'amount' => '300000.00',
                'legacyReference' => '131010/2023',
                'paymentDate' => '2026-06-10',
                'medium' => 'cash',
                'currency' => 'ARS',
            ])
            ->assertSessionHasNoErrors();

        return Receipt::query()
            ->whereNull('beneficiary_installment_id')
            ->latest('id')
            ->firstOrFail();
    }
}
