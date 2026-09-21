<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankStatementImport;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Haberes\Actions\FindTicketCandidates;
use App\Modules\Haberes\Actions\LinkDepositTicket;
use App\Modules\Haberes\Enums\DepositKind;
use App\Modules\Haberes\Enums\DepositTicketStatus;
use App\Modules\Haberes\Models\DepositTicket;
use App\Modules\Haberes\Models\Expediente;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Encontrar en el extracto el movimiento que trajo el ticket.
 *
 * El flujo que describió el área: llega el expediente con el comprobante,
 * se cargan sus datos, y cada tanto se revisa el banco buscando ese
 * crédito. Acá se prueba la parte que hace ese trabajo.
 */
class CruceDeTicketsTest extends TestCase
{
    use RefreshDatabase;

    private const CUENTA = '310000123456789';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HaberesDemoSeeder::class);
    }

    /** Un solo candidato: mismo importe, mismo día. */
    public function test_encuentra_el_movimiento_del_mismo_dia_y_el_mismo_importe(): void
    {
        $cuenta = $this->cuenta();
        $ticket = $this->ticket($cuenta, '72000.00', '2026-04-24');

        $esperado = $this->movimiento($cuenta, '72000.00', '2026-04-24');
        // Ruido: mismo día, otro importe.
        $this->movimiento($cuenta, '35000.00', '2026-04-24');
        // Ruido: mismo importe, muy lejos en el tiempo.
        $this->movimiento($cuenta, '72000.00', '2026-06-01');

        $resultado = app(FindTicketCandidates::class)->handle($ticket);

        $this->assertCount(1, $resultado->candidates);
        $this->assertSame($esperado->id, $resultado->candidates[0]->transaction->id);
        $this->assertSame('el mismo día', $resultado->candidates[0]->signals['fecha']);
    }

    /**
     * El importe se compara exacto, sin tolerancia.
     *
     * Las comisiones son movimientos separados en el extracto —causal
     * 3914—, así que el crédito entra completo. Si difiere, es otro
     * depósito.
     */
    public function test_un_importe_distinto_no_es_candidato(): void
    {
        $cuenta = $this->cuenta();
        $ticket = $this->ticket($cuenta, '72000.00', '2026-04-24');

        $this->movimiento($cuenta, '71999.00', '2026-04-24');
        $this->movimiento($cuenta, '72000.01', '2026-04-24');

        $this->assertEmpty(app(FindTicketCandidates::class)->handle($ticket)->candidates);
    }

    /**
     * La ventana de fechas es asimétrica: el dinero no se acredita antes
     * de depositarse.
     */
    public function test_la_ventana_mira_hacia_adelante(): void
    {
        $cuenta = $this->cuenta();
        $ticket = $this->ticket($cuenta, '72000.00', '2026-04-24');

        $siguiente = $this->movimiento($cuenta, '72000.00', '2026-04-27');
        // Cuatro días antes del depósito: imposible.
        $this->movimiento($cuenta, '72000.00', '2026-04-20');

        $resultado = app(FindTicketCandidates::class)->handle($ticket);

        $this->assertCount(1, $resultado->candidates);
        $this->assertSame($siguiente->id, $resultado->candidates[0]->transaction->id);
        $this->assertSame('3 días después', $resultado->candidates[0]->signals['fecha']);
    }

    /** El más cercano en el tiempo va primero. */
    public function test_ordena_por_cercania(): void
    {
        $cuenta = $this->cuenta();
        $ticket = $this->ticket($cuenta, '72000.00', '2026-04-24');

        $this->movimiento($cuenta, '72000.00', '2026-04-28');
        $cercano = $this->movimiento($cuenta, '72000.00', '2026-04-25');

        $resultado = app(FindTicketCandidates::class)->handle($ticket);

        $this->assertCount(2, $resultado->candidates);
        $this->assertSame($cercano->id, $resultado->candidates[0]->transaction->id);
    }

    /**
     * El número de operación manda sobre la cercanía.
     *
     * Y queda registrado que coincidió: es lo que después va a permitir
     * responder con datos si este campo sirve o no.
     */
    public function test_la_coincidencia_de_operacion_gana(): void
    {
        $cuenta = $this->cuenta();
        $ticket = $this->ticket($cuenta, '72000.00', '2026-04-24', operacion: '1053565801');

        $this->movimiento($cuenta, '72000.00', '2026-04-24');
        $conReferencia = $this->movimiento(
            $cuenta,
            '72000.00',
            '2026-04-28',
            operationId: '1053565801',
        );

        $resultado = app(FindTicketCandidates::class)->handle($ticket);

        $this->assertSame($conReferencia->id, $resultado->candidates[0]->transaction->id);
        $this->assertTrue($resultado->candidates[0]->operationMatches);
        $this->assertSame(
            'coincide con la referencia del extracto',
            $resultado->candidates[0]->signals['operación'],
        );
    }

    /** También lo busca dentro del concepto, donde el extracto a veces lo esconde. */
    public function test_la_operacion_tambien_se_busca_en_el_concepto(): void
    {
        $cuenta = $this->cuenta();
        $ticket = $this->ticket($cuenta, '72000.00', '2026-04-24', operacion: '995979830');

        $this->movimiento(
            $cuenta,
            '72000.00',
            '2026-04-24',
            descripcion: '995979830 - Numero de Operacion',
        );

        $resultado = app(FindTicketCandidates::class)->handle($ticket);

        $this->assertSame(
            'aparece dentro del concepto',
            $resultado->candidates[0]->signals['operación'],
        );
    }

    /**
     * El CUIT del concepto se contrasta con el empleador del expediente.
     *
     * Que coincida es la confirmación más fuerte que da el banco; que no,
     * una advertencia visible —el empleador puede depositar desde la
     * cuenta de un tercero—.
     */
    public function test_señala_si_el_cuit_es_el_del_empleador(): void
    {
        $cuenta = $this->cuenta();
        $expediente = $this->expediente();
        $documentoEmpleador = $expediente->employer?->document;
        $this->assertNotNull($documentoEmpleador);

        $ticket = $this->ticket($cuenta, '72000.00', '2026-04-24', expediente: $expediente);

        $this->movimiento($cuenta, '72000.00', '2026-04-24', cuit: $documentoEmpleador);

        $resultado = app(FindTicketCandidates::class)->handle($ticket);

        $this->assertTrue($resultado->candidates[0]->employerMatches);
        $this->assertStringContainsString(
            'es el del empleador',
            $resultado->candidates[0]->signals['CUIT del concepto'],
        );
    }

    public function test_avisa_cuando_el_cuit_no_es_el_del_empleador(): void
    {
        $cuenta = $this->cuenta();
        $ticket = $this->ticket($cuenta, '72000.00', '2026-04-24');

        $this->movimiento($cuenta, '72000.00', '2026-04-24', cuit: '30333333339');

        $resultado = app(FindTicketCandidates::class)->handle($ticket);

        $this->assertFalse($resultado->candidates[0]->employerMatches);
        $this->assertStringContainsString(
            'NO es el del empleador',
            $resultado->candidates[0]->signals['CUIT del concepto'],
        );
    }

    /**
     * Sin candidatos hay dos situaciones distintas, y confundirlas manda al
     * operador a buscar donde no es.
     */
    public function test_distingue_falta_importar_de_no_aparece(): void
    {
        $cuenta = $this->cuenta();
        $ticket = $this->ticket($cuenta, '72000.00', '2026-04-24');

        // Sin ninguna importación: no hay dónde buscar.
        $sinExtracto = app(FindTicketCandidates::class)->handle($ticket);

        $this->assertFalse($sinExtracto->periodImported);
        $this->assertStringContainsString('Importá el período', (string) $sinExtracto->emptyReason());

        // Con el período importado, pero sin ese crédito.
        $this->importacion($cuenta, '2026-04-01', '2026-04-30');

        $conExtracto = app(FindTicketCandidates::class)->handle($ticket->refresh());

        $this->assertTrue($conExtracto->periodImported);
        $this->assertStringContainsString('Revisá los datos del ticket', (string) $conExtracto->emptyReason());
    }

    /** Un movimiento ya tomado no se vuelve a ofrecer. */
    public function test_un_movimiento_vinculado_no_es_candidato(): void
    {
        $cuenta = $this->cuenta();
        $movimiento = $this->movimiento($cuenta, '72000.00', '2026-04-24');

        $primero = $this->ticket($cuenta, '72000.00', '2026-04-24');
        app(LinkDepositTicket::class)->handle($primero, $movimiento, $this->operador()->id);

        $segundo = $this->ticket($cuenta, '72000.00', '2026-04-24');

        $this->assertEmpty(app(FindTicketCandidates::class)->handle($segundo)->candidates);
    }

    /** Y la base lo impone, no solo la consulta. */
    public function test_la_base_impide_dos_tickets_sobre_el_mismo_movimiento(): void
    {
        $cuenta = $this->cuenta();
        $movimiento = $this->movimiento($cuenta, '72000.00', '2026-04-24');

        $primero = $this->ticket($cuenta, '72000.00', '2026-04-24');
        app(LinkDepositTicket::class)->handle($primero, $movimiento, $this->operador()->id);

        $segundo = $this->ticket($cuenta, '72000.00', '2026-04-24');

        $this->expectException(QueryException::class);

        $segundo->forceFill([
            'bank_transaction_id' => $movimiento->id,
            'status' => DepositTicketStatus::Matched,
            'matched_at' => now(),
            'matched_by' => $this->operador()->id,
        ])->save();
    }

    public function test_vincular_guarda_las_señales_que_coincidieron(): void
    {
        $cuenta = $this->cuenta();
        $ticket = $this->ticket($cuenta, '72000.00', '2026-04-24', operacion: '1053565801');
        $movimiento = $this->movimiento(
            $cuenta,
            '72000.00',
            '2026-04-24',
            operationId: '1053565801',
        );

        app(LinkDepositTicket::class)->handle($ticket, $movimiento, $this->operador()->id);

        $ticket->refresh();

        $this->assertSame(DepositTicketStatus::Matched, $ticket->status);
        $this->assertSame('exacto', $ticket->match_signals['importe']);
        $this->assertSame(
            'coincide con la referencia del extracto',
            $ticket->match_signals['operación'],
        );
        $this->assertNotNull($ticket->matched_at);
    }

    public function test_no_se_vincula_un_movimiento_de_otro_importe(): void
    {
        $cuenta = $this->cuenta();
        $ticket = $this->ticket($cuenta, '72000.00', '2026-04-24');
        $otro = $this->movimiento($cuenta, '35000.00', '2026-04-24');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('35.000,00');

        app(LinkDepositTicket::class)->handle($ticket, $otro, $this->operador()->id);
    }

    /** Desvincular devuelve el ticket a la cola y libera el movimiento. */
    public function test_se_puede_desvincular(): void
    {
        $cuenta = $this->cuenta();
        $ticket = $this->ticket($cuenta, '72000.00', '2026-04-24');
        $movimiento = $this->movimiento($cuenta, '72000.00', '2026-04-24');

        $accion = app(LinkDepositTicket::class);
        $accion->handle($ticket, $movimiento, $this->operador()->id);
        $accion->unlink($ticket->refresh(), 'Era el depósito de otro expediente', $this->operador()->id);

        $ticket->refresh();

        $this->assertSame(DepositTicketStatus::Waiting, $ticket->status);
        $this->assertNull($ticket->bank_transaction_id);
        $this->assertNull($ticket->match_signals);

        // Y el movimiento vuelve a estar disponible.
        $otro = $this->ticket($cuenta, '72000.00', '2026-04-24');
        $this->assertCount(1, app(FindTicketCandidates::class)->handle($otro)->candidates);
    }

    /**
     * La cuota declarada por otro importe no bloquea, pero se avisa: puede
     * ser un pago parcial o la cuota equivocada.
     */
    public function test_avisa_si_el_ticket_no_coincide_con_la_cuota(): void
    {
        $cuenta = $this->cuenta();
        $expediente = $this->expediente();
        $haber = $expediente->haberes()->firstOrFail();
        $cuota = $haber->installments()->firstOrFail();

        $ticket = $this->ticket(
            $cuenta,
            // Un peso menos que lo que la cuota espera.
            (string) (((float) $cuota->expected_amount) - 1),
            '2026-04-24',
            expediente: $expediente,
        );

        $ticket->forceFill([
            'haber_id' => $haber->id,
            'beneficiary_installment_id' => $cuota->id,
        ])->save();

        $resultado = app(FindTicketCandidates::class)->handle($ticket->refresh());

        $this->assertNotEmpty($resultado->warnings);
        $this->assertStringContainsString('la cuota espera', $resultado->warnings[0]);
    }

    private function cuenta(): BankAccount
    {
        return BankAccount::query()->create([
            'label' => 'Cta. Cte. 2693 — Haberes',
            'bank_name' => 'Banco Macro',
            'account_number' => self::CUENTA,
            'currency' => 'ARS',
            'is_active' => true,
        ]);
    }

    private function expediente(): Expediente
    {
        return Expediente::query()->whereNotNull('employer_id')->orderBy('id')->firstOrFail();
    }

    private function ticket(
        BankAccount $cuenta,
        string $importe,
        string $fecha,
        ?string $operacion = null,
        ?Expediente $expediente = null,
    ): DepositTicket {
        return DepositTicket::query()->create([
            'expediente_id' => ($expediente ?? $this->expediente())->id,
            'bank_account_id' => $cuenta->id,
            'deposited_at' => $fecha,
            'amount' => $importe,
            'operation_number' => $operacion,
            'deposit_kind' => DepositKind::CashDeposit,
            'status' => DepositTicketStatus::Waiting,
        ]);
    }

    private function movimiento(
        BankAccount $cuenta,
        string $importe,
        string $fecha,
        ?string $operationId = null,
        ?string $descripcion = null,
        ?string $cuit = null,
    ): BankTransaction {
        return BankTransaction::query()->create([
            'bank_account_id' => $cuenta->id,
            'first_seen_import_id' => $this->importacion($cuenta, $fecha, $fecha)->id,
            'transaction_date' => $fecha,
            'amount' => $importe,
            'direction' => TransactionDirection::Credit,
            'operation_id' => $operationId,
            'description' => $descripcion ?? 'Deposito en efectivo',
            'counterparty_identifier' => $cuit,
            'fingerprint' => hash('sha256', $cuenta->id.$fecha.$importe.($operationId ?? uniqid())),
        ]);
    }

    private function importacion(BankAccount $cuenta, string $desde, string $hasta): BankStatementImport
    {
        return BankStatementImport::query()->create([
            'bank_account_id' => $cuenta->id,
            'imported_by' => $this->operador()->id,
            'original_filename' => 'extracto.csv',
            'file_size' => 100,
            'file_sha256' => hash('sha256', uniqid('', true)),
            'source_format' => 'macro_online_csv',
            'parser_version' => 'macro-csv-1',
            'period_from' => $desde,
            'period_to' => $hasta,
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
