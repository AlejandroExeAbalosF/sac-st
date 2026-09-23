<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankStatementImport;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Haberes\Enums\DepositTicketStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\DepositTicket;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Models\Attachment;
use App\Support\BusinessDate;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * El recorrido completo del comprobante: cargarlo, buscarlo, vincularlo.
 */
class PantallasDeDepositosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(HaberesDemoSeeder::class);
    }

    public function test_la_cola_arranca_vacia_y_abre(): void
    {
        $this->actingAs($this->operador())
            ->get(route('depositos.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('haberes/depositos/index')
                ->where('counts.waiting', 0)
                ->has('tickets.data', 0),
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Cargar el comprobante
    |--------------------------------------------------------------------------
    |
    | Se entra desde la cuota, y de la cuota sale todo lo que antes era un
    | campo. Lo que el formulario pregunta es lo que dice el papel.
    |
    */

    /**
     * La pantalla llega resuelta: quién, cuánto y de qué tipo.
     *
     * Es lo que reemplaza a los tres selects. Que el importe y el
     * beneficiario viajen no es un adorno: son lo que el operador coteja
     * contra el papel antes de guardar.
     */
    public function test_la_pantalla_trae_la_cuota_resuelta(): void
    {
        $this->cuenta();
        $cuota = $this->cuota();
        $haber = $cuota->haber;

        $this->actingAs($this->operador())
            ->get(route('haberes.installments.ticket.create', $cuota))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('haberes/depositos/create')
                ->where('cuota.id', $cuota->id)
                ->where('cuota.number', $cuota->installment_number)
                ->where('cuota.amount', $cuota->importeEsperado())
                ->where('haber.beneficiaryName', $haber->beneficiary->name)
                // El ordinal del haber, que es lo que la ruta resuelve: con
                // el id el enlace del aviso abriria otro haber.
                ->where('haber.number', $haber->haber_number)
                ->has('accounts', 1),
            );
    }

    /**
     * El tipo sale del medio de la cuota, no de un select.
     *
     * Un cheque depositado y un deposito por ventanilla no se buscan igual
     * en el extracto, asi que el dato importa; lo que ya no hace falta es
     * preguntarlo cuando la cuota lo dice.
     */
    public function test_el_tipo_sale_del_medio_de_la_cuota(): void
    {
        $this->cuenta();
        $cuota = $this->cuota();

        $cuota->forceFill(['expected_medium' => 'cheque'])->save();

        $this->actingAs($this->operador())
            ->get(route('haberes.installments.ticket.create', $cuota))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('cuota.medium', 'cheque')
                ->where('cuota.depositKind', 'Depósito de cheque'),
            );
    }

    /**
     * Cargar el comprobante lo deja esperando y lleva a buscarlo.
     *
     * No emite recibo ni financia nada: eso es la recepción, que llega
     * cuando el crédito aparece en el extracto.
     */
    public function test_registrar_un_comprobante_lo_deja_esperando(): void
    {
        $cuenta = $this->cuenta();
        $cuota = $this->cuota();

        $this->actingAs($this->operador())
            ->post(route('haberes.installments.ticket', $cuota), [
                'bankAccountId' => $cuenta->id,
                'depositedAt' => '2026-04-24',
                'depositedTime' => '09:36',
                'operationNumber' => '1053565801',
                'photo' => UploadedFile::fake()->image('ticket.jpg'),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $ticket = DepositTicket::query()->firstOrFail();

        $this->assertSame(DepositTicketStatus::Waiting, $ticket->status);
        $this->assertNull($ticket->bank_transaction_id);

        // Y la foto quedó como adjunto del ticket.
        $adjunto = Attachment::query()
            ->forSubject(AttachmentSubject::DepositTicket, $ticket->id)
            ->firstOrFail();

        $this->assertSame('deposit_ticket', $adjunto->document_type);
        $this->assertSame('scanned', $adjunto->source->value);
        Storage::disk('local')->assertExists($adjunto->object_key);
    }

    /**
     * El comprobante nace apuntando a la cuota, por su importe y su tipo.
     *
     * Nada de eso llega del formulario: lo arma el servidor desde la cuota
     * de la dirección. Es lo que hace imposible el ticket que dice una cosa
     * y cuelga de otra, que antes era un campo oculto de distancia.
     */
    public function test_el_comprobante_sale_entero_de_la_cuota(): void
    {
        $cuenta = $this->cuenta();
        $cuota = $this->cuota();

        $this->actingAs($this->operador())
            ->post(route('haberes.installments.ticket', $cuota), [
                'bankAccountId' => $cuenta->id,
                'depositedAt' => '2026-04-24',
                // Lo que el formulario ya no manda, por si alguien lo manda.
                'amount' => '1.00',
                'installmentId' => 99999,
                'depositKind' => 'transfer',
            ])
            ->assertSessionHasNoErrors();

        $ticket = DepositTicket::query()->firstOrFail();

        $this->assertSame($cuota->id, $ticket->beneficiary_installment_id);
        $this->assertSame($cuota->haber_id, $ticket->haber_id);
        $this->assertSame($cuota->haber->expediente_id, $ticket->expediente_id);
        $this->assertSame($cuota->importeEsperado(), $ticket->amount);
        $this->assertSame('cash_deposit', $ticket->deposit_kind->value);
    }

    /** La foto no es obligatoria: se advierte, no se traba. */
    public function test_se_puede_registrar_sin_foto(): void
    {
        $cuenta = $this->cuenta();

        $this->actingAs($this->operador())
            ->post(route('haberes.installments.ticket', $this->cuota()), [
                'bankAccountId' => $cuenta->id,
                'depositedAt' => '2026-04-24',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, DepositTicket::query()->count());
        $this->assertSame(0, Attachment::query()->count());
    }

    /** Un comprobante es de algo que ya pasó. */
    public function test_no_se_admite_una_fecha_futura(): void
    {
        $cuenta = $this->cuenta();

        $this->actingAs($this->operador())
            ->post(route('haberes.installments.ticket', $this->cuota()), [
                'bankAccountId' => $cuenta->id,
                'depositedAt' => BusinessDate::today()->addDay()->toDateString(),
            ])
            ->assertSessionHasErrors('depositedAt');
    }

    /** Cargado el comprobante, la cuota lo sabe y deja de ofrecer cargarlo. */
    public function test_la_cuota_muestra_el_comprobante_que_ya_tiene(): void
    {
        $cuenta = $this->cuenta();
        $cuota = $this->cuota();
        $haber = $cuota->haber;

        $this->actingAs($this->operador())
            ->post(route('haberes.installments.ticket', $cuota), [
                'bankAccountId' => $cuenta->id,
                'depositedAt' => '2026-04-24',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->operador())
            ->get(route('haberes.haber.show', [$this->expediente(), $haber]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('haber.installments.0.depositTicket.status', 'waiting')
                // Y viaja entero, que es lo que el modal necesita para
                // mostrarlo y corregirlo sin ir a buscarlo.
                ->where('haber.installments.0.depositTicket.editable', true)
                ->has('haber.installments.0.depositTicket.accountLabel'),
            );
    }

    public function test_la_pantalla_de_busqueda_dice_que_falta_importar(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->operador())
            ->get(route('depositos.search', $ticket))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('haberes/depositos/search')
                ->where('periodImported', false)
                ->has('candidates', 0)
                ->where(
                    'emptyReason',
                    fn (?string $razon): bool => str_contains((string) $razon, 'Importá el período'),
                ),
            );
    }

    public function test_quien_solo_consulta_no_registra_comprobantes(): void
    {
        $cuenta = $this->cuenta();

        $this->actingAs($this->operador('consulta'))
            ->post(route('haberes.installments.ticket', $this->cuota()), [
                'bankAccountId' => $cuenta->id,
                'depositedAt' => '2026-04-24',
            ])
            ->assertForbidden();
    }

    public function test_quien_carga_todos_los_dias_no_descarta(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->operador('administrativo'))
            ->patch(route('depositos.discard', $ticket), ['reason' => 'El depósito se anuló'])
            ->assertForbidden();
    }

    public function test_descartar_conserva_el_comprobante_y_su_motivo(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->operador('contador'))
            ->patch(route('depositos.discard', $ticket), [
                'reason' => 'El empleador anuló el depósito',
            ])
            ->assertRedirect();

        $ticket->refresh();

        $this->assertSame(DepositTicketStatus::Discarded, $ticket->status);
        $this->assertSame('El empleador anuló el depósito', $ticket->discarded_reason);
        // Sigue existiendo: que el expediente haya traído un papel que no
        // valía también es información.
        $this->assertSame(1, DepositTicket::query()->count());
    }

    public function test_descartar_exige_un_motivo(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->operador('contador'))
            ->patch(route('depositos.discard', $ticket), ['reason' => ''])
            ->assertSessionHasErrors('reason');
    }

    /*
    |--------------------------------------------------------------------------
    | Corregir lo transcripto
    |--------------------------------------------------------------------------
    |
    | El cruce exige importe exacto, así que un dígito mal tipeado hace que
    | el movimiento no aparezca nunca. El error se manifiesta como «el
    | depósito no está en el banco», que no señala su causa.
    |
    */

    public function test_se_corrige_un_importe_mal_tipeado(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->operador())
            ->post(route('depositos.update', $ticket), [
                ...$this->datosDe($ticket),
                'amount' => '682000.00',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('682000.00', $ticket->refresh()->amount);
    }

    /**
     * Corregir el importe no desvincula el comprobante de su cuota.
     *
     * El modal de la cuota manda solo lo que se corrige —ahí el haber y
     * la cuota ya se saben— y una lectura descuidada de eso los anulaba
     * en silencio: el comprobante quedaba huérfano y la cuota volvía a
     * ofrecer cargarlo, con el papel ya cargado.
     */
    public function test_corregir_no_desvincula_el_comprobante_de_su_cuota(): void
    {
        $haber = $this->expediente()->haberes()->firstOrFail();
        $cuota = $haber->installments()->firstOrFail();

        $ticket = $this->ticket();
        $ticket->forceFill([
            'haber_id' => $haber->id,
            'beneficiary_installment_id' => $cuota->id,
        ])->save();

        $this->actingAs($this->operador())
            ->post(route('depositos.update', $ticket), [
                // Sin haberId ni installmentId: es lo que manda el modal.
                ...$this->datosDe($ticket),
                'amount' => '682000.00',
            ])
            ->assertSessionHasNoErrors();

        $ticket->refresh();

        $this->assertSame('682000.00', $ticket->amount);
        $this->assertSame($haber->id, $ticket->haber_id);
        $this->assertSame($cuota->id, $ticket->beneficiary_installment_id);
    }

    /**
     * La hora vuelve sin segundos.
     *
     * PostgreSQL devuelve `10:32:00` para un `time`, y ese formato no lo
     * acepta la validación del alta —`H:i`—. Sin recortarlo, corregir
     * cualquier otro campo arrastraría un error en uno que nadie tocó.
     */
    public function test_la_hora_del_comprobante_viaja_sin_segundos(): void
    {
        $ticket = $this->ticket();
        $ticket->forceFill(['deposited_time' => '10:32'])->save();

        $haber = $this->expediente()->haberes()->firstOrFail();
        $cuota = $haber->installments()->firstOrFail();
        $ticket->forceFill(['beneficiary_installment_id' => $cuota->id, 'haber_id' => $haber->id])->save();

        $this->actingAs($this->operador())
            ->get(route('haberes.haber.show', [$this->expediente(), $haber]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('haber.installments.0.depositTicket.depositedTime', '10:32'),
            );
    }

    /** Vinculado, sus datos son la base de una afirmación ya hecha. */
    public function test_un_comprobante_vinculado_no_se_corrige(): void
    {
        $ticket = $this->ticket();
        $movimiento = $this->movimiento($ticket);

        $this->actingAs($this->operador())
            ->post(route('depositos.link', $ticket), ['bankTransactionId' => $movimiento->id])
            ->assertRedirect();

        $this->assertSame(DepositTicketStatus::Matched, $ticket->refresh()->status);

        $this->actingAs($this->operador())
            ->followingRedirects()
            ->post(route('depositos.update', $ticket), [
                ...$this->datosDe($ticket),
                'amount' => '999.00',
            ])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->hasFlash('toast.type', 'error')
                ->hasFlash('toast.message'),
            );

        // El importe no se movió.
        $this->assertSame('72000.00', $ticket->refresh()->amount);
    }

    /** Desvinculado vuelve a ser corregible: es el camino que la pantalla indica. */
    public function test_desvincular_devuelve_la_posibilidad_de_corregir(): void
    {
        $ticket = $this->ticket();
        $movimiento = $this->movimiento($ticket);

        $this->actingAs($this->operador())
            ->post(route('depositos.link', $ticket), ['bankTransactionId' => $movimiento->id]);

        $this->actingAs($this->operador())
            ->patch(route('depositos.unlink', $ticket), ['reason' => 'El importe estaba mal copiado.']);

        $this->actingAs($this->operador())
            ->post(route('depositos.update', $ticket), [
                ...$this->datosDe($ticket->refresh()),
                'amount' => '682000.00',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('682000.00', $ticket->refresh()->amount);
    }

    /** El expediente no se muda con una corrección, aunque llegue en el formulario. */
    public function test_corregir_no_cambia_el_expediente(): void
    {
        $ticket = $this->ticket();
        $original = $ticket->expediente_id;

        $otro = Expediente::query()
            ->whereNotNull('employer_id')
            ->where('id', '!=', $original)
            ->firstOrFail();

        $this->actingAs($this->operador())
            ->post(route('depositos.update', $ticket), [
                ...$this->datosDe($ticket),
                'expedienteId' => $otro->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($original, $ticket->refresh()->expediente_id);
    }

    public function test_quien_solo_consulta_no_corrige(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->operador('consulta'))
            ->post(route('depositos.update', $ticket), $this->datosDe($ticket))
            ->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | La lista de respaldo
    |--------------------------------------------------------------------------
    */

    /** Sin pedirla no se arma: la pantalla normal no la necesita. */
    public function test_los_creditos_disponibles_no_se_traen_sin_pedirlos(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->operador())
            ->get(route('depositos.search', $ticket))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('available', null)
                ->where('showingAvailable', false),
            );
    }

    /**
     * Pedida, aparece el crédito real aunque el importe del ticket no
     * coincida — que es justamente el caso que la lista resuelve.
     */
    public function test_los_creditos_disponibles_muestran_el_que_el_cruce_no_encontro(): void
    {
        $ticket = $this->ticket();
        // El banco dice $682.000; el ticket quedó cargado en $72.000.
        $real = $this->movimiento($ticket, '682000.00');

        $this->actingAs($this->operador())
            ->get(route('depositos.search', ['ticket' => $ticket, 'todos' => 1]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('showingAvailable', true)
                ->where('available.data.0.id', $real->id),
            );
    }

    /** Lo que quedó fuera del circuito no vuelve a ofrecerse. */
    public function test_los_creditos_disponibles_excluyen_los_ignorados(): void
    {
        $ticket = $this->ticket();
        $movimiento = $this->movimiento($ticket, '500000.00');

        $this->actingAs($this->operador('contador'))
            ->patch(route('banco.movimientos.ignore', $movimiento), [
                'reason' => 'Movimiento entre cuentas del ministerio.',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->operador())
            ->get(route('depositos.search', ['ticket' => $ticket, 'todos' => 1]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('available.data', 0));
    }

    /** Y lo que ya tiene otro comprobante, tampoco. */
    public function test_los_creditos_disponibles_excluyen_los_de_otro_ticket(): void
    {
        $ticket = $this->ticket();
        $movimiento = $this->movimiento($ticket, '72000.00');

        $otroTicket = $this->ticket();

        $this->actingAs($this->operador())
            ->post(route('depositos.link', $otroTicket), ['bankTransactionId' => $movimiento->id])
            ->assertRedirect();

        $this->actingAs($this->operador())
            ->get(route('depositos.search', ['ticket' => $ticket, 'todos' => 1]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('available.data', 0));
    }

    /**
     * El orden por defecto es la cercanía de importe.
     *
     * Con un dígito mal tipeado, el movimiento correcto difiere en pocos
     * pesos y tiene que quedar primero, por encima de uno más reciente.
     */
    public function test_los_creditos_disponibles_ordenan_por_cercania(): void
    {
        $ticket = $this->ticket();

        // Lejos en importe, pero el más reciente.
        $this->movimiento($ticket, '5000000.00', '2026-06-01');
        // A quinientos pesos del ticket, y más viejo.
        $cercano = $this->movimiento($ticket, '72500.00', '2026-04-20');

        $this->actingAs($this->operador())
            ->get(route('depositos.search', ['ticket' => $ticket, 'todos' => 1]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('available.data.0.id', $cercano->id),
            );
    }

    private function expediente(): Expediente
    {
        return Expediente::query()->whereNotNull('employer_id')->orderBy('id')->firstOrFail();
    }

    /** La cuota desde la que se carga un comprobante. */
    private function cuota(): BeneficiaryInstallment
    {
        return $this->expediente()
            ->haberes()->orderBy('id')->firstOrFail()
            ->installments()->orderBy('installment_number')->firstOrFail();
    }

    /**
     * Los datos actuales del ticket, para reenviarlos con una corrección.
     *
     * @return array<string, mixed>
     */
    private function datosDe(DepositTicket $ticket): array
    {
        return [
            'bankAccountId' => $ticket->bank_account_id,
            'depositedAt' => $ticket->deposited_at->toDateString(),
            'amount' => $ticket->amount,
            'depositKind' => $ticket->deposit_kind->value,
        ];
    }

    private function movimiento(
        DepositTicket $ticket,
        ?string $importe = null,
        string $fecha = '2026-04-24',
    ): BankTransaction {
        return BankTransaction::query()->create([
            'bank_account_id' => $ticket->bank_account_id,
            'first_seen_import_id' => $this->importacion($ticket),
            'transaction_date' => $fecha,
            'amount' => $importe ?? $ticket->amount,
            'direction' => TransactionDirection::Credit,
            'description' => 'Deposito en efectivo',
            'fingerprint' => hash('sha256', uniqid('', true)),
        ]);
    }

    private function importacion(DepositTicket $ticket): int
    {
        // El sha va con formato real: la tabla lo verifica con un CHECK.
        return (int) BankStatementImport::query()->firstOrCreate(
            ['file_sha256' => hash('sha256', 'respaldo-de-prueba')],
            [
                'bank_account_id' => $ticket->bank_account_id,
                'imported_by' => $this->operador()->id,
                'original_filename' => 'extracto.csv',
                'file_size' => 100,
                'source_format' => 'macro_online_csv',
                'parser_version' => 'macro-csv-1',
                'period_from' => '2026-04-01',
                'period_to' => '2026-06-30',
                'status' => 'completed',
                'rows_total' => 1,
                'rows_valid' => 1,
                'rows_rejected' => 0,
                'rows_new' => 1,
                'rows_duplicate' => 0,
                'imported_at' => now(),
            ],
        )->id;
    }

    private function cuenta(): BankAccount
    {
        return BankAccount::query()->firstOrCreate(
            ['account_number' => '310000123456789'],
            [
                'label' => 'Cta. Cte. 2693 — Haberes',
                'bank_name' => 'Banco Macro',
                'currency' => 'ARS',
                'is_active' => true,
            ],
        );
    }

    private function ticket(): DepositTicket
    {
        return DepositTicket::query()->create([
            'expediente_id' => $this->expediente()->id,
            'bank_account_id' => $this->cuenta()->id,
            'deposited_at' => '2026-04-24',
            'amount' => '72000.00',
            'deposit_kind' => 'cash_deposit',
            'status' => DepositTicketStatus::Waiting,
        ]);
    }
}
