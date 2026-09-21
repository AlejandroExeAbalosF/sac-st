<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Banking\Actions\RegisterBankFundReceipt;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankStatementImport;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Haberes\Actions\AllocateFundsToInstallment;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Haberes\Support\InstallmentFunding;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Ledger\Models\JournalLine;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Shared\Models\CashBox;
use App\Support\Money\Decimal;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * La cuota queda financiada.
 *
 * El último tramo del circuito: la recepción dijo que entró plata, y esto
 * dice de quién es. Es lo que habilita —más adelante— el recibo de ingreso
 * y la Orden de Pago.
 *
 * Los tres invariantes que se prueban acá son los que impiden pagar de más:
 * no se asigna más de lo que entró, no se financia una cuota por encima de
 * su derecho, y una cuota se financia con un solo medio.
 */
class FinanciacionDeCuotaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HaberesDemoSeeder::class);
    }

    public function test_asignar_una_recepcion_deja_la_cuota_financiada(): void
    {
        $cuota = $this->cuota();
        $importe = $cuota->importeEsperado();
        $recepcion = $this->recepcionBancaria($importe);

        $financiacion = app(InstallmentFunding::class);
        $this->assertFalse($financiacion->isFullyFunded($cuota));

        $asignacion = $this->asignar($recepcion, $cuota, $importe);

        $this->assertSame($importe, $asignacion->amount);
        $this->assertTrue($financiacion->isFullyFunded($cuota->refresh()));
        $this->assertSame('0.00', $financiacion->remaining($cuota));
        $this->assertSame('0.00', $financiacion->unallocated($recepcion->refresh()));
        $this->assertSame(PaymentMedium::Bank, $financiacion->medium($cuota));
        $this->assertSame(PaymentMedium::Bank, $financiacion->mediumForMany([$cuota->id])[$cuota->id]);
    }

    public function test_no_se_asigna_dinero_a_una_cuota_anulada(): void
    {
        $cuota = $this->cuota();
        $cuota->forceFill([
            'workflow_status' => InstallmentWorkflowStatus::Cancelled,
            'block_reason' => null,
        ])->save();

        try {
            $this->asignar($this->recepcionBancaria($cuota->importeEsperado()), $cuota, $cuota->importeEsperado());
            $this->fail('Una cuota anulada aceptó una asignación.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('anulada o pagada', $e->getMessage());
        }

        $this->assertSame(0, FundingAllocation::query()->count());
    }

    /**
     * El asiento mueve una atribución a otra, no una ubicación.
     *
     * La plata sigue en la cuenta del banco: lo que cambió es de quién es.
     */
    public function test_el_asiento_pasa_el_dinero_de_sin_identificar_al_beneficiario(): void
    {
        $cuota = $this->cuota();
        $importe = $cuota->importeEsperado();

        $asignacion = $this->asignar($this->recepcionBancaria($importe), $cuota, $importe);

        $lineas = JournalLine::query()
            ->where('financial_event_id', $asignacion->allocation_event_id)
            ->get();

        $this->assertCount(2, $lineas);

        $debito = $lineas->firstOrFail(fn (JournalLine $l): bool => $l->isDebit());
        $credito = $lineas->firstOrFail(fn (JournalLine $l): bool => ! $l->isDebit());

        $this->assertSame(LedgerAccount::UnassignedFunds, $debito->account_code);
        $this->assertSame(LedgerAccount::BeneficiaryFunds, $credito->account_code);
        $this->assertSame($cuota->id, $credito->beneficiary_installment_id);
        $this->assertSame($cuota->haber_id, $credito->haber_id);
    }

    /** Una cuota puede financiarse con más de un ingreso (§2.1.9). */
    public function test_una_cuota_se_puede_financiar_con_dos_recepciones(): void
    {
        $cuota = $this->cuota();
        $total = $cuota->importeEsperado();
        $mitad = Decimal::scale(bcdiv($total, '2', 4));
        $resto = Decimal::sub($total, $mitad);

        $financiacion = app(InstallmentFunding::class);

        $this->asignar($this->recepcionBancaria($mitad), $cuota, $mitad);

        $this->assertFalse($financiacion->isFullyFunded($cuota));
        $this->assertSame($resto, $financiacion->remaining($cuota));

        $this->asignar($this->recepcionBancaria($resto), $cuota, $resto);

        $this->assertTrue($financiacion->isFullyFunded($cuota->refresh()));
    }

    /** Invariante 2: no se reparte más de lo que entró. */
    public function test_no_se_asigna_mas_de_lo_que_tiene_la_recepcion(): void
    {
        $cuota = $this->cuota();
        $recepcion = $this->recepcionBancaria('50000.00');

        try {
            $this->asignar($recepcion, $cuota, '60000.00');
            $this->fail('Se asignó más plata de la que la recepción tiene.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('sin asignar', $e->getMessage());
        }

        $this->assertSame(0, FundingAllocation::query()->count());
    }

    /** Invariante 2, del otro lado: el derecho de la cuota es el tope. */
    public function test_no_se_financia_una_cuota_por_encima_de_su_importe(): void
    {
        $cuota = $this->cuota();
        $exceso = Decimal::add($cuota->importeEsperado(), '1000.00');
        $recepcion = $this->recepcionBancaria($exceso);

        try {
            $this->asignar($recepcion, $cuota, $exceso);
            $this->fail('Se financió la cuota por encima de su importe esperado.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('le faltan', $e->getMessage());
        }

        $this->assertSame(0, FundingAllocation::query()->count());
    }

    /**
     * Y la base lo impide aunque nadie pase por el Action.
     *
     * Es el control que de verdad protege: el Action explica, el trigger
     * garantiza.
     */
    public function test_la_base_rechaza_financiar_de_mas_sin_pasar_por_el_action(): void
    {
        $cuota = $this->cuota();
        $importe = $cuota->importeEsperado();

        $primera = $this->asignar($this->recepcionBancaria($importe), $cuota, $importe);
        $otraRecepcion = $this->recepcionBancaria('1000.00');

        $this->expectException(QueryException::class);

        DB::table('funding_allocations')->insert([
            'allocation_event_id' => $this->eventoSuelto(),
            'fund_receipt_id' => $otraRecepcion->id,
            'haber_id' => $primera->haber_id,
            'beneficiary_installment_id' => $cuota->id,
            'allocation_kind' => 'allocation',
            'amount' => '1000.00',
            'allocated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Invariante 4: una cuota se financia con un solo medio.
     *
     * No es una regla contable sino documental: el recibo de ingreso
     * imprime un medio en singular, y ese papel respalda la Orden.
     */
    public function test_una_cuota_no_mezcla_medios(): void
    {
        $cuota = $this->cuota();
        $total = $cuota->importeEsperado();
        $mitad = Decimal::scale(bcdiv($total, '2', 4));

        $this->asignar($this->recepcionBancaria($mitad), $cuota, $mitad);

        try {
            $this->asignar($this->recepcionEnEfectivo($mitad), $cuota, $mitad);
            $this->fail('La cuota aceptó financiarse con dos medios distintos.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('un solo medio', $e->getMessage());
        }

        $this->assertSame(1, FundingAllocation::query()->count());
    }

    /** El doble clic no financia la cuota dos veces. */
    public function test_el_segundo_envio_del_mismo_formulario_no_duplica_la_asignacion(): void
    {
        $cuota = $this->cuota();
        $importe = $cuota->importeEsperado();
        $recepcion = $this->recepcionBancaria($importe);

        $primera = $this->asignar($recepcion, $cuota, $importe, 'formulario-abierto-una-vez');
        $segunda = $this->asignar($recepcion, $cuota, $importe, 'formulario-abierto-una-vez');

        $this->assertSame($primera->id, $segunda->id);
        $this->assertSame(1, FundingAllocation::query()->count());
    }

    public function test_una_asignacion_no_se_edita_ni_se_borra(): void
    {
        $cuota = $this->cuota();
        $importe = $cuota->importeEsperado();
        $asignacion = $this->asignar($this->recepcionBancaria($importe), $cuota, $importe);

        try {
            DB::table('funding_allocations')->where('id', $asignacion->id)->update(['amount' => '1.00']);
            $this->fail('El importe de una asignación se pudo editar.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('no se edita', $e->getMessage());
        }

        $this->expectException(QueryException::class);

        DB::table('funding_allocations')->where('id', $asignacion->id)->delete();
    }

    /**
     * La cuota tiene que ser del haber que la asignación dice.
     *
     * Lo garantiza la foránea compuesta: sin ella se podría financiar la
     * cuota de un beneficiario imputándosela a otro, y todo lo que se
     * calcule por haber quedaría mal en silencio.
     */
    public function test_no_se_asigna_una_cuota_a_un_haber_ajeno(): void
    {
        $cuota = $this->cuota();
        $recepcion = $this->recepcionBancaria('1000.00');

        $otroHaber = DB::table('haberes')->where('id', '!=', $cuota->haber_id)->value('id');
        $this->assertNotNull($otroHaber, 'El seeder tiene que dejar más de un haber.');

        $this->expectException(QueryException::class);

        DB::table('funding_allocations')->insert([
            'allocation_event_id' => $this->eventoSuelto(),
            'fund_receipt_id' => $recepcion->id,
            'haber_id' => $otroHaber,
            'beneficiary_installment_id' => $cuota->id,
            'allocation_kind' => 'allocation',
            'amount' => '1000.00',
            'allocated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Andamiaje
    |--------------------------------------------------------------------------
    */

    /**
     * Las cuatro filas se leen con `FOR UPDATE`, y en ese orden.
     *
     * Es lo unico que impide que dos operadores imputando recepciones
     * distintas a la misma cuota lean el mismo pendiente y la financien
     * de mas: los triggers consultan las asignaciones **despues** de
     * insertar, asi que no serializan nada entre transacciones.
     *
     * El orden importa tanto como los bloqueos. De afuera hacia adentro
     * —expediente, haber, cuota, recepcion— es el mismo que toman
     * `CancelExpediente` y `CancelHaber`; invertirlo en cualquiera de los
     * tres abriria un abrazo mortal en vez de cerrar una carrera.
     *
     * La concurrencia real no se puede reproducir aca —`RefreshDatabase`
     * mantiene todo dentro de una transaccion y una segunda conexion no
     * veria estos datos—, asi que se verifica lo que si es verificable y
     * es exactamente lo que importa: que los bloqueos esten, y en orden.
     */
    public function test_la_asignacion_bloquea_de_afuera_hacia_adentro(): void
    {
        $cuota = $this->cuota();
        $recepcion = $this->recepcionBancaria($cuota->importeEsperado());

        $bloqueadas = [];

        DB::listen(function ($query) use (&$bloqueadas): void {
            $sql = strtolower($query->sql);

            if (! str_contains($sql, 'for update')) {
                return;
            }

            foreach (['expedientes', 'haberes', 'beneficiary_installments', 'fund_receipts'] as $tabla) {
                if (str_contains($sql, '"'.$tabla.'"')) {
                    // La recepcion se bloquea dos veces —el modelo y el
                    // disponible—; lo que se verifica es el orden, no
                    // cuantas veces se pide la misma fila.
                    if ($bloqueadas === [] || end($bloqueadas) !== $tabla) {
                        $bloqueadas[] = $tabla;
                    }

                    return;
                }
            }
        });

        $this->asignar($recepcion, $cuota, $cuota->importeEsperado());

        $this->assertSame(
            ['expedientes', 'haberes', 'beneficiary_installments', 'fund_receipts'],
            $bloqueadas,
        );
    }

    private function asignar(
        FundReceipt $recepcion,
        BeneficiaryInstallment $cuota,
        string $importe,
        ?string $clave = null,
    ): FundingAllocation {
        return app(AllocateFundsToInstallment::class)->handle(
            receipt: $recepcion,
            installment: $cuota,
            amount: $importe,
            idempotencyKey: $clave ?? 'asignacion-'.Str::random(10),
        );
    }

    private function cuota(): BeneficiaryInstallment
    {
        $expediente = Expediente::query()->whereNotNull('employer_id')->orderBy('id')->firstOrFail();

        return $expediente->haberes()->firstOrFail()->installments()->firstOrFail();
    }

    private function cajaHaberes(): int
    {
        return (int) CashBox::query()->where('code', CashBox::HABERES)->value('id');
    }

    /** Recorre el circuito real: movimiento del extracto → recepción. */
    private function recepcionBancaria(string $importe): FundReceipt
    {
        $cuenta = BankAccount::query()->firstOr(fn (): BankAccount => BankAccount::query()->create([
            'label' => 'Cta. Cte. 2693 — Haberes',
            'bank_name' => 'Banco Macro',
            'account_number' => '310000123456789',
            'currency' => 'ARS',
            'is_active' => true,
        ]));

        $movimiento = BankTransaction::query()->create([
            'bank_account_id' => $cuenta->id,
            'first_seen_import_id' => $this->importacion($cuenta)->id,
            'transaction_date' => '2026-04-24',
            'amount' => $importe,
            'direction' => TransactionDirection::Credit,
            'description' => 'Transferencia recibida',
            'fingerprint' => hash('sha256', uniqid('', true)),
        ]);

        return app(RegisterBankFundReceipt::class)->handle(
            transaction: $movimiento,
            amount: $importe,
            idempotencyKey: 'recepcion-'.Str::random(10),
            cashBoxId: $this->cajaHaberes(),
        );
    }

    /**
     * Una recepción en efectivo, escrita a mano.
     *
     * El circuito de efectivo no existe todavía —es de otra etapa—, pero
     * el invariante del medio único hay que poder probarlo igual.
     */
    private function recepcionEnEfectivo(string $importe): FundReceipt
    {
        $evento = app(PostJournalEntry::class)->handle(
            type: FinancialEventType::FundsReceived,
            idempotencyKey: 'efectivo-'.Str::random(10),
            lines: [
                EntryLine::debit(LedgerAccount::CashOnHand, $importe),
                EntryLine::credit(LedgerAccount::UnassignedFunds, $importe),
            ],
            date: now(),
            cashBoxId: $this->cajaHaberes(),
        );

        return FundReceipt::query()->create([
            'financial_event_id' => $evento->id,
            'cash_box_id' => $this->cajaHaberes(),
            'medium' => PaymentMedium::Cash,
            'amount' => $importe,
            'received_date' => '2026-04-24',
        ]);
    }

    /** Un evento cualquiera, para las escrituras directas a la base. */
    private function eventoSuelto(): int
    {
        return app(PostJournalEntry::class)->handle(
            type: FinancialEventType::FundsAllocated,
            idempotencyKey: 'suelto-'.Str::random(10),
            lines: [
                EntryLine::debit(LedgerAccount::UnassignedFunds, '1000.00'),
                EntryLine::credit(LedgerAccount::BeneficiaryFunds, '1000.00'),
            ],
            date: now(),
        )->id;
    }

    private function importacion(BankAccount $cuenta): BankStatementImport
    {
        return BankStatementImport::query()->create([
            'bank_account_id' => $cuenta->id,
            'imported_by' => $this->operador()->id,
            'original_filename' => 'extracto.csv',
            'file_size' => 100,
            'file_sha256' => hash('sha256', uniqid('', true)),
            'source_format' => 'macro_online_csv',
            'parser_version' => 'macro-csv-1',
            'period_from' => '2026-04-01',
            'period_to' => '2026-04-30',
            'status' => 'completed',
            'rows_total' => 1,
            'rows_valid' => 1,
            'rows_rejected' => 0,
            'rows_new' => 1,
            'rows_duplicate' => 0,
            'imported_at' => now(),
        ]);
    }
}
