<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Haberes\Actions\AllocateFundsToInstallment;
use App\Modules\Haberes\Actions\DeliverToBeneficiary;
use App\Modules\Haberes\Actions\FundInstallmentFromLegacy;
use App\Modules\Haberes\Actions\IssueIncomeReceipt;
use App\Modules\Haberes\Actions\UnallocateFunds;
use App\Modules\Haberes\Actions\VoidLegacyIncomeDocument;
use App\Modules\Haberes\Enums\ExpectedMedium;
use App\Modules\Haberes\Enums\InstallmentStage;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Enums\LegacyDocumentKind;
use App\Modules\Haberes\Enums\ReceiptNumberSource;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Haberes\Models\LegacyDocument;
use App\Modules\Haberes\Support\DisbursementEligibility;
use App\Modules\Haberes\Support\InstallmentFunding;
use App\Modules\Haberes\Support\InstallmentStages;
use App\Modules\Haberes\Support\LegacyFundsOptions;
use App\Modules\Haberes\Support\LegacyPaper;
use App\Modules\Haberes\Support\PaymentOrderEligibility;
use App\Modules\Ledger\Actions\RegisterCashFundReceipt;
use App\Modules\Ledger\Actions\RegisterOpeningBalance;
use App\Modules\Ledger\Enums\ChequeStatus;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FundReceiptOrigin;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Ledger\Support\CashBalance;
use App\Modules\Ledger\Support\CashDayTakings;
use App\Modules\Ledger\Support\UndetailedCheques;
use App\Modules\Shared\Models\CashBox;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CollectsInstallments;
use Tests\TestCase;

/**
 * Apartar del saldo del sistema anterior la plata de una cuota.
 *
 * El asiento cambia el dueño del dinero sin moverlo de lugar, y desde ahí
 * la cuota sigue el circuito con su recibo de ingreso de papel. Cada
 * invariante se prueba en el Action y en la base; los triggers diferidos
 * necesitan `SET CONSTRAINTS ALL IMMEDIATE`.
 */
class FondosAnterioresTest extends TestCase
{
    use CollectsInstallments;
    use RefreshDatabase;

    /* ── Apartar ─────────────────────────────────────────────────────── */

    public function test_apartar_efectivo_cambia_el_dueno_sin_mover_el_dinero(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuota('200/2024', '85000.00');

        $this->apartar($cuota, PaymentMedium::Cash);

        $saldos = app(CashBalance::class);

        $this->assertSame($this->restoDelSistemaAnterior('85000.00'), $saldos->of(LedgerAccount::LegacyFunds, $this->caja()));
        $this->assertSame('1000000.00', $saldos->of(LedgerAccount::CashOnHand, $this->caja()));
        $this->assertSame('85000.00', $saldos->of(LedgerAccount::BeneficiaryFunds, $this->caja()));
        $this->assertTrue(app(InstallmentFunding::class)->isFullyFunded($cuota));

        $recepcion = FundReceipt::query()->where('origin', FundReceiptOrigin::Legacy->value)->sole();
        $this->assertSame(PaymentMedium::Cash, $recepcion->medium);
        $this->assertSame(1, LegacyDocument::query()->where('kind', 'income_receipt')->count());
    }

    /** El apartado no entró hoy: ya estaba en el cajón. */
    public function test_el_apartado_no_figura_como_recaudacion_ni_en_la_cola_de_recepciones(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuota('201/2024', '85000.00');

        $this->apartar($cuota, PaymentMedium::Cash);

        $this->assertSame(
            '0.00',
            app(CashDayTakings::class)->of($this->caja(), BusinessDate::today(), Currency::Ars),
        );

        $this->actingAs($this->operador('contador'))
            ->get('/recepciones')
            ->assertInertia(fn ($page) => $page->where('receipts.data', []));
    }

    public function test_apartar_un_deposito_directo_deja_la_cuenta_para_la_orden(): void
    {
        $cuenta = $this->cuentaBancaria();
        $this->abrirLibros(banco: '300000.00', cuentaBancaria: $cuenta);
        $cuota = $this->cuotaPor('202/2024', '85000.00', ExpectedMedium::Bank);

        $this->apartar($cuota, PaymentMedium::Bank, bankAccountId: $cuenta);

        $estado = app(PaymentOrderEligibility::class)->for($cuota->refresh());

        $this->assertTrue($estado->applies);
        $this->assertNull($estado->blockedReason);
        $this->assertTrue($estado->incomeReceipt?->isPaper());
        $this->assertSame($cuenta, $estado->organismBankAccountId);
        // Un recibo de papel imprime siempre su número de talonario.
        $this->assertSame('3121', $estado->incomeReceipt?->numberFor(ReceiptNumberSource::System));
    }

    public function test_apartar_dos_cheques_enteros_de_la_cartera(): void
    {
        $this->abrirLibros(cheques: [['A-1', '50000.00'], ['A-2', '35000.00']]);
        $cuota = $this->cuotaPor('203/2024', '85000.00', ExpectedMedium::Cheque);
        [$uno, $dos] = FundReceipt::query()->where('origin', 'opening')->orderBy('id')->get()->all();

        $asignaciones = $this->apartar($cuota, PaymentMedium::Cheque, sources: [
            ['amount' => '50000.00', 'chequeReceiptId' => $uno->id],
            ['amount' => '35000.00', 'chequeReceiptId' => $dos->id],
        ]);

        $this->assertCount(2, $asignaciones);
        $this->assertSame('0.00', app(InstallmentFunding::class)->unallocated($dos));
        // Los cheques ya eran recepciones: no se crean otras.
        $this->assertSame(0, FundReceipt::query()->where('origin', 'legacy')->count());
    }

