<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Haberes\Support\InstallmentFunding;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Shared\Models\Receipt;
use App\Support\Money\Decimal;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * El pago que el empleador trae al mostrador.
 *
 * **Cobrar y entregar el recibo son un solo acto**, porque en el mostrador
 * lo son: el efectivo llega con el expediente y el recibo es lo que lo
 * asienta.
 *
 * Y por eso el asiento no va en el alta de la cuota: cargar una cuota es
 * reconocer un derecho del expediente, que el §2.1.1 distingue del dinero
 * recibido. Si crearla asentara plata, cargarla mal pondría en los libros
 * dinero que no entró.
 */
class PagoPorMostradorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HaberesDemoSeeder::class);
    }

    /** El circuito entero en un acto: se cobra, se asienta y sale el recibo. */
    public function test_un_pago_en_efectivo_financia_la_cuota_de_una_vez(): void
    {
        $cuota = $this->cuotaEnEfectivo();

        $this->pagar($cuota)->assertSessionHasNoErrors();

        $this->assertTrue(app(InstallmentFunding::class)->isFullyFunded($cuota->refresh()));
        $this->assertSame(1, FundReceipt::query()->count());
        $this->assertSame(1, FundingAllocation::query()->count());
        $this->assertSame(PaymentMedium::Cash, FundReceipt::query()->firstOrFail()->medium);
        // Y el comprobante salió en el mismo acto.
        $this->assertSame(1, Receipt::query()->count());
    }

    /** El dinero está en la caja, no en el banco. */
    public function test_el_asiento_del_mostrador_toca_la_caja(): void
    {
        $cuota = $this->cuotaEnEfectivo();
        $this->pagar($cuota);

        $recepcion = FundReceipt::query()->firstOrFail();

        $cuentas = DB::table('journal_lines')
            ->where('financial_event_id', $recepcion->financial_event_id)
            ->pluck('account_code')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['CASH_ON_HAND', 'UNASSIGNED_FUNDS'], $cuentas);
    }

    /**
     * El dinero no pasa por la cola de no identificados.
     *
     * Es la diferencia con el banco: acá se sabe de quién es desde el
     * primer momento, así que la recepción queda sin saldo libre.
     */
    public function test_el_pago_no_deja_dinero_sin_identificar(): void
    {
        $cuota = $this->cuotaEnEfectivo();
        $this->pagar($cuota);

        $recepcion = FundReceipt::query()->firstOrFail();

        $this->assertSame('0.00', app(InstallmentFunding::class)->unallocated($recepcion));
    }

    /** El comprobante documenta el cobro que acaba de ocurrir. */
    public function test_el_recibo_sale_del_mismo_acto(): void
    {
        $cuota = $this->cuotaEnEfectivo();
        $this->pagar($cuota)->assertSessionHasNoErrors();

        $recibo = Receipt::query()->firstOrFail();

        $this->assertSame('cash', $recibo->medium_snapshot);
        $this->assertSame($cuota->importeEsperado(), $recibo->amount);
        $this->assertSame($cuota->id, $recibo->beneficiary_installment_id);
    }

    /**
     * No se cobra de mas, y ya no hace falta rechazarlo.
     *
     * El importe lo pone la cuota. Un navegador que igual mande el suyo
     * —un formulario viejo, una pestaña abierta hace rato, alguien
     * probando— no cambia lo que se asienta.
     */
    public function test_el_importe_que_mande_el_navegador_se_ignora(): void
    {
        $cuota = $this->cuotaEnEfectivo();
        $exceso = Decimal::add($cuota->importeEsperado(), '1000.00');

        $this->pagar($cuota, ['amount' => $exceso])->assertSessionHasNoErrors();

        $this->assertSame($cuota->importeEsperado(), FundReceipt::query()->firstOrFail()->amount);
        $this->assertSame($cuota->importeEsperado(), Receipt::query()->firstOrFail()->amount);
    }

    /** El cheque entra por el mismo mostrador y queda en custodia (§2.5). */
    public function test_un_cheque_entra_en_custodia(): void
    {
        $cuota = $this->cuotaEnCheque();

        $this->pagar($cuota, [
            'chequeNumber' => '12345678',
            'chequeBank' => 'Banco Nación',
        ])->assertSessionHasNoErrors();

        $recepcion = FundReceipt::query()->firstOrFail();

        $this->assertSame(PaymentMedium::Cheque, $recepcion->medium);
        $this->assertSame('in_custody', $recepcion->cheque_status?->value);
        $this->assertSame('12345678', $recepcion->cheque_number);

        $cuentas = DB::table('journal_lines')
            ->where('financial_event_id', $recepcion->financial_event_id)
            ->pluck('account_code')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['CHEQUES_IN_CUSTODY', 'UNASSIGNED_FUNDS'], $cuentas);
    }

    /** Un cheque sin número no se puede inventariar después. */
    public function test_un_cheque_exige_su_numero(): void
    {
        $this->pagar($this->cuotaEnCheque())
            ->assertSessionHasErrors('chequeNumber');

        $this->assertSame(0, FundReceipt::query()->count());
        $this->assertSame(0, Receipt::query()->count());
    }

    /**
     * El doble clic no cobra dos veces.
     *
     * Se cobra el total porque un pago parcial no genera comprobante
     * (§2.1.14), y acá cobrar y emitir son el mismo acto.
     */
    public function test_el_segundo_envio_no_duplica_el_pago(): void
    {
        $cuota = $this->cuotaEnEfectivo();
        $mitad = $cuota->importeEsperado();

        $clave = 'pago-mostrador-unico';

        $this->pagar($cuota, ['idempotencyKey' => $clave])->assertSessionHasNoErrors();
        $this->pagar($cuota, ['idempotencyKey' => $clave])->assertSessionHasNoErrors();

        $this->assertSame(1, FundReceipt::query()->count());
        $this->assertSame(1, FundingAllocation::query()->count());
        $this->assertSame(1, Receipt::query()->count());
        $this->assertSame($mitad, app(InstallmentFunding::class)->allocated($cuota->refresh()));
    }

    /**
     * La fecha del pago vive entre el alta de la cuota y hoy.
     *
     * El piso importa tanto como el techo: antes del alta el sistema no
     * sabía nada de ese dinero, así que un cobro con fecha anterior sería
     * afirmar algo que el expediente no respalda —y quedaría escrito en el
     * libro—. La fecha propuesta es justamente el piso, porque el
     * expediente llega con el pago.
     */
    public function test_la_fecha_del_pago_no_puede_ser_anterior_al_alta_de_la_cuota(): void
    {
        $cuota = $this->cuotaEnEfectivo();
        $vispera = $cuota->created_at->subDay()->toDateString();

        $this->pagar($cuota, ['receivedDate' => $vispera])
            ->assertSessionHasErrors('receivedDate');

        $this->assertSame(0, FundReceipt::query()->count());
        $this->assertSame(0, Receipt::query()->count());

        // El día del alta sí, que es lo que el formulario propone.
        $this->pagar($cuota, ['receivedDate' => $cuota->created_at->toDateString()])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            $cuota->created_at->toDateString(),
            FundReceipt::query()->firstOrFail()->received_date->toDateString(),
        );
    }

    /** Tampoco después de hoy: no se cobra lo que todavía no pasó. */
    public function test_la_fecha_del_pago_no_puede_ser_futura(): void
    {
        $this->pagar($this->cuotaEnEfectivo(), ['receivedDate' => now()->addDay()->toDateString()])
            ->assertSessionHasErrors('receivedDate');

        $this->assertSame(0, FundReceipt::query()->count());
    }

    /** La pantalla ofrece el botón en las cuotas de mostrador. */
    public function test_la_cuota_de_mostrador_llega_con_lo_necesario_para_cobrar(): void
    {
        $cuota = $this->cuotaEnEfectivo();
        $haber = $cuota->haber;

        $this->actingAs($this->operador())
            ->get(route('haberes.haber.show', [$haber->expediente, $haber]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canRegisterPayment', true)
                // La fecha que el formulario propone para el cobro.
                ->has('haber.installments.0.createdAt')
                ->where('haber.installments.0.expectedMedium', 'cash')
                ->where('haber.installments.0.isFullyFunded', false),
            );
    }

    public function test_quien_solo_consulta_no_cobra(): void
    {
        $cuota = $this->cuotaEnEfectivo();

        $this->actingAs($this->operador('consulta'))
            ->post(route('haberes.installments.receipt', $cuota), $this->datos())
            ->assertForbidden();

        $this->assertSame(0, FundReceipt::query()->count());
        $this->assertSame(0, Receipt::query()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Andamiaje
    |--------------------------------------------------------------------------
    */

    private function cuotaEnEfectivo(): BeneficiaryInstallment
    {
        $cuota = Expediente::query()
            ->whereNotNull('employer_id')
            ->orderBy('id')
            ->firstOrFail()
            ->haberes()
            ->orderBy('id')
            ->firstOrFail()
            ->installments()
            ->orderBy('installment_number')
            ->firstOrFail();

        $cuota->forceFill(['expected_medium' => 'cash'])->save();

        return $cuota->refresh();
    }

    private function cuotaEnCheque(): BeneficiaryInstallment
    {
        $cuota = $this->cuotaEnEfectivo();
        $cuota->forceFill(['expected_medium' => 'cheque'])->save();

        return $cuota->refresh();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function pagar(
        BeneficiaryInstallment $cuota,
        array $extra = [],
    ): TestResponse {
        return $this->actingAs($this->operador())
            ->post(route('haberes.installments.receipt', $cuota), [
                ...$this->datos(),
                ...$extra,
            ]);
    }

    /**
     * Lo unico que el formulario aporta al cobro.
     *
     * El importe, el medio y la caja no estan: los deriva el servidor de
     * la propia cuota.
     *
     * @return array<string, mixed>
     */
    private function datos(): array
    {
        return [
            'receivedDate' => now()->toDateString(),
            'idempotencyKey' => 'pago-'.Str::random(10),
        ];
    }
}
