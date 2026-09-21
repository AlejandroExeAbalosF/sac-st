<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Banking\Actions\RegisterBankFundReceipt;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankStatementImport;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Haberes\Actions\ReverseFundReceipt;
use App\Modules\Haberes\Enums\DepositTicketStatus;
use App\Modules\Haberes\Enums\InstallmentWorkflowStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\DepositTicket;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\FundingAllocation;
use App\Modules\Haberes\Support\InstallmentFunding;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Models\Person;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * El circuito visto desde la pantalla.
 *
 * Lo que se prueba acá no son los invariantes —eso ya está en
 * `FinanciacionDeCuotaTest`— sino que el recorrido se pueda hacer: entrar
 * al movimiento, registrar la recepción, y desde ahí asignarla a la cuota
 * que el comprobante señaló.
 */
class PantallasDeRecepcionesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HaberesDemoSeeder::class);
    }

    /**
     * La revertida se muestra como tal y deja de pedir atención.
     *
     * «Pendientes» filtra por importe mayor a lo asignado, y una recepción
     * revertida no tiene asignaciones: sin excluirla aparecía en la cola de
     * trabajo pidiendo que alguien repartiera un dinero que ya salió de los
     * libros.
     */
    public function test_la_revertida_no_figura_entre_las_pendientes(): void
    {
        $recepcion = $this->recepcion('1000.00');

        $this->actingAs($this->operador())
            ->get(route('recepciones.index', ['pendientes' => 1]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('receipts.data', 1));

        app(ReverseFundReceipt::class)->handle(
            receipt: $recepcion,
            reason: 'No correspondía asentarla: era un depósito nuestro.',
            idempotencyKey: 'reversion-'.$recepcion->id,
            actorId: $this->operador()->id,
        );

        $this->actingAs($this->operador())
            ->get(route('recepciones.index', ['pendientes' => 1]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('receipts.data', 0));

        // Sin el filtro sigue estando, y el listado dice que se revirtió.
        $this->actingAs($this->operador())
            ->get(route('recepciones.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('receipts.data', 1)
                ->whereNot('receipts.data.0.reversedAt', null));
    }

    /*
     |---------------------------------------------------------------------
     | El buscador
     |---------------------------------------------------------------------
     |
     | Tres preguntas, las que el área le hace a esta lista: «¿entró la
     | plata del 125958?», «¿qué depositó CIACSA?» y «¿de dónde salieron
     | estos $240.000?».
     |
     */

    /**
     * La búsqueda por expediente, que es la pregunta principal.
     *
     * El expediente no es una columna de la recepción: se llega por la
     * imputación bancaria y el comprobante que trajo ese crédito. Sin este
     * caso armado entero —expediente, comprobante cruzado y recepción— la
     * consulta se probaría a sí misma contra la nada.
     */
    public function test_se_busca_por_numero_de_expediente(): void
    {
        $expediente = $this->cuota()->haber->expediente;
        $conExpediente = $this->recepcionDe($expediente);

        // Y otra que no tiene nada que ver, para que el filtro tenga algo
        // que descartar.
        $this->recepcion('9999.00');

        $this->actingAs($this->operador())
            ->get(route('recepciones.index', ['q' => $expediente->display_number]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('receipts.data', 1)
                ->where('receipts.data.0.id', $conExpediente->id)
                ->where('receipts.data.0.expedienteNumber', $expediente->display_number));
    }

    /** Alcanza con un pedazo del número, que es como se tipea. */
    public function test_el_expediente_se_busca_por_parte_del_numero(): void
    {
        $expediente = $this->cuota()->haber->expediente;
        $this->recepcionDe($expediente);

        $parte = explode('/', $expediente->display_number)[0];

        $this->actingAs($this->operador())
            ->get(route('recepciones.index', ['q' => $parte]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('receipts.data', 1));
    }

    public function test_se_busca_por_importe(): void
    {
        $this->recepcion('1000.00');
        $this->recepcion('2500.50');

        $this->actingAs($this->operador())
            ->get(route('recepciones.index', ['q' => '2500,50']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('receipts.data', 1)
                ->where('receipts.data.0.amount', '2500.50'));
    }

    /** El importe se escribe como se lee, con coma o con punto. */
    public function test_el_importe_se_busca_en_cualquiera_de_sus_formas(): void
    {
        $this->recepcion('2500.50');

        foreach (['2500.50', '2.500,50', '2500,5'] as $escrito) {
            $this->actingAs($this->operador())
                ->get(route('recepciones.index', ['q' => $escrito]))
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->has('receipts.data', 1));
        }
    }

    public function test_se_busca_por_depositante_sin_acentos(): void
    {
        $recepcion = $this->recepcion('1000.00');
        $recepcion->forceFill([
            'depositor_id' => Person::query()->where('type', 'company')->valueOrFail('id'),
        ])->save();

        $this->recepcion('3000.00');

        $nombre = (string) Person::query()->where('type', 'company')->valueOrFail('name');

        $this->actingAs($this->operador())
            ->get(route('recepciones.index', [
                'q' => Str::lower(Str::substr(Str::ascii($nombre), 0, 5)),
            ]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('receipts.data', 1)
                ->where('receipts.data.0.id', $recepcion->id));
    }

    /** Lo que no coincide con nada devuelve vacío, no todo. */
    public function test_una_busqueda_sin_coincidencias_no_trae_nada(): void
    {
        $this->recepcion('1000.00');

        $this->actingAs($this->operador())
            ->get(route('recepciones.index', ['q' => 'no-existe-esto']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('receipts.data', 0)
                ->where('filters.q', 'no-existe-esto'));
    }

    /**
     * Buscar no saca del filtro, ni el filtro borra la búsqueda.
     *
     * Son dos cosas que se combinan: «de lo que todavía no tiene dueño,
     * cuál es de este expediente».
     */
    public function test_la_busqueda_convive_con_el_filtro(): void
    {
        $conSaldo = $this->recepcion('1000.00');

        $this->actingAs($this->operador())
            ->get(route('recepciones.index', ['pendientes' => 1, 'q' => '1000']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('receipts.data', 1)
                ->where('receipts.data.0.id', $conSaldo->id)
                ->where('filters.pendientes', true)
                ->where('filters.q', '1000'));
    }

    public function test_el_listado_de_recepciones_se_ve(): void
    {
        $this->actingAs($this->operador())
            ->get(route('recepciones.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('recepciones/index')
                ->has('receipts.data')
            );
    }

    public function test_la_pantalla_de_la_recepcion_propone_las_cuotas_del_expediente(): void
    {
        $cuota = $this->cuota();
        $recepcion = $this->recepcion($cuota->importeEsperado());

        $expediente = $cuota->haber->expediente;

        $this->actingAs($this->operador())
            ->get(route('recepciones.show', [
                'receipt' => $recepcion->id,
                'expediente' => $expediente->display_number,
            ]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('recepciones/show')
                ->where('receipt.id', $recepcion->id)
                ->where('receipt.unallocated', $cuota->importeEsperado())
                ->has('candidates')
                // La cuota del expediente buscado tiene que estar entre las
                // candidatas, con lo que le falta ya calculado.
                ->where('candidates.0.remaining', $cuota->importeEsperado())
            );
    }

    public function test_la_pantalla_no_ofrece_cuotas_anuladas(): void
    {
        $cuota = $this->cuota();
        $cuota->forceFill([
            'workflow_status' => InstallmentWorkflowStatus::Cancelled,
            'block_reason' => null,
        ])->save();
        $recepcion = $this->recepcion($cuota->importeEsperado());

        $this->actingAs($this->operador())
            ->get(route('recepciones.show', [
                'receipt' => $recepcion->id,
                'expediente' => $cuota->haber->expediente->display_number,
            ]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where(
                    'candidates',
                    fn ($candidates): bool => collect($candidates)
                        ->doesntContain('id', $cuota->id),
                )
            );
    }

    public function test_asignar_desde_la_pantalla_financia_la_cuota(): void
    {
        $cuota = $this->cuota();
        $importe = $cuota->importeEsperado();
        $recepcion = $this->recepcion($importe);

        $this->actingAs($this->operador())
            ->post(route('recepciones.allocate', $recepcion), [
                'installmentId' => $cuota->id,
                'amount' => $importe,
                'idempotencyKey' => 'asignacion:'.$recepcion->id.':unica',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(1, FundingAllocation::query()->count());
        $this->assertTrue(app(InstallmentFunding::class)->isFullyFunded($cuota->refresh()));
    }

    /** El error del Action llega al formulario, no como excepción. */
    public function test_pedir_mas_de_lo_que_queda_devuelve_un_error_de_validacion(): void
    {
        $cuota = $this->cuota();
        $recepcion = $this->recepcion('1000.00');

        $this->actingAs($this->operador())
            ->post(route('recepciones.allocate', $recepcion), [
                'installmentId' => $cuota->id,
                'amount' => '5000.00',
                'idempotencyKey' => 'asignacion-excesiva',
            ])
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, FundingAllocation::query()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Permisos
    |--------------------------------------------------------------------------
    */

    public function test_quien_solo_consulta_ve_las_recepciones(): void
    {
        $this->actingAs($this->operador('consulta'))
            ->get(route('recepciones.index'))
            ->assertOk();
    }

    public function test_quien_solo_consulta_no_asigna(): void
    {
        $cuota = $this->cuota();
        $recepcion = $this->recepcion($cuota->importeEsperado());

        $this->actingAs($this->operador('consulta'))
            ->post(route('recepciones.allocate', $recepcion), [
                'installmentId' => $cuota->id,
                'amount' => $cuota->importeEsperado(),
                'idempotencyKey' => 'no-deberia-entrar',
            ])
            ->assertForbidden();

        $this->assertSame(0, FundingAllocation::query()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Andamiaje
    |--------------------------------------------------------------------------
    */

    /**
     * Una recepción llegada por el comprobante de un expediente.
     *
     * Es la cadena real: el papel del expediente se cruza con el crédito
     * del extracto, y de ese crédito nace la recepción. Es lo que hace que
     * la recepción sepa de qué expediente vino.
     */
    private function recepcionDe(Expediente $expediente): FundReceipt
    {
        $movimiento = $this->credito('1000.00');

        DepositTicket::query()->create([
            'expediente_id' => $expediente->id,
            'bank_account_id' => $movimiento->bank_account_id,
            'bank_transaction_id' => $movimiento->id,
            'matched_at' => now(),
            'matched_by' => $this->operador()->id,
            'deposited_at' => '2026-04-24',
            'amount' => '1000.00',
            'deposit_kind' => 'transfer',
            'status' => DepositTicketStatus::Matched,
        ]);

        return app(RegisterBankFundReceipt::class)->handle(
            transaction: $movimiento,
            amount: '1000.00',
            idempotencyKey: 'recepcion-exp-'.$movimiento->id,
            cashBoxId: $this->cajaHaberes(),
            actorId: $this->operador()->id,
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

    /**
     * La ficha trae el permiso de revertir, y el listado no lo necesita.
     *
     * Estuvo mal puesto: fue a parar al listado, que no lo usa, y la ficha
     * —donde vive el botón— lo recibía en `undefined`. El botón no se
     * dibujaba y nada fallaba: una condición contra un permiso ausente es
     * simplemente falsa.
     */
    public function test_la_ficha_dice_si_se_puede_revertir(): void
    {
        $recepcion = $this->recepcion('1000.00');

        $this->actingAs($this->operador('contador'))
            ->get(route('recepciones.show', $recepcion))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('recepciones/show')
                ->where('canReverse', true));
    }

    /** Quien no puede revertir recibe el permiso en falso, no ausente. */
    public function test_quien_no_puede_revertir_lo_recibe_en_falso(): void
    {
        $recepcion = $this->recepcion('1000.00');

        $this->actingAs($this->operador('administrativo'))
            ->get(route('recepciones.show', $recepcion))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('canReverse', false));
    }

    /**
     * La recepción, por el Action.
     *
     * Ya no hay pantalla de alta —desvío 47—: la recepción nace del atajo
     * «registrar y asignar», que llama a este mismo Action por dentro.
     */
    private function recepcion(string $importe): FundReceipt
    {
        $movimiento = $this->credito($importe);

        return app(RegisterBankFundReceipt::class)->handle(
            transaction: $movimiento,
            amount: $importe,
            idempotencyKey: 'recepcion-'.$movimiento->id,
            cashBoxId: $this->cajaHaberes(),
            actorId: $this->operador()->id,
        );
    }

    private function credito(string $importe): BankTransaction
    {
        $cuenta = BankAccount::query()->firstOr(fn (): BankAccount => BankAccount::query()->create([
            'label' => 'Cta. Cte. 2693 — Haberes',
            'bank_name' => 'Banco Macro',
            'account_number' => '310000123456789',
            'currency' => 'ARS',
            'is_active' => true,
        ]));

        return BankTransaction::query()->create([
            'bank_account_id' => $cuenta->id,
            'first_seen_import_id' => $this->importacion($cuenta)->id,
            'transaction_date' => '2026-04-24',
            'amount' => $importe,
            'direction' => TransactionDirection::Credit,
            'description' => 'Transferencia recibida',
            'fingerprint' => hash('sha256', uniqid('', true)),
        ]);
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