    /**
     * Un cheque no se reparte entre cuotas: entregar una marcaría entregado
     * el papel entero y el resto quedaría en custodia sin cheque detrás.
     */
    public function test_un_cheque_del_sistema_anterior_no_se_aparta_en_parte(): void
    {
        $this->abrirLibros(cheques: [['A-1', '50000.00'], ['A-2', '40000.00']]);
        $cuota = $this->cuotaPor('230/2024', '85000.00', ExpectedMedium::Cheque);
        [$uno, $dos] = FundReceipt::query()->where('origin', 'opening')->orderBy('id')->get()->all();

        try {
            $this->apartar($cuota, PaymentMedium::Cheque, sources: [
                ['amount' => '50000.00', 'chequeReceiptId' => $uno->id],
                ['amount' => '35000.00', 'chequeReceiptId' => $dos->id],
            ]);
            $this->fail('Se apartó un cheque en parte.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('se reserva entero', $e->errors()['sources'][0]);
        }

        $evento = $this->eventoApartado($cuota, '35000.00');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('se asigna y se libera entero');

        $this->insertarAsignacion($cuota, $dos, $evento, '35000.00');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_un_cheque_del_sistema_anterior_se_libera_entero(): void
    {
        $this->abrirLibros(cheques: [['A-6', '85000.00']]);
        $cuota = $this->cuotaPor('231/2024', '85000.00', ExpectedMedium::Cheque);
        $cheque = FundReceipt::query()->where('origin', 'opening')->sole();
        $this->apartar($cuota, PaymentMedium::Cheque, sources: [['amount' => '85000.00', 'chequeReceiptId' => $cheque->id]]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('se libera entero');

        app(UnallocateFunds::class)->handle(FundingAllocation::query()->sole(), '5000.00', 'test-parcial-231', 'La cuota bajó.');
    }

    /**
     * Un cheque identificado al apartar es un papel de la cartera: liberado,
     * vuelve a la lista y se aparta para otra cuota.
     */
    public function test_un_cheque_identificado_y_liberado_se_vuelve_a_apartar(): void
    {
        $this->abrirLibros(chequesSinDetalle: '85000.00');
        $primera = $this->cuotaPor('232/2024', '85000.00', ExpectedMedium::Cheque);
        $segunda = $this->cuotaPor('233/2024', '85000.00', ExpectedMedium::Cheque);

        $this->apartar($primera, PaymentMedium::Cheque, sources: [
            ['amount' => '85000.00', 'newCheque' => ['number' => '00045871', 'bank' => null, 'issueDate' => null]],
        ]);
        app(UnallocateFunds::class)->handle(FundingAllocation::query()->sole(), '85000.00', 'test-libera-232', 'Era de otra cuota.');

        $cheque = FundReceipt::query()->where('origin', 'legacy')->sole();
        $opciones = app(LegacyFundsOptions::class)->for($segunda->haber);
        $this->assertSame([$cheque->id], array_map(fn ($c) => $c->id, $opciones->cheques));

        app(FundInstallmentFromLegacy::class)->handle(
            installment: $segunda,
            medium: PaymentMedium::Cheque,
            sources: [['amount' => '85000.00', 'chequeReceiptId' => $cheque->id]],
            income: new LegacyPaper(LegacyDocumentKind::IncomeReceipt, 'income', '3122', CarbonImmutable::parse('2025-03-10'), '85000.00'),
            idempotencyKey: 'test-reapartar-233',
        );

        $this->assertTrue(app(InstallmentFunding::class)->isFullyFunded($segunda));
        $this->assertSame(1, FundReceipt::query()->where('origin', 'legacy')->count());
    }

    /*
     * ── Lo que queda del sistema anterior en cada lugar ───────────────
     *
     * El cajón y la cuenta guardan también plata que entró después, y el
     * saldo del sistema anterior es uno solo para los tres lugares. Que el
     * cajón tenga billetes y que el saldo viejo alcance no dice que quede
     * plata vieja en el cajón.
     */

    /**
     * El caso que motivó el control.
     *
     * Se abrió con $ 300.000 en efectivo y ya se apartaron $ 200.000: del
     * sistema anterior quedan $ 100.000 en efectivo. El cajón sigue
     * teniendo $ 300.000 —apartar no saca billetes— y el saldo viejo
     * alcanza por lo que hay en el banco. Ninguno de los dos controles de
     * antes lo frenaba.
     */
    public function test_no_se_aparta_efectivo_viejo_que_ya_se_aparto(): void
    {
        $this->abrirLibros(efectivo: '300000.00', banco: '1000000.00', cuentaBancaria: $this->cuentaBancaria());
        $primera = $this->cuotaPor('301/2024', '200000.00', ExpectedMedium::Cash);
        $segunda = $this->cuotaPor('302/2024', '200000.00', ExpectedMedium::Cash);
        $this->apartar($primera, PaymentMedium::Cash);

        try {
            $this->apartar($segunda, PaymentMedium::Cash, talonario: '3122');
            $this->fail('Se apartó como efectivo viejo plata que ya tenía dueño.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('quedan 100.000,00 en efectivo', $e->errors()['amount'][0]);
        }

        // Liberar la primera devuelve su efectivo al sistema anterior.
        app(UnallocateFunds::class)->handle(
            FundingAllocation::query()->where('beneficiary_installment_id', $primera->id)->sole(),
            '200000.00',
            'test-liberar-301',
            'Se apartó para la cuota equivocada.',
        );

        $this->apartar($segunda, PaymentMedium::Cash, talonario: '3122');
        $this->assertTrue(app(InstallmentFunding::class)->isFullyFunded($segunda));
    }

    /** Lo mismo con el depósito directo, cuenta por cuenta. */
    public function test_no_se_aparta_de_una_cuenta_mas_de_lo_viejo_que_le_queda(): void
    {
        $cuenta = $this->cuentaBancaria();
        $this->abrirLibros(banco: '300000.00', cuentaBancaria: $cuenta);
        $primera = $this->cuotaPor('303/2024', '200000.00', ExpectedMedium::Bank);
        $segunda = $this->cuotaPor('304/2024', '200000.00', ExpectedMedium::Bank);
        $this->apartar($primera, PaymentMedium::Bank, bankAccountId: $cuenta);

        try {
            $this->apartar($segunda, PaymentMedium::Bank, bankAccountId: $cuenta, talonario: '3122');
            $this->fail('Se apartó de la cuenta más plata vieja de la que quedaba.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('quedan 100.000,00 en la cuenta', $e->errors()['bankAccountId'][0]);
        }
    }

    /** La base lo rechaza aunque no se pase por el Action. */
    public function test_la_base_no_deja_apartar_mas_efectivo_viejo_del_que_queda(): void
    {
        $this->abrirLibros(efectivo: '300000.00', banco: '1000000.00', cuentaBancaria: $this->cuentaBancaria());
        $cuota = $this->cuotaPor('305/2024', '400000.00', ExpectedMedium::Cash);
        $evento = $this->eventoApartado($cuota, '400000.00');
        $this->recepcionLegacy($evento, '400000.00');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('no queda tanto en efectivo');

        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    /** El diálogo dice cuánto queda en cada lugar: es el tope de lo que se aparta. */
    public function test_el_dialogo_informa_lo_que_queda_en_cada_lugar(): void
    {
        $cuenta = $this->cuentaBancaria();
        $this->abrirLibros(efectivo: '300000.00', banco: '1000000.00', cuentaBancaria: $cuenta);
        $cuota = $this->cuotaPor('306/2024', '200000.00', ExpectedMedium::Cash);
        $this->apartar($cuota, PaymentMedium::Cash);

        $opciones = app(LegacyFundsOptions::class)->for($cuota->haber);

        $this->assertSame('100000.00', $opciones->cash);
        $this->assertSame(
            [['id' => $cuenta, 'label' => 'Cta. Cte. 310000123456789', 'available' => '1000000.00']],
            $opciones->bankAccounts,
        );
    }

    /** Y el saldo del sistema anterior muestra, en cada lugar, la parte vieja. */
    public function test_pagos_anteriores_muestra_lo_viejo_de_cada_lugar(): void
    {
        $this->abrirLibros(
            efectivo: '300000.00',
            banco: '1000000.00',
            cuentaBancaria: $this->cuentaBancaria(),
            chequesSinDetalle: '50000.00',
        );
        $this->apartar($this->cuotaPor('307/2024', '200000.00', ExpectedMedium::Cash), PaymentMedium::Cash);

        $this->actingAs($this->operador('contador'))
            ->get('/caja/saldo-anterior')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('balances.cash', '300000.00')
                ->where('legacyByPlace.cash', '100000.00')
                ->where('legacyByPlace.cheques', '50000.00')
                ->where('legacyByPlace.bank', '1000000.00'));
    }

    /** El depósito directo tiene que estar en la cuenta elegida, no en cualquiera. */
    public function test_el_deposito_directo_mira_el_saldo_de_la_cuenta_elegida(): void
    {
        $conPlata = $this->cuentaBancaria();
        $vacia = $this->cuentaBancaria('310000999999999');
        $this->abrirLibros(banco: '300000.00', cuentaBancaria: $conPlata);
        $cuota = $this->cuotaPor('234/2024', '85000.00', ExpectedMedium::Bank);

        try {
            $this->apartar($cuota, PaymentMedium::Bank, bankAccountId: $vacia);
            $this->fail('Se apartó de una cuenta sin saldo.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('hay 0,00', $e->errors()['bankAccountId'][0]);
        }

        $this->apartar($cuota, PaymentMedium::Bank, bankAccountId: $conPlata);
        $this->assertTrue(app(InstallmentFunding::class)->isFullyFunded($cuota));
    }

    public function test_la_base_rechaza_una_cuenta_de_otra_moneda_para_el_deposito_directo(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuotaPor('235/2024', '85000.00', ExpectedMedium::Bank);
        $dolares = (int) DB::table('bank_accounts')->insertGetId([
            'label' => 'Cuenta en dólares', 'bank_name' => 'Banco Macro', 'account_number' => '320000123456789',
            'currency' => 'USD', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $evento = $this->eventoApartado($cuota, '1.00');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('no es de la moneda de la recepción');

        FundReceipt::query()->create([
            'financial_event_id' => $evento,
            'cash_box_id' => $this->caja(),
            'medium' => PaymentMedium::Bank,
            'origin' => FundReceiptOrigin::Legacy,
            'bank_account_id' => $dolares,
            'amount' => '1.00',
            'received_date' => BusinessDate::today(),
        ]);
    }

    /** Un papel mal cargado se anula con motivo y se carga el correcto. */
    public function test_un_recibo_de_papel_mal_cargado_se_anula_y_se_reemplaza(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuota('236/2024', '85000.00');
        $this->apartar($cuota, PaymentMedium::Cash);

        try {
            app(VoidLegacyIncomeDocument::class)->handle($cuota, 'El número del talonario estaba mal tipeado.');
            $this->fail('Se anuló un papel que respalda plata apartada.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('primero hay que quitar la reserva', $e->errors()['reason'][0]);
        }

        app(UnallocateFunds::class)->handle(FundingAllocation::query()->sole(), '85000.00', 'test-libera-236', 'El papel estaba mal cargado.');

        $this->actingAs($this->operador('contador'))
            ->post("/haberes/cuotas/{$cuota->id}/recibo-de-papel/anular", ['reason' => 'El número del talonario estaba mal tipeado.'])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, LegacyDocument::query()->current()->count());

        app(FundInstallmentFromLegacy::class)->handle(
            installment: $cuota->refresh(),
            medium: PaymentMedium::Cash,
            sources: [['amount' => '85000.00']],
            income: new LegacyPaper(LegacyDocumentKind::IncomeReceipt, 'income', '3127', CarbonImmutable::parse('2025-03-10'), '85000.00'),
            idempotencyKey: 'test-reapartar-236',
        );

        $this->assertSame('3127', LegacyDocument::query()->current()->sole()->number);
    }

    /**
     * La apertura declaró los cheques como un total: el cheque se identifica
     * al apartar, con su papel, y queda en la cartera para entregarse.
     */
    public function test_identifica_un_cheque_que_la_apertura_declaro_sin_detalle(): void
    {
        $this->abrirLibros(chequesSinDetalle: '85000.00');
        $cuota = $this->cuotaPor('223/2024', '85000.00', ExpectedMedium::Cheque);

        $this->apartar($cuota, PaymentMedium::Cheque, sources: [
            ['amount' => '85000.00', 'newCheque' => ['number' => '00045871', 'bank' => 'Macro', 'issueDate' => '2025-03-01']],
        ]);

        $cheque = FundReceipt::query()->where('origin', FundReceiptOrigin::Legacy->value)->sole();

        $this->assertSame(PaymentMedium::Cheque, $cheque->medium);
        $this->assertSame('00045871', $cheque->cheque_number);
        $this->assertSame(ChequeStatus::InCustody, $cheque->cheque_status);
        $this->assertSame('0.00', app(UndetailedCheques::class)->amount($this->caja(), Currency::Ars));
        $this->assertSame('85000.00', app(CashBalance::class)->of(LedgerAccount::ChequesInCustody, $this->caja()));

        app(DeliverToBeneficiary::class)->handle($cuota->refresh(), 'test-entrega-223', $this->operador('contador')->id);

        $this->assertSame(ChequeStatus::Delivered, $cheque->refresh()->cheque_status);
        $this->assertSame('0.00', app(CashBalance::class)->of(LedgerAccount::ChequesInCustody, $this->caja()));
    }

    /**
     * El importe del cheque nuevo no lo decide la pantalla: es lo que le
     * falta a la cuota después de los cheques de la lista.
     */
    public function test_el_cheque_nuevo_cubre_lo_que_falta_y_no_toma_el_importe_de_la_pantalla(): void
    {
        $this->abrirLibros(chequesSinDetalle: '85000.00');
        $cuota = $this->cuotaPor('227/2024', '85000.00', ExpectedMedium::Cheque);

        $this->actingAs($this->operador('contador'))
            ->post("/haberes/cuotas/{$cuota->id}/fondos-anteriores", [
                'medium' => 'cheque',
                'cheques' => [['number' => '00045871', 'bank' => '', 'issueDate' => '2025-03-01', 'amount' => '1.00']],
                'incomeNumber' => '3121',
                'incomeDate' => '2025-03-10',
                'idempotencyKey' => 'test-importe-fijo',
            ])
            ->assertSessionHasNoErrors();

        $cheque = FundReceipt::query()->where('origin', FundReceiptOrigin::Legacy->value)->sole();

        $this->assertSame('85000.00', $cheque->amount);
        // Sin banco es válido: el campo es opcional.
        $this->assertNull($cheque->cheque_bank);
        $this->assertTrue(app(InstallmentFunding::class)->isFullyFunded($cuota));
    }

    public function test_no_se_identifica_un_cheque_por_mas_de_lo_que_la_apertura_dejo_sin_detallar(): void
    {
        $this->abrirLibros(chequesSinDetalle: '50000.00');
        $cuota = $this->cuotaPor('224/2024', '85000.00', ExpectedMedium::Cheque);

        try {
            $this->apartar($cuota, PaymentMedium::Cheque, sources: [
                ['amount' => '85000.00', 'newCheque' => ['number' => '1', 'bank' => null, 'issueDate' => null]],
            ]);
            $this->fail('Se identificó un cheque que la caja no tiene.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('sin identificar', $e->errors()['source'][0]);
        }
    }

    public function test_no_se_identifica_dos_veces_el_mismo_cheque(): void
    {
        $this->abrirLibros(chequesSinDetalle: '170000.00');
        $primera = $this->cuotaPor('225/2024', '85000.00', ExpectedMedium::Cheque);
        $segunda = $this->cuotaPor('226/2024', '85000.00', ExpectedMedium::Cheque);
        $papel = ['number' => '00045871', 'bank' => 'Macro', 'issueDate' => '2025-03-01'];

        $this->apartar($primera, PaymentMedium::Cheque, sources: [['amount' => '85000.00', 'newCheque' => $papel]]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('ya está en la cartera');

        app(FundInstallmentFromLegacy::class)->handle(
            installment: $segunda,
            medium: PaymentMedium::Cheque,
            sources: [['amount' => '85000.00', 'newCheque' => [...$papel, 'number' => ' 00045871 ']]],
            income: new LegacyPaper(LegacyDocumentKind::IncomeReceipt, 'income', '3122', CarbonImmutable::parse('2025-03-10'), '85000.00'),
            idempotencyKey: 'test-segunda-226',
        );
    }

    public function test_no_se_aparta_mas_de_lo_que_queda_libre_de_un_cheque(): void
    {
        $this->abrirLibros(cheques: [['A-3', '50000.00']]);
        $cuota = $this->cuotaPor('204/2024', '85000.00', ExpectedMedium::Cheque);
        $cheque = FundReceipt::query()->where('origin', 'opening')->sole();

        $this->expectException(ValidationException::class);

        $this->apartar($cuota, PaymentMedium::Cheque, sources: [
            ['amount' => '85000.00', 'chequeReceiptId' => $cheque->id],
        ]);
    }

    public function test_se_aparta_la_cuota_entera_y_con_su_recibo_de_papel(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuota('205/2024', '85000.00');

        try {
            $this->apartar($cuota, PaymentMedium::Cash, sources: [['amount' => '40000.00']]);
            $this->fail('Se apartó una cuota a medias.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('sources', $e->errors());
        }

        try {
            app(FundInstallmentFromLegacy::class)->handle(
                installment: $cuota,
                medium: PaymentMedium::Cash,
                sources: [['amount' => '85000.00']],
                income: null,
                idempotencyKey: 'test-sin-papel',
            );
            $this->fail('Se apartó sin recibo de ingreso de papel.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('incomeNumber', $e->errors());
        }
    }

    /* ── Sin mezcla ──────────────────────────────────────────────────── */

    public function test_una_cuota_cobrada_por_el_circuito_no_recibe_plata_anterior(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuota('206/2024', '85000.00');
        $this->cobrar($cuota, 72300, BusinessDate::today()->toDateString());

        $this->expectException(ValidationException::class);

        $this->apartar($cuota->refresh(), PaymentMedium::Cash);
    }

    public function test_una_cuota_con_recibo_de_papel_no_recibe_plata_del_circuito(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuota('207/2024', '85000.00');
        $this->apartar($cuota, PaymentMedium::Cash);
        $asignacion = FundingAllocation::query()->sole();

        app(UnallocateFunds::class)->handle($asignacion, '85000.00', 'test-liberar-207', 'Se apartó para la cuota equivocada.');

        // Liberada, la cuota conserva su recibo de papel: solo admite plata anterior.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('recibo de ingreso de papel');

        $this->cobrar($cuota->refresh(), 72301, BusinessDate::today()->toDateString());
    }

    public function test_la_base_no_mezcla_dinero_anterior_con_actual(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuota('208/2024', '85000.00');
        $this->cobrar($cuota, 72302, BusinessDate::today()->toDateString());
        $evento = $this->eventoApartado($cuota, '1.00');
        $recepcion = $this->recepcionLegacy($evento, '1.00');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('no admite dinero del sistema anterior');

        $this->insertarAsignacion($cuota, $recepcion, $evento, '1.00');
    }

    /* ── Liberar ─────────────────────────────────────────────────────── */

    public function test_liberar_en_parte_devuelve_al_saldo_anterior_lo_liberado(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuota('209/2024', '100000.00');
        $this->apartar($cuota, PaymentMedium::Cash);

        // La cuota baja a 80.000: los 20.000 de más se devuelven.
        DB::table('beneficiary_installments')->where('id', $cuota->id)->update(['expected_amount' => '80000.00']);
        app(UnallocateFunds::class)->handle(FundingAllocation::query()->sole(), '20000.00', 'test-parcial-209', 'La cuota bajó a 80.000.');

        $saldos = app(CashBalance::class);

        $this->assertSame($this->restoDelSistemaAnterior('80000.00'), $saldos->of(LedgerAccount::LegacyFunds, $this->caja()));
        $this->assertSame('80000.00', app(InstallmentFunding::class)->allocated($cuota->refresh()));
        $this->assertSame('0.00', $saldos->of(LedgerAccount::UnassignedFunds, $this->caja()));
    }

    /** Liberado todo, se vuelve a apartar con el mismo papel. */
    public function test_liberar_todo_y_volver_a_apartar_reutiliza_el_papel(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuota('210/2024', '85000.00');
        $this->apartar($cuota, PaymentMedium::Cash);

        app(UnallocateFunds::class)->handle(FundingAllocation::query()->sole(), '85000.00', 'test-total-210', 'Se apartó de más por error.');

        $this->assertSame($this->restoDelSistemaAnterior('0.00'), app(CashBalance::class)->of(LedgerAccount::LegacyFunds, $this->caja()));

        app(FundInstallmentFromLegacy::class)->handle(
            installment: $cuota->refresh(),
            medium: PaymentMedium::Cash,
            sources: [['amount' => '85000.00']],
            income: null,
            idempotencyKey: 'test-otra-vez-210',
        );

        $this->assertTrue(app(InstallmentFunding::class)->isFullyFunded($cuota->refresh()));
        $this->assertSame(1, LegacyDocument::query()->count());
    }

    public function test_la_base_no_deja_reutilizar_una_recepcion_apartada(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuota('211/2024', '85000.00');
        $this->apartar($cuota, PaymentMedium::Cash);
        app(UnallocateFunds::class)->handle(FundingAllocation::query()->sole(), '85000.00', 'test-reuso-211', 'Se liberó para probar.');
        $recepcion = FundReceipt::query()->where('origin', 'legacy')->sole();
        $otro = $this->eventoApartado($cuota, '85000.00');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('no se vuelve a asignar');

        $this->insertarAsignacion($cuota, $recepcion, $otro, '85000.00');
    }

    /** El agujero que cierra la etapa: un cheque de la apertura por el camino normal. */
    public function test_un_cheque_de_la_apertura_no_se_asigna_por_el_camino_normal(): void
    {
        $this->abrirLibros(cheques: [['A-4', '85000.00']]);
        $cuota = $this->cuotaPor('212/2024', '85000.00', ExpectedMedium::Cheque);
        $cheque = FundReceipt::query()->where('origin', 'opening')->sole();

        try {
            app(AllocateFundsToInstallment::class)->handle($cheque, $cuota, '85000.00', 'test-normal-212');
            $this->fail('Un cheque de la apertura se asignó por el camino normal.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('receiptId', $e->errors());
        }

        $evento = $this->eventoAsignacionNormal($cuota, '85000.00');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('se asigna apartándolo');

        $this->insertarAsignacion($cuota, $cheque, $evento, '85000.00');
    }

    /* ── El circuito, con el recibo de papel ─────────────────────────── */

    public function test_la_cuota_apartada_se_entrega_por_mostrador_con_su_recibo_de_papel(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuota('213/2024', '85000.00');
        $this->apartar($cuota, PaymentMedium::Cash);

        $estado = app(DisbursementEligibility::class)->for($cuota->refresh());
        $this->assertTrue($estado->canPay(), (string) $estado->blockedReason);

        app(DeliverToBeneficiary::class)->handle($cuota, 'test-entrega-213', $this->operador('contador')->id);

        $this->assertSame(InstallmentWorkflowStatus::Paid, $cuota->refresh()->workflow_status);
        $this->assertSame('915000.00', app(CashBalance::class)->of(LedgerAccount::CashOnHand, $this->caja()));
        $this->assertSame(
            InstallmentStage::Paid,
            app(InstallmentStages::class)->forMany(collect([$cuota]))[$cuota->id],
        );
    }

    /** El traslado al banco pide recibo de ingreso: vale el de papel. */
    public function test_la_cuota_apartada_se_puede_trasladar_al_banco_con_su_recibo_de_papel(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuota('220/2024', '85000.00');
        $this->apartar($cuota, PaymentMedium::Cash);

        $this->actingAs($this->operador('contador'))
            ->get("/haberes/cuotas/{$cuota->id}/traslado")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('cuota.receiptNumber', '3121'));
    }

    /**
     * Dos apartados del mismo cheque se ordenan sobre el cheque.
     *
     * La carrera entre dos conexiones no se puede reproducir acá: el cheque
     * nace en la transacción del test y otra conexión no lo ve. Lo
     * verificable es que el Action lo bloquee antes de leer su saldo libre,
     * igual que el trigger `allocation_within_receipt`.
     */
    public function test_apartar_bloquea_el_cheque_antes_de_leer_su_saldo_libre(): void
    {
        $this->abrirLibros(cheques: [['A-5', '85000.00']]);
        $cuota = $this->cuotaPor('221/2024', '85000.00', ExpectedMedium::Cheque);
        $cheque = FundReceipt::query()->where('origin', 'opening')->sole();
        $bloqueos = 0;

        DB::listen(function ($query) use (&$bloqueos): void {
            $sql = strtolower($query->sql);

            if (str_contains($sql, 'for update') && str_contains($sql, '"fund_receipts"')) {
                $bloqueos++;
            }
        });

        $this->apartar($cuota, PaymentMedium::Cheque, sources: [
            ['amount' => '85000.00', 'chequeReceiptId' => $cheque->id],
        ]);

        $this->assertGreaterThan(0, $bloqueos);
    }

    public function test_una_cuota_con_recibo_de_papel_no_lleva_recibo_del_sistema(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuota('214/2024', '85000.00');
        $this->apartar($cuota, PaymentMedium::Cash);

        try {
            app(IssueIncomeReceipt::class)->handle($cuota->refresh());
            $this->fail('Se emitió un recibo del sistema sobre uno de papel.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('recibo de ingreso de papel', $e->errors()['installmentId'][0]);
        }
    }

    public function test_el_papel_no_se_anula_mientras_respalda_plata_apartada(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuota('215/2024', '85000.00');
        $this->apartar($cuota, PaymentMedium::Cash);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('respalda dinero apartado');

        DB::table('legacy_documents')->update([
            'voided_at' => now(),
            'voided_by' => $this->operador('contador')->id,
            'void_reason' => 'Prueba',
        ]);
    }

    /* ── Saldo y forma del asiento ───────────────────────────────────── */

    public function test_no_se_aparta_mas_de_lo_que_queda_del_sistema_anterior(): void
    {
        $this->abrirLibros(efectivo: '50000.00');
        $cuota = $this->cuota('216/2024', '85000.00');

        $this->expectException(ValidationException::class);

        $this->apartar($cuota, PaymentMedium::Cash);
    }

    public function test_la_base_no_deja_que_el_apartado_mueva_dinero_de_lugar(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuota('217/2024', '85000.00');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('no mueve el dinero de lugar');

        $evento = $this->eventoCrudo('legacy_funds_allocated');
        DB::table('journal_lines')->insert([
            $this->linea($evento, LedgerAccount::LegacyFunds, debit: '85000.00'),
            $this->linea($evento, LedgerAccount::CashOnHand, credit: '85000.00'),
        ]);
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    }

    public function test_el_apartado_no_se_hace_en_un_periodo_cerrado_porque_va_con_fecha_de_hoy(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuota('218/2024', '85000.00');

        $asignaciones = $this->apartar($cuota, PaymentMedium::Cash);

        $this->assertSame(
            BusinessDate::today()->toDateString(),
            $asignaciones[0]->allocationEvent->event_date->toDateString(),
        );
    }

    /** El saldo del sistema anterior muestra lo reservado y lo liberado, y cierra. */
    public function test_pagos_anteriores_lista_lo_apartado_con_lo_liberado(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuota('222/2024', '100000.00');
        $this->apartar($cuota, PaymentMedium::Cash);
        DB::table('beneficiary_installments')->where('id', $cuota->id)->update(['expected_amount' => '80000.00']);
        app(UnallocateFunds::class)->handle(FundingAllocation::query()->sole(), '20000.00', 'test-lista-222', 'La cuota bajó a 80.000.');

        $this->actingAs($this->operador('contador'))
            ->get('/caja/saldo-anterior')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('balances.pending', $this->restoDelSistemaAnterior('80000.00'))
                ->where('setAside.0.amount', '100000.00')
                ->where('setAside.0.released', '20000.00')
                ->where('setAside.0.description', fn (string $texto): bool => str_contains($texto, '222/2024'))
                // La reserva nombra su cuota, que se abre en el panel lateral.
                ->where('setAside.0.installmentId', $cuota->id)
                ->where('canViewInstallments', true));
    }

    /* ── La cartera de cheques y el panel de la cuota ────────────────── */

    /**
     * Reservar un cheque no mueve el saldo: el papel sigue en la caja. La
     * cartera lo abre y dice para quién es cada uno.
     */
    public function test_la_cartera_de_cheques_dice_para_quien_es_cada_uno(): void
    {
        $this->abrirLibros(cheques: [['A-1', '85000.00'], ['A-2', '40000.00']]);
        $cuota = $this->cuotaPor('230/2024', '85000.00', ExpectedMedium::Cheque);
        $primero = FundReceipt::query()->where('cheque_number', 'A-1')->sole();
        $this->apartar($cuota, PaymentMedium::Cheque, sources: [
            ['amount' => '85000.00', 'chequeReceiptId' => $primero->id],
        ]);

        // Un cheque cobrado por el circuito que todavía no tiene dueño.
        app(RegisterCashFundReceipt::class)->handle(
            amount: '10000.00',
            idempotencyKey: 'test-cheque-sin-dueno',
            cashBoxId: $this->caja(),
            receivedDate: BusinessDate::today(),
            medium: PaymentMedium::Cheque,
            cheque: ['number' => 'C-9', 'bank' => 'Nación', 'issueDate' => null],
        );

        $this->actingAs($this->operador('administrativo'))
            ->getJson('/haberes/cheques-en-custodia?currency=ARS')
            ->assertOk()
            ->assertJsonCount(3, 'cheques')
            ->assertJsonPath('cheques.0.number', 'A-1')
            ->assertJsonPath('cheques.0.origin', 'opening')
            ->assertJsonPath('cheques.0.assignments.0.installmentId', $cuota->id)
            ->assertJsonPath('cheques.0.assignments.0.expedienteNumber', '230/2024')
            ->assertJsonPath('cheques.0.assignments.0.reserved', true)
            ->assertJsonPath('cheques.0.unassigned', '0.00')
            ->assertJsonPath('cheques.1.assignments', [])
            ->assertJsonPath('cheques.1.unassigned', '40000.00')
            ->assertJsonPath('cheques.2.origin', 'received')
            ->assertJsonPath('cheques.2.unassigned', '10000.00')
            ->assertJsonPath('detailed', '135000.00')
            ->assertJsonPath('undetailed', '0.00')
            ->assertJsonPath('total', '135000.00');
    }

    /** Lo que la apertura declaró como total también es parte de la cartera. */
    public function test_la_cartera_suma_lo_que_la_apertura_dejo_sin_detallar(): void
    {
        $this->abrirLibros(chequesSinDetalle: '200000.00');

        $this->actingAs($this->operador('contador'))
            ->getJson('/haberes/cheques-en-custodia?currency=ARS')
            ->assertOk()
            ->assertJsonPath('cheques', [])
            ->assertJsonPath('detailed', '0.00')
            ->assertJsonPath('undetailed', '200000.00')
            ->assertJsonPath('total', '200000.00');
    }

    /** El panel lateral de la cuota: de quién es, cuánto y en qué etapa. */
    public function test_el_panel_de_la_cuota_dice_de_quien_es_y_donde_esta(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuota('231/2024', '85000.00');
        $this->apartar($cuota, PaymentMedium::Cash);

        $this->actingAs($this->operador('consulta'))
            ->getJson("/haberes/cuotas/{$cuota->id}/panel")
            ->assertOk()
            ->assertJsonPath('subject.installmentId', $cuota->id)
            ->assertJsonPath('subject.expedienteNumber', '231/2024')
            ->assertJsonPath('installmentsTotal', 1)
            ->assertJsonPath('expectedAmount', '85000.00')
            ->assertJsonPath('fundedAmount', '85000.00')
            ->assertJsonPath('fundedFromLegacy', true)
            ->assertJsonPath('stage', 'in_cash_box');
    }

    public function test_quien_no_paga_haberes_anteriores_no_aparta(): void
    {
        $this->abrirLibros();
        $cuota = $this->cuota('219/2024', '85000.00');

        $this->actingAs($this->operador('administrativo'))
            ->post("/haberes/cuotas/{$cuota->id}/fondos-anteriores", [
                'medium' => 'cash',
                'incomeNumber' => '3121',
                'incomeDate' => '2025-03-10',
                'idempotencyKey' => 'test-permiso',
            ])
            ->assertForbidden();

        $this->actingAs($this->operador('contador'))
            ->post("/haberes/cuotas/{$cuota->id}/fondos-anteriores", [
                'medium' => 'cash',
                'incomeNumber' => '3121',
                'incomeDate' => '2025-03-10',
                'idempotencyKey' => 'test-permiso',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(app(InstallmentFunding::class)->isFullyFunded($cuota));
    }

    /* ── Ayudas ──────────────────────────────────────────────────────── */

    /**
     * @param  list<array{amount: numeric-string, chequeReceiptId?: int|null, newCheque?: array{number: string, bank: string|null, issueDate: string|null}|null}>|null  $sources
     * @return list<FundingAllocation>
     */
    private function apartar(
        BeneficiaryInstallment $cuota,
        PaymentMedium $medio,
        ?array $sources = null,
        ?int $bankAccountId = null,
        string $talonario = '3121',
    ): array {
        return app(FundInstallmentFromLegacy::class)->handle(
            installment: $cuota,
            medium: $medio,
            sources: $sources ?? [['amount' => $cuota->importeEsperado()]],
            income: new LegacyPaper(
                LegacyDocumentKind::IncomeReceipt,
                'income',
                $talonario,
                CarbonImmutable::parse('2025-03-10'),
                $cuota->importeEsperado(),
            ),
            idempotencyKey: 'test-apartar-'.$cuota->id,
            bankAccountId: $bankAccountId,
        );
    }

    /** Lo que queda en `LEGACY_FUNDS` tras apartar ese importe del total abierto. */
    private function restoDelSistemaAnterior(string $apartado): string
    {
        return bcsub('1000000.00', $apartado, 2);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $cheques  Número e importe.
     */
    private function abrirLibros(
        string $efectivo = '1000000.00',
        array $cheques = [],
        ?string $banco = null,
        ?int $cuentaBancaria = null,
        ?string $chequesSinDetalle = null,
    ): void {
        $saldos = [LedgerAccount::CashOnHand->value => $efectivo];

        // La apertura puede declarar los cheques como un total, sin detallarlos.
        if ($chequesSinDetalle !== null) {
            $saldos[LedgerAccount::ChequesInCustody->value] = $chequesSinDetalle;
        }
        $cartera = [];
        $totalCheques = '0.00';

        foreach ($cheques as [$numero, $importe]) {
            $cartera[] = ['number' => $numero, 'bank' => 'Macro', 'issueDate' => '2025-03-01', 'amount' => $importe];
            $totalCheques = bcadd($totalCheques, $importe, 2);
        }

        if ($cartera !== []) {
            $saldos[LedgerAccount::ChequesInCustody->value] = $totalCheques;
        }

        if ($banco !== null) {
            $saldos[LedgerAccount::BankAccount->value] = $banco;
        }

        app(RegisterOpeningBalance::class)->handle(
            cashBoxId: $this->caja(),
            balances: $saldos,
            date: CarbonImmutable::parse('2026-06-01'),
            bankAccountId: $cuentaBancaria,
            cheques: $cartera,
            denominations: $this->billetesPara($efectivo),
        );
    }

    /** Una cuota del helper común, con el medio previsto que haga falta. */
    private function cuotaPor(string $expediente, string $importe, ExpectedMedium $medio): BeneficiaryInstallment
    {
        $cuota = $this->cuota($expediente, $importe);
        $cuota->forceFill(['expected_medium' => $medio])->save();

        return $cuota;
    }

    private function eventoCrudo(string $tipo): int
    {
        return (int) DB::table('financial_events')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'cash_box_id' => $this->caja(),
            'event_type' => $tipo,
            'event_date' => BusinessDate::today()->toDateString(),
            'status' => 'posted',
            'posted_at' => now(),
            'idempotency_key' => 'test-crudo-'.Str::random(8),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Un apartado bien formado, sin recepción ni asignación todavía. */
    private function eventoApartado(BeneficiaryInstallment $cuota, string $importe): int
    {
        $evento = $this->eventoCrudo('legacy_funds_allocated');

        DB::table('journal_lines')->insert($this->linea($evento, LedgerAccount::LegacyFunds, debit: $importe));
        DB::table('journal_lines')->insert([
            ...$this->linea($evento, LedgerAccount::BeneficiaryFunds, credit: $importe),
            'haber_id' => $cuota->haber_id,
            'beneficiary_installment_id' => $cuota->id,
        ]);

        return $evento;
    }

    private function eventoAsignacionNormal(BeneficiaryInstallment $cuota, string $importe): int
    {
        $evento = $this->eventoCrudo('funds_allocated');

        DB::table('journal_lines')->insert($this->linea($evento, LedgerAccount::UnassignedFunds, debit: $importe));
        DB::table('journal_lines')->insert([
            ...$this->linea($evento, LedgerAccount::BeneficiaryFunds, credit: $importe),
            'haber_id' => $cuota->haber_id,
            'beneficiary_installment_id' => $cuota->id,
        ]);

        return $evento;
    }

    private function recepcionLegacy(int $evento, string $importe): FundReceipt
    {
        return FundReceipt::query()->create([
            'financial_event_id' => $evento,
            'cash_box_id' => $this->caja(),
            'medium' => PaymentMedium::Cash,
            'origin' => FundReceiptOrigin::Legacy,
            'amount' => $importe,
            'received_date' => BusinessDate::today(),
        ]);
    }

    private function insertarAsignacion(BeneficiaryInstallment $cuota, FundReceipt $recepcion, int $evento, string $importe): void
    {
        DB::table('funding_allocations')->insert([
            'allocation_event_id' => $evento,
            'fund_receipt_id' => $recepcion->id,
            'haber_id' => $cuota->haber_id,
            'beneficiary_installment_id' => $cuota->id,
            'allocation_kind' => 'allocation',
            'amount' => $importe,
            'allocated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function linea(int $evento, LedgerAccount $cuenta, string $debit = '0', string $credit = '0'): array
    {
        return [
            'financial_event_id' => $evento,
            'account_code' => $cuenta->value,
            'debit' => $debit,
            'credit' => $credit,
            'cash_box_id' => $this->caja(),
            'currency' => 'ARS',
        ];
    }

    private function caja(): int
    {
        return (int) CashBox::query()->where('code', CashBox::HABERES)->value('id');
    }

    private function cuentaBancaria(string $numero = '310000123456789'): int
    {
        return (int) DB::table('bank_accounts')->insertGetId([
            'label' => 'Cta. Cte. '.$numero,
            'bank_name' => 'Banco Macro',
            'account_number' => $numero,
            'currency' => 'ARS',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
