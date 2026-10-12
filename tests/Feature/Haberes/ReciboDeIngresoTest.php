<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Models\User;
use App\Modules\Haberes\Actions\AllocateFundsToInstallment;
use App\Modules\Haberes\Actions\IssueIncomeReceipt;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Ledger\Actions\RegisterCashFundReceipt;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Ledger\Models\ReceiptFinancialEvent;
use App\Modules\Shared\Actions\ConfigureDocumentNumbering;
use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Enums\ReceiptIssueMode;
use App\Modules\Shared\Enums\ReceiptStatus;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Models\DocumentSeries;
use App\Modules\Shared\Models\Receipt;
use App\Support\Money\Decimal;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * El comprobante que el área entrega.
 *
 * Dos reglas lo gobiernan y las dos vienen del §2 del DER: se emite **una
 * sola vez**, cuando la cuota queda completa, y **al recibir**, sin
 * esperar la acreditación de nada. Lo segundo es lo que ordena el circuito
 * del efectivo: el empleador deja la plata y se lleva su recibo en el
 * momento; que después el beneficiario no aparezca es otro hecho.
 */
class ReciboDeIngresoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->abrirLibrosSinSaldo();

        $this->seed(HaberesDemoSeeder::class);
    }

    /** El circuito del efectivo, entero: entra por mostrador y sale el recibo. */
    public function test_el_efectivo_entra_por_mostrador_y_se_emite_el_recibo(): void
    {
        $cuota = $this->cuota();
        $importe = $cuota->importeEsperado();

        $recepcion = $this->recepcionEnEfectivo($importe);
        $this->asignar($recepcion, $cuota, $importe);

        $recibo = app(IssueIncomeReceipt::class)->handle($cuota);

        $this->assertSame(ReceiptStatus::Issued, $recibo->status);
        $this->assertSame($importe, $recibo->amount);
        $this->assertSame('cash', $recibo->medium_snapshot);
        $this->assertSame($cuota->id, $recibo->beneficiary_installment_id);
        // El número lo pone el sistema, siempre.
        $this->assertNotNull($recibo->formatted_number);
        $this->assertNull($recibo->talonario_number);
        $this->assertSame(ReceiptIssueMode::Online, $recibo->issue_mode);
    }

    /**
     * El asiento del efectivo mueve `CASH_ON_HAND`, no la cuenta bancaria.
     *
     * Es lo que distingue este circuito del bancario: el dinero está en el
     * cajón, no en el banco.
     */
    public function test_la_recepcion_en_efectivo_asienta_contra_la_caja(): void
    {
        $recepcion = $this->recepcionEnEfectivo('50000.00');

        $cuentas = DB::table('journal_lines')
            ->where('financial_event_id', $recepcion->financial_event_id)
            ->pluck('account_code')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['CASH_ON_HAND', 'UNASSIGNED_FUNDS'], $cuentas);
        $this->assertSame(PaymentMedium::Cash, $recepcion->medium);
    }

    /** §2.1.14: un ingreso parcial no genera comprobante de ningún tipo. */
    public function test_una_cuota_incompleta_no_genera_comprobante(): void
    {
        $cuota = $this->cuota();
        $mitad = Decimal::scale(bcdiv($cuota->importeEsperado(), '2', 4));

        $this->asignar($this->recepcionEnEfectivo($mitad), $cuota, $mitad);

        try {
            app(IssueIncomeReceipt::class)->handle($cuota);
            $this->fail('Se emitió un recibo con la cuota a medio financiar.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('todavía no está completa', $e->getMessage());
        }

        $this->assertSame(0, Receipt::query()->count());
    }

    /** §2.1.14: una sola vez. */
    public function test_no_se_emiten_dos_recibos_para_la_misma_cuota(): void
    {
        $cuota = $this->cuotaFinanciada();

        $primero = app(IssueIncomeReceipt::class)->handle($cuota);

        try {
            app(IssueIncomeReceipt::class)->handle($cuota);
            $this->fail('Se emitió un segundo recibo para la misma cuota.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($primero->formatted_number, $e->getMessage());
        }

        $this->assertSame(1, Receipt::query()->count());
    }

    /** Y la base lo impide aunque nadie pase por el Action. */
    public function test_la_base_rechaza_el_segundo_recibo_vigente(): void
    {
        $cuota = $this->cuotaFinanciada();
        $recibo = app(IssueIncomeReceipt::class)->handle($cuota);

        $this->expectException(QueryException::class);

        DB::table('receipts')->insert([
            ...$this->filaCruda($recibo),
            'number' => $recibo->number + 1,
            'formatted_number' => '0010/00000999',
        ]);
    }

    /**
     * El número del talonario convive con el del sistema.
     *
     * El identificador sigue siendo el del sistema; el del papel se
     * registra al lado, y es lo que permite encontrar la hoja física.
     */
    public function test_el_numero_del_talonario_se_registra_junto_al_del_sistema(): void
    {
        $cuota = $this->cuotaFinanciada();

        $recibo = app(IssueIncomeReceipt::class)->handle($cuota, talonarioNumber: '00071514');

        $this->assertSame('00071514', $recibo->talonario_number);
        $this->assertNotNull($recibo->formatted_number);
        // Cargado después de escribirse a mano: eso es lo que dice el modo.
        $this->assertSame(ReceiptIssueMode::OfflineTalonario, $recibo->issue_mode);
        $this->assertNotNull($recibo->recorded_at);
    }

    /**
     * Un número de talonario no se repite, ni siquiera si el recibo se anuló.
     *
     * La hoja 00071514 existió en papel: si se arruinó, ese número se
     * consumió y no vuelve.
     */
    public function test_el_numero_del_talonario_no_se_repite(): void
    {
        $primera = $this->cuotaFinanciada();
        $recibo = app(IssueIncomeReceipt::class)->handle($primera, talonarioNumber: '00071514');

        // Se anula, pero el número sigue tomado.
        DB::table('receipts')->where('id', $recibo->id)->update([
            'status' => 'voided',
            'voided_by' => $this->operador()->id,
            'voided_at' => now(),
            'void_reason' => 'La hoja se arruinó al escribirla.',
        ]);

        $otra = $this->otraCuotaFinanciada();

        $this->expectException(QueryException::class);

        app(IssueIncomeReceipt::class)->handle($otra, talonarioNumber: '00071514');
    }

    /** Un recibo documenta todas las asignaciones que completaron la cuota. */
    public function test_el_recibo_referencia_los_hechos_que_lo_respaldan(): void
    {
        $cuota = $this->cuota();
        $total = $cuota->importeEsperado();
        $mitad = Decimal::scale(bcdiv($total, '2', 4));
        $resto = Decimal::sub($total, $mitad);

        // Dos ingresos para la misma cuota (§2.1.9).
        $this->asignar($this->recepcionEnEfectivo($mitad), $cuota, $mitad);
        $this->asignar($this->recepcionEnEfectivo($resto), $cuota, $resto);

        $recibo = app(IssueIncomeReceipt::class)->handle($cuota->refresh());

        $this->assertSame($total, $recibo->amount);
        $this->assertSame(
            2,
            ReceiptFinancialEvent::query()->where('receipt_id', $recibo->id)->count(),
            'El comprobante tiene que documentar las dos asignaciones.',
        );
    }

    /** Lo que dice el papel queda congelado. */
    public function test_el_recibo_congela_lo_que_sale_impreso(): void
    {
        $cuota = $this->cuotaFinanciada();
        $haber = $cuota->haber;

        $recibo = app(IssueIncomeReceipt::class)->handle($cuota);

        $nombreOriginal = $haber->beneficiary->name;
        $this->assertSame($nombreOriginal, $recibo->beneficiary_name_snapshot);

        // Corregir el maestro no puede cambiar el papel ya entregado.
        DB::table('people')->where('id', $haber->beneficiary_id)
            ->update(['first_name' => 'Otro', 'last_name' => 'Nombre']);

        $this->assertSame($nombreOriginal, $recibo->refresh()->beneficiary_name_snapshot);
    }

    public function test_un_comprobante_no_se_edita_ni_se_borra(): void
    {
        $cuota = $this->cuotaFinanciada();
        $recibo = app(IssueIncomeReceipt::class)->handle($cuota);

        try {
            DB::table('receipts')->where('id', $recibo->id)->update(['amount' => '1.00']);
            $this->fail('El importe de un comprobante se pudo editar.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('no se editan', $e->getMessage());
        }

        $this->expectException(QueryException::class);

        DB::table('receipts')->where('id', $recibo->id)->delete();
    }

    /** Una transferencia no entra por el mostrador: nace del extracto. */
    public function test_no_se_registra_una_transferencia_como_efectivo(): void
    {
        $this->expectException(ValidationException::class);

        app(RegisterCashFundReceipt::class)->handle(
            amount: '1000.00',
            idempotencyKey: 'no-deberia-entrar',
            cashBoxId: $this->cajaHaberes(),
            receivedDate: now(),
            medium: PaymentMedium::Bank,
        );
    }

    /** El cheque sigue el circuito del efectivo y queda en custodia (§2.5). */
    public function test_el_cheque_entra_en_custodia_y_no_al_banco(): void
    {
        $recepcion = app(RegisterCashFundReceipt::class)->handle(
            amount: '90000.00',
            idempotencyKey: 'cheque-'.Str::random(8),
            cashBoxId: $this->cajaHaberes(),
            receivedDate: now(),
            medium: PaymentMedium::Cheque,
            cheque: ['number' => '12345678', 'bank' => 'Banco Nación', 'issueDate' => '2026-04-20'],
        );

        $this->assertSame(PaymentMedium::Cheque, $recepcion->medium);
        $this->assertSame('in_custody', $recepcion->cheque_status?->value);

        $cuentas = DB::table('journal_lines')
            ->where('financial_event_id', $recepcion->financial_event_id)
            ->pluck('account_code')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['CHEQUES_IN_CUSTODY', 'UNASSIGNED_FUNDS'], $cuentas);
    }

    public function test_cambiar_el_formato_conserva_el_recibo_y_emite_con_la_nueva_version(): void
    {
        $old = app(IssueIncomeReceipt::class)->handle($this->cuotaFinanciada());
        $actor = User::factory()->create();
        $series = DocumentSeries::activeForCode('0010');
        $new = app(ConfigureDocumentNumbering::class)->handle(
            DocumentType::ReceiptIncome,
            ['prefix' => '100', 'prefixDigits' => 4, 'numberDigits' => 5, 'separator' => '-',
                'nextNumber' => 50, 'reason' => 'Nueva serie del área.', 'token' => $series->configurationToken()], $actor->id,
        );
        $this->assertNotSame($series->id, $new->id);
        $this->assertFalse($series->refresh()->is_active);
        $this->assertSame('0010/00000001', $old->refresh()->formatted_number);
        $receipt = app(IssueIncomeReceipt::class)->handle($this->otraCuotaFinanciada());
        $this->assertSame('0100-00050', $receipt->formatted_number);
        $this->assertSame($new->id, $receipt->document_series_id);
        $this->assertSame($series->id, $old->document_series_id);
    }

    public function test_cambiar_de_serie_no_permite_repetir_el_numero_de_talonario(): void
    {
        app(IssueIncomeReceipt::class)->handle($this->cuotaFinanciada(), talonarioNumber: '72190');
        $actor = User::factory()->create();
        $series = DocumentSeries::activeForCode('0010');
        app(ConfigureDocumentNumbering::class)->handle(
            DocumentType::ReceiptIncome,
            ['prefix' => '0100', 'prefixDigits' => 4, 'numberDigits' => 8, 'separator' => '/',
                'nextNumber' => 2, 'reason' => 'Cambio de prefijo.', 'token' => $series->configurationToken()], $actor->id,
        );
        $this->expectException(QueryException::class);
        app(IssueIncomeReceipt::class)->handle($this->otraCuotaFinanciada(), talonarioNumber: '72190');
    }

    /** @return iterable<string, array{bool, bool, string}> */
    public static function vueltasAlFormatoOriginal(): iterable
    {
        foreach ([false, true] as $emitirIntermedio) {
            foreach ([false, true] as $repetirNumero) {
                foreach (['prefijo', 'separador'] as $cambio) {
                    yield $cambio.'-'.(int) $emitirIntermedio.'-'.(int) $repetirNumero => [$emitirIntermedio, $repetirNumero, $cambio];
                }
            }
        }
    }

    #[DataProvider('vueltasAlFormatoOriginal')]
    public function test_volver_al_formato_original_respeta_la_historia_sin_error_de_unicidad(bool $emitirIntermedio, bool $repetirNumero, string $cambio): void
    {
        $original = app(IssueIncomeReceipt::class)->handle($this->cuotaFinanciada());
        $actor = User::factory()->create();
        $configure = app(ConfigureDocumentNumbering::class);
        $intermedia = $configure->handle(DocumentType::ReceiptIncome, [
            'prefix' => $cambio === 'prefijo' ? '0100' : '0010',
            'prefixDigits' => 4, 'numberDigits' => 8, 'separator' => '-',
            'nextNumber' => 10, 'reason' => 'Cambio intermedio para verificar el regreso.',
            'token' => DocumentSeries::activeForCode('0010')->configurationToken(),
        ], $actor->id);
        $reciboIntermedio = $emitirIntermedio
            ? app(IssueIncomeReceipt::class)->handle($this->otraCuotaFinanciada())
            : null;
        // Si solo cambió el separador, el prefijo también consumió el número 10.
        $siguiente = $cambio === 'separador' && $emitirIntermedio ? 11 : 2;
        $datos = [
            'prefix' => '0010', 'prefixDigits' => 4, 'numberDigits' => 8, 'separator' => '/',
            'nextNumber' => $repetirNumero ? 1 : $siguiente, 'reason' => 'Regreso al formato original.',
            'token' => DocumentSeries::activeForCode('0010')->configurationToken(),
        ];

        if ($repetirNumero) {
            try {
                $configure->handle(DocumentType::ReceiptIncome, $datos, $actor->id);
                $this->fail('Se permitió reutilizar un número del formato original.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('nextNumber', $exception->errors());
            }
            $this->assertSame($intermedia->id, DocumentSeries::activeForCode('0010')->id);
        } else {
            $regreso = $configure->handle(DocumentType::ReceiptIncome, $datos, $actor->id);
            $nuevo = app(IssueIncomeReceipt::class)->handle($this->otraCuotaFinanciada());
            $this->assertSame('0010/'.str_pad((string) $siguiente, 8, '0', STR_PAD_LEFT), $nuevo->formatted_number);
            $this->assertSame($regreso->id, $nuevo->document_series_id);
            $this->assertNotSame($original->document_series_id, $regreso->id);
            $this->assertSame(! $emitirIntermedio, $intermedia->id === $regreso->id);
        }
        $this->assertSame('0010/00000001', $original->refresh()->formatted_number);
        if ($reciboIntermedio !== null) {
            $this->assertSame(($cambio === 'prefijo' ? '0100' : '0010').'-00000010', $reciboIntermedio->refresh()->formatted_number);
        }
        $this->assertSame(1, DocumentSeries::query()->where('code', '0010')->where('is_active', true)->count());
    }

    public function test_la_base_impide_reescribir_el_formato_de_una_serie_utilizada(): void
    {
        $receipt = app(IssueIncomeReceipt::class)->handle($this->cuotaFinanciada());
        $this->expectException(QueryException::class);
        DB::table('document_series')->where('id', $receipt->document_series_id)->update(['number_padding' => 5]);
    }

    public function test_no_se_puede_reutilizar_un_numero_emitido_con_el_mismo_prefijo(): void
    {
        app(IssueIncomeReceipt::class)->handle($this->cuotaFinanciada());
        $series = DocumentSeries::activeForCode('0010');
        $actor = User::factory()->create();
        $this->expectException(ValidationException::class);
        app(ConfigureDocumentNumbering::class)->handle(
            DocumentType::ReceiptIncome,
            ['prefix' => '0010', 'prefixDigits' => 4, 'numberDigits' => 5, 'separator' => '-',
                'nextNumber' => 1, 'reason' => 'Intento de reinicio.', 'token' => $series->configurationToken()], $actor->id,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Andamiaje
    |--------------------------------------------------------------------------
    */

    private function cuota(): BeneficiaryInstallment
    {
        return $this->expediente()->haberes()->firstOrFail()->installments()->firstOrFail();
    }

    private function cuotaFinanciada(): BeneficiaryInstallment
    {
        $cuota = $this->cuota();
        $importe = $cuota->importeEsperado();

        $this->asignar($this->recepcionEnEfectivo($importe), $cuota, $importe);

        return $cuota->refresh();
    }

    /**
     * Otra cuota completa, cualquiera menos la de `cuota()`.
     *
     * No hace falta que sea de otro haber ni de otro expediente: lo
     * único que el test necesita es una segunda cuota a la que intentar
     * emitirle un recibo con un número de talonario ya usado.
     *
     * **Ordenada y sin plata imputada**, y no es cosmético: sin `orderBy`
     * PostgreSQL devuelve las filas en el orden que se le antoja, y sin el
     * filtro la elegida podía ser una que el set de demostración ya deja
     * cobrada. El test fallaba una vez cada tantas corridas con «la cuota
     * está anulada o pagada», que no tiene nada que ver con lo que prueba.
     */
    private function otraCuotaFinanciada(): BeneficiaryInstallment
    {
        $cuota = BeneficiaryInstallment::query()
            ->where('id', '!=', $this->cuota()->id)
            ->where('workflow_status', InstallmentWorkflowStatus::Active)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('funding_allocations')
                ->whereColumn('funding_allocations.beneficiary_installment_id', 'beneficiary_installments.id'))
            ->whereHas('haber.expediente', fn ($q) => $q->whereNotNull('employer_id'))
            ->orderBy('id')
            ->firstOrFail();

        $importe = $cuota->importeEsperado();
        $this->asignar($this->recepcionEnEfectivo($importe), $cuota, $importe);

        return $cuota->refresh();
    }

    private function expediente(): Expediente
    {
        return Expediente::query()->whereNotNull('employer_id')->orderBy('id')->firstOrFail();
    }

    private function cajaHaberes(): int
    {
        return (int) CashBox::query()->where('code', CashBox::HABERES)->value('id');
    }

    private function recepcionEnEfectivo(string $importe): FundReceipt
    {
        return app(RegisterCashFundReceipt::class)->handle(
            amount: $importe,
            idempotencyKey: 'efectivo-'.Str::random(10),
            cashBoxId: $this->cajaHaberes(),
            receivedDate: now(),
        );
    }

    private function asignar(FundReceipt $recepcion, BeneficiaryInstallment $cuota, string $importe): void
    {
        app(AllocateFundsToInstallment::class)->handle(
            receipt: $recepcion,
            installment: $cuota,
            amount: $importe,
            idempotencyKey: 'asignacion-'.Str::random(10),
        );
    }

    /**
     * Una copia de la fila, para las escrituras directas a la base.
     *
     * @return array<string, mixed>
     */
    private function filaCruda(Receipt $recibo): array
    {
        $fila = (array) DB::table('receipts')->where('id', $recibo->id)->first();
        unset($fila['id']);

        return $fila;
    }
}
