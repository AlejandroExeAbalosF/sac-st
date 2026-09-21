<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Banking\Actions\RegisterBankFundReceipt;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankStatementImport;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Haberes\Actions\AllocateFundsToInstallment;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Ledger\Actions\RegisterCashFundReceipt;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Models\Receipt;
use App\Modules\Shared\Pdf\IncomeReceiptPdf;
use App\Support\Money\Decimal;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * El recibo visto desde la cuota.
 *
 * La tarjeta decide qué ofrecer con lo que le llega, así que lo que se
 * prueba acá es que le llegue lo necesario: cuánto tiene financiado, si
 * está completa, y el recibo cuando ya existe. Sin eso el botón no puede
 * aparecer en el momento correcto.
 */
class PantallaDelReciboTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HaberesDemoSeeder::class);
    }

    /** Sin financiar, la tarjeta no tiene nada que ofrecer. */
    public function test_una_cuota_sin_financiar_llega_en_cero(): void
    {
        $cuota = $this->cuota();

        $this->verHaber($cuota)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('haber.installments.0.fundedAmount', '0.00')
                ->where('haber.installments.0.isFullyFunded', false)
                ->where('haber.installments.0.incomeReceipt', null)
                ->where('canIssueReceipt', true),
            );
    }

    /** A medio financiar tampoco: §2.1.14, un ingreso parcial no genera comprobante. */
    public function test_una_cuota_a_medias_no_figura_como_completa(): void
    {
        $cuota = $this->cuota();
        $mitad = Decimal::scale(bcdiv($cuota->importeEsperado(), '2', 4));

        $this->financiar($cuota, $mitad);

        $this->verHaber($cuota)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('haber.installments.0.fundedAmount', $mitad)
                ->where('haber.installments.0.isFullyFunded', false)
                ->where('haber.installments.0.remainingAmount', Decimal::sub($cuota->importeEsperado(), $mitad))
                ->where('haber.installments.0.incomeReceipt', null),
            );
    }

    /** Completa y sin recibo: es el estado en el que el botón aparece. */
    public function test_una_cuota_completa_llega_lista_para_emitir(): void
    {
        $cuota = $this->cuotaFinanciada();

        $this->verHaber($cuota)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('haber.installments.0.isFullyFunded', true)
                ->where('haber.installments.0.remainingAmount', '0.00')
                ->where('haber.installments.0.incomeReceipt', null),
            );
    }

    public function test_emitir_desde_la_cuota_deja_el_recibo_a_la_vista(): void
    {
        $cuota = $this->cuotaFinanciada();

        $this->actingAs($this->operador())
            ->post(route('haberes.installments.receipt', $cuota), ['idempotencyKey' => 'recibo-'.Str::random(8)])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $recibo = Receipt::query()->firstOrFail();

        $this->verHaber($cuota)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('haber.installments.0.incomeReceipt.formattedNumber', $recibo->formatted_number)
                ->where('haber.installments.0.incomeReceipt.amount', $cuota->importeEsperado())
                ->where('haber.installments.0.incomeReceipt.talonarioNumber', null),
            );
    }

    /** El número del papel viaja con el formulario y queda al lado del del sistema. */
    public function test_se_puede_emitir_cargando_el_numero_del_talonario(): void
    {
        $cuota = $this->cuotaFinanciada();

        $this->actingAs($this->operador())
            ->post(route('haberes.installments.receipt', $cuota), [
                'talonarioNumber' => '00071514',
                'idempotencyKey' => 'recibo-talonario',
            ])
            ->assertSessionHasNoErrors();

        $this->verHaber($cuota)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('haber.installments.0.incomeReceipt.talonarioNumber', '00071514')
                ->where('haber.installments.0.incomeReceipt.issueMode', 'offline_talonario'),
            );
    }

    /**
     * El rechazo del Action llega al formulario, no como excepción.
     *
     * Con el medio en `bank` no hay nada que cobrar por mostrador: ese
     * dinero entra por el extracto, y hasta que la asignación lo complete
     * no hay comprobante que emitir (§2.1.14).
     *
     * **La financiación parcial va por banco, y eso es la prueba.** Antes
     * entraba en efectivo: la cuota decía «transferencia» y tenía plata de
     * mostrador adentro, y el rechazo salía de creerle a la columna en vez
     * de al dinero. Con el medio efectivo mandando, una cuota que sostiene
     * efectivo sí puede terminar de cobrarse en el mostrador —que es lo
     * correcto—, así que el caso hay que armarlo como dice el enunciado.
     */
    public function test_emitir_con_la_cuota_incompleta_devuelve_un_error(): void
    {
        $cuota = $this->cuota();
        $cuota->forceFill(['expected_medium' => 'bank'])->save();
        $this->financiarPorBanco($cuota->refresh(), '1000.00');

        $this->actingAs($this->operador())
            ->post(route('haberes.installments.receipt', $cuota), ['idempotencyKey' => 'recibo-'.Str::random(8)])
            ->assertSessionHasErrors('installmentId');

        $this->assertSame(0, Receipt::query()->count());
    }

    public function test_quien_solo_consulta_no_emite_recibos(): void
    {
        $cuota = $this->cuotaFinanciada();

        $this->actingAs($this->operador('consulta'))
            ->post(route('haberes.installments.receipt', $cuota), ['idempotencyKey' => 'recibo-'.Str::random(8)])
            ->assertForbidden();

        $this->assertSame(0, Receipt::query()->count());

        // Y la pantalla no le ofrece el botón.
        $this->actingAs($this->operador('consulta'))
            ->get($this->urlDelHaber($cuota))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canIssueReceipt', false));
    }

    /** Emitir es trabajo de mostrador: lo hace quien atiende. */
    public function test_el_administrativo_emite_recibos(): void
    {
        $cuota = $this->cuotaFinanciada();

        $this->actingAs($this->operador('administrativo'))
            ->post(route('haberes.installments.receipt', $cuota), ['idempotencyKey' => 'recibo-'.Str::random(8)])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Receipt::query()->count());
    }

    /**
     * La vista previa se puede enmarcar, y el resto del sistema no.
     *
     * El dialogo la muestra en un iframe del mismo origen. Con la politica
     * global —`frame-ancestors 'none'`, que es la correcta contra el
     * clickjacking— el navegador bloqueaba el marco y no se veia nada: sin
     * error en la pagina, sin fallo en el servidor, un rectangulo en
     * blanco. La excepcion existia pero apuntaba a un nombre de ruta que
     * nunca existio.
     */
    public function test_la_vista_previa_se_puede_mostrar_en_un_iframe(): void
    {
        $cuota = $this->cuotaFinanciada();

        $previa = $this->actingAs($this->operador())
            ->get(route('haberes.installments.receipt.preview', $cuota));

        $previa->assertOk();
        $previa->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $this->assertStringContainsString(
            "frame-ancestors 'self'",
            (string) $previa->headers->get('Content-Security-Policy'),
        );

        // Y la pantalla que la contiene sigue sin poder enmarcarse.
        $this->verHaber($cuota)->assertHeader('X-Frame-Options', 'DENY');
    }

    /**
     * Antes de cobrar, el papel muestra lo que se va a cobrar.
     *
     * El importe del recibo sale de las asignaciones, y en el mostrador
     * todavía no hay ninguna: la previa mostraba un recibo por $ 0,00. Lo
     * que se va a cobrar lo dice la cuota, que es de donde hay que leerlo
     * —el navegador no tiene voz en cuánto dinero se asienta—.
     */
    public function test_la_previa_muestra_el_importe_de_la_cuota(): void
    {
        $cuota = $this->cuota();
        $cuota->forceFill(['expected_medium' => 'cash'])->save();

        $previa = $this->actingAs($this->operador())
            ->get(route('haberes.installments.receipt.preview', $cuota->refresh()));

        $previa->assertOk();
        $previa->assertSee(Decimal::format($cuota->importeEsperado()));
        $previa->assertDontSee('$ 0,00');
    }

    /**
     * Cuál de los dos números encabeza el papel lo elige el operador.
     *
     * Los dos conviven: con el del talonario arriba, el del sistema baja a
     * referencia. La relación entre ambos queda guardada, así que ninguna
     * de las dos versiones deja de ser rastreable.
     */
    public function test_se_puede_encabezar_con_el_numero_del_talonario(): void
    {
        $cuota = $this->cuotaFinanciada();
        $url = route('haberes.installments.receipt.preview', $cuota);

        $conTalonario = $this->actingAs($this->operador())
            ->get($url.'?talonarioNumber=00071514&printsTalonarioNumber=1');

        $conTalonario->assertOk();
        $conTalonario->assertSee('00071514');
        $conTalonario->assertSee('Registro 0010/00000001');

        // Y con el interruptor apagado manda el del sistema, aunque el
        // número del talonario esté cargado.
        $conSistema = $this->actingAs($this->operador())
            ->get($url.'?talonarioNumber=00071514');

        $conSistema->assertOk();
        $conSistema->assertSee('0010/00000001');
        $conSistema->assertDontSee('Registro');
    }

    /** La elección se guarda: la reimpresión sale igual que el original. */
    public function test_la_eleccion_del_numero_sobrevive_a_la_reimpresion(): void
    {
        $cuota = $this->cuotaFinanciada();

        $this->actingAs($this->operador())
            ->post(route('haberes.installments.receipt', $cuota), [
                'talonarioNumber' => '00071514',
                'printsTalonarioNumber' => true,
                'idempotencyKey' => 'recibo-encabezado',
            ])
            ->assertSessionHasNoErrors();

        $recibo = Receipt::query()->firstOrFail();

        $this->assertTrue($recibo->prints_talonario_number);

        // El PDF sale por la ruta; lo que dice se lee en la plantilla, que
        // es la misma para la impresion y para la previa.
        $this->actingAs($this->operador())
            ->get(route('recibos.print', $recibo))
            ->assertOk();

        $papel = app(IncomeReceiptPdf::class)->preview($recibo)->render();

        $this->assertStringContainsString('00071514', $papel);
        $this->assertStringContainsString('Registro '.$recibo->formatted_number, $papel);
    }

    /**
     * No se encabeza con un número que no se cargó.
     *
     * Lo impide la base, además del Action: es la clase de incoherencia
     * que después aparece impresa.
     */
    public function test_no_se_encabeza_con_un_talonario_ausente(): void
    {
        $cuota = $this->cuotaFinanciada();

        $this->actingAs($this->operador())
            ->post(route('haberes.installments.receipt', $cuota), [
                'printsTalonarioNumber' => true,
                'idempotencyKey' => 'recibo-sin-talonario',
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse(Receipt::query()->firstOrFail()->prints_talonario_number);
    }

    /**
     * El visor muestra el papel enmarcado y deja elegir la salida.
     *
     * Sin parámetro manda lo que se eligió al emitir —para eso se guarda—
     * y el interruptor pide el otro para esta copia. Los dos números están
     * en el mismo registro, así que ninguna versión deja de ser rastreable.
     */
    public function test_el_visor_alterna_el_numero_sin_tocar_lo_emitido(): void
    {
        $recibo = $this->reciboConTalonario();
        $url = route('recibos.view', $recibo);

        // Lo emitido: encabezado por el talonario.
        $comoSeEmitio = $this->actingAs($this->operador())->get($url);
        $comoSeEmitio->assertOk();
        $comoSeEmitio->assertSee('Registro '.$recibo->formatted_number);

        // Y esta copia, con el del sistema arriba.
        $laOtra = $this->actingAs($this->operador())->get($url.'?printsTalonarioNumber=0');
        $laOtra->assertOk();
        $laOtra->assertSee($recibo->formatted_number);
        $laOtra->assertDontSee('Registro');

        // Sin que el comprobante haya cambiado.
        $this->assertTrue($recibo->refresh()->prints_talonario_number);
    }

    /** El visor se puede enmarcar; el resto del sistema no. */
    public function test_el_visor_se_puede_mostrar_en_un_iframe(): void
    {
        $vista = $this->actingAs($this->operador())
            ->get(route('recibos.view', $this->reciboConTalonario()));

        $vista->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    /** Quien solo consulta puede mirarlo y reimprimirlo. */
    public function test_quien_solo_consulta_puede_ver_el_recibo(): void
    {
        $this->actingAs($this->operador('consulta'))
            ->get(route('recibos.view', $this->reciboConTalonario()))
            ->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | Andamiaje
    |--------------------------------------------------------------------------
    */

    private function reciboConTalonario(): Receipt
    {
        $this->actingAs($this->operador())
            ->post(route('haberes.installments.receipt', $this->cuotaFinanciada()), [
                'talonarioNumber' => '00071514',
                'printsTalonarioNumber' => true,
                'idempotencyKey' => 'recibo-visor',
            ])
            ->assertSessionHasNoErrors();

        return Receipt::query()->firstOrFail();
    }

    private function cuota(): BeneficiaryInstallment
    {
        return $this->expediente()->haberes()->firstOrFail()->installments()->firstOrFail();
    }

    private function cuotaFinanciada(): BeneficiaryInstallment
    {
        $cuota = $this->cuota();
        $this->financiar($cuota, $cuota->importeEsperado());

        return $cuota->refresh();
    }

    private function expediente(): Expediente
    {
        return Expediente::query()->whereNotNull('employer_id')->orderBy('id')->firstOrFail();
    }

    private function urlDelHaber(BeneficiaryInstallment $cuota): string
    {
        $haber = $cuota->haber;

        return route('haberes.haber.show', [$haber->expediente, $haber]);
    }

    private function verHaber(BeneficiaryInstallment $cuota): TestResponse
    {
        return $this->actingAs($this->operador())
            ->get($this->urlDelHaber($cuota))
            ->assertOk();
    }

    /** Efectivo por mostrador, asignado a la cuota. */
    /** El circuito bancario: movimiento del extracto → recepción → imputación. */
    private function financiarPorBanco(BeneficiaryInstallment $cuota, string $importe): FundReceipt
    {
        $cuenta = BankAccount::query()->create([
            'label' => 'Cta. Cte. 2693 — Haberes',
            'bank_name' => 'Banco Macro',
            'account_number' => '310000123456789',
            'currency' => 'ARS',
            'is_active' => true,
        ]);

        $importacion = BankStatementImport::query()->create([
            'bank_account_id' => $cuenta->id,
            'imported_by' => $this->operador()->id,
            'original_filename' => 'extracto.csv',
            'file_size' => 100,
            'file_sha256' => hash('sha256', uniqid('', true)),
            'source_format' => 'macro_online_csv',
            'parser_version' => 'macro-csv-1',
            'period_from' => '2026-08-01',
            'period_to' => '2026-08-31',
            'status' => 'completed',
            'rows_total' => 1,
            'rows_valid' => 1,
            'rows_rejected' => 0,
            'rows_new' => 1,
            'rows_duplicate' => 0,
            'imported_at' => now(),
        ]);

        $movimiento = BankTransaction::query()->create([
            'bank_account_id' => $cuenta->id,
            'first_seen_import_id' => $importacion->id,
            'transaction_date' => '2026-08-05',
            'amount' => $importe,
            'direction' => TransactionDirection::Credit,
            'description' => 'Transferencia recibida',
            'fingerprint' => hash('sha256', uniqid('', true)),
        ]);

        $recepcion = app(RegisterBankFundReceipt::class)->handle(
            transaction: $movimiento,
            amount: $importe,
            idempotencyKey: 'banco-'.Str::random(10),
            cashBoxId: (int) CashBox::query()->where('code', CashBox::HABERES)->value('id'),
        );

        app(AllocateFundsToInstallment::class)->handle(
            receipt: $recepcion,
            installment: $cuota,
            amount: $importe,
            idempotencyKey: 'asignacion-'.Str::random(10),
        );

        return $recepcion;
    }

    private function financiar(BeneficiaryInstallment $cuota, string $importe): FundReceipt
    {
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

        return $recepcion;
    }
}
