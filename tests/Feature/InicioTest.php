<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Banking\Actions\ConfirmCashDepositCredit;
use App\Modules\Banking\Enums\ReconciliationStatus;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankStatementImport;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Haberes\Actions\CollectAndIssueReceipt;
use App\Modules\Haberes\Actions\DepositCashToBank;
use App\Modules\Haberes\Enums\DepositTicketStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\DepositTicket;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Support\CashMonthsPendingClosing;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Ledger\Support\RecentMovements;
use App\Modules\Shared\Enums\LoginEventType;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Models\PersonBankAccount;
use App\Modules\Shared\Models\UserLoginEvent;
use App\Support\Ui\Toast;
use Carbon\CarbonImmutable;
use Database\Seeders\CashBoxSeeder;
use Database\Seeders\DocumentSeriesSeeder;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * El tablero de inicio.
 *
 * Dos cosas se prueban acá y ninguna es cosmética. La primera es **qué
 * significa cada número**: una cola cuenta casos que esperan una acción, y
 * contar de más —un débito entre los fondos por identificar, una cuota de
 * mostrador entre las que esperan Orden— manda a alguien a buscar trabajo
 * que no existe.
 *
 * La segunda es **qué llega cuándo**: las colas y los saldos viajan
 * diferidos, así que la primera respuesta no puede traerlos y la segunda
 * sí. Si eso se rompiera, la pantalla mostraría el esqueleto para siempre
 * sin que nada falle a la vista.
 */
class InicioTest extends TestCase
{
    use RefreshDatabase;

    private const CBU_VALIDO = '2850000300000000000017';

    public function test_los_invitados_van_al_login(): void
    {
        $this->get(route('inicio'))->assertRedirect(route('login'));
    }

    public function test_la_ruta_vieja_del_tablero_ya_no_existe(): void
    {
        $this->actingAs($this->operador())
            ->get('/dashboard')
            ->assertNotFound();
    }

    public function test_las_confirmaciones_legacy_llegan_como_toast_y_no_como_prop(): void
    {
        $this->actingAs($this->operador());
        session()->flash('status', 'Traslado cancelado.');

        $this->get(route('inicio'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->hasFlash('toast', [
                    'type' => 'success',
                    'message' => 'Traslado cancelado.',
                ])
                ->missing('flash.status'),
            );
    }

    /**
     * Salvo donde la pantalla ya lo dice ella misma.
     *
     * Fortify le pasa el `status` a su vista como prop y el formulario lo
     * dibuja adentro de la tarjeta. Si el puente además lo tostara, «te
     * enviamos el enlace» saldría dos veces por el mismo pedido.
     */
    public function test_las_pantallas_de_acceso_no_duplican_su_propio_aviso(): void
    {
        session()->flash('status', 'Te enviamos un enlace para recuperar la contraseña.');

        $this->get(route('password.request'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/forgot-password')
                ->where('status', 'Te enviamos un enlace para recuperar la contraseña.')
                ->missingFlash('toast'),
            );
    }

    /**
     * Un toast tipado le gana al puente, con su segundo renglón intacto.
     *
     * El puente no puede saber el tono —todo `status` es una confirmación
     * verde— ni dónde parte la oración: «Cuenta «Cta. Cte. 2693» registrada.»
     * tiene cuatro puntos y ninguno cierra la idea. Por eso lo que el
     * controlador dice explícitamente tiene que sobrevivir entero.
     */
    public function test_un_toast_tipado_no_lo_pisa_el_puente_legacy(): void
    {
        $this->actingAs($this->operador());
        session()->flash('status', 'Cuenta rechazada. No vuelve a ofrecerse para pagar.');
        Toast::warning('Cuenta rechazada.', 'No vuelve a ofrecerse para pagar.');

        $this->get(route('inicio'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->hasFlash('toast', [
                    'type' => 'warning',
                    'message' => 'Cuenta rechazada.',
                    'description' => 'No vuelve a ofrecerse para pagar.',
                ]),
            );
    }

    /*
    |---------------------------------------------------------------------
    | Qué llega cuándo
    |---------------------------------------------------------------------
    */

    /**
     * Lo que la pantalla necesita para dibujarse llega en la primera
     * respuesta; lo caro, no.
     */
    public function test_la_primera_respuesta_no_trae_las_props_diferidas(): void
    {
        $this->actingAs($this->operador())
            ->get(route('inicio'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('inicio')
                ->has('operationalDate')
                ->has('can')
                ->has('recentAccess')
                ->missing('queues')
                ->missing('cashDay')
                ->missing('cashBoxes')
                ->missing('movements')
            );
    }

    public function test_las_colas_llegan_en_la_recarga_parcial(): void
    {
        // La caja de Haberes es una fila de sistema: sin ella el bloque
        // entero se rescata en `null` y no hay colas que contar.
        $this->seed(CashBoxSeeder::class);

        $this->colas()->assertJsonPath('component', 'inicio');

        /*
         * El único lugar donde el orden es el sujeto, y por eso se afirma
         * entero: es el orden del circuito, y moverlo tiene que fallar acá
         * y no en las diez pruebas que hablan de una cola en particular.
         */
        $this->assertSame([
            'comprobantes_sin_cruzar',
            'fondos_sin_identificar',
            'cuotas_sin_orden',
            'ordenes_en_saf',
            'egresos_por_validar',
            'beneficiarios_sin_cbu',
            'caja_sin_cerrar',
            'caja_mes_sin_cerrar',
        ], $this->clavesDeColas());
    }

    /**
     * El mes terminado que nadie cerró tiene su propio recordatorio.
     *
     * Cerrar el mes no es automático ni debería serlo --es un acto
     * contable, no un vencimiento de calendario-- pero tampoco puede
     * depender de que alguien se acuerde.
     *
     * Va como cola aparte de los días: son dos trabajos distintos, y el
     * mensual exige que los días ya estén cerrados.
     */
    public function test_el_mes_terminado_sin_cerrar_aparece_en_el_tablero(): void
    {
        $this->seed(CashBoxSeeder::class);

        $caja = (int) CashBox::query()->where('code', CashBox::HABERES)->value('id');
        $mesPasado = CarbonImmutable::now()->subMonthNoOverflow()->startOfMonth()->addDays(4);

        app(PostJournalEntry::class)->handle(
            type: FinancialEventType::FundsReceived,
            idempotencyKey: 'cobro-del-mes-pasado',
            lines: [
                EntryLine::debit(LedgerAccount::CashOnHand, '50000.00')->onCashBox($caja),
                EntryLine::credit(LedgerAccount::UnassignedFunds, '50000.00')->onCashBox($caja),
            ],
            date: $mesPasado,
            cashBoxId: $caja,
        );

        $this->assertSame(1, $this->cola('caja_mes_sin_cerrar')['count']);
    }

    /** El mes en curso no está atrasado: todavía no terminó. */
    public function test_el_mes_en_curso_no_cuenta_como_pendiente(): void
    {
        $this->seed(CashBoxSeeder::class);

        $caja = (int) CashBox::query()->where('code', CashBox::HABERES)->value('id');

        app(PostJournalEntry::class)->handle(
            type: FinancialEventType::FundsReceived,
            idempotencyKey: 'cobro-de-este-mes',
            lines: [
                EntryLine::debit(LedgerAccount::CashOnHand, '50000.00')->onCashBox($caja),
                EntryLine::credit(LedgerAccount::UnassignedFunds, '50000.00')->onCashBox($caja),
            ],
            date: CarbonImmutable::now(),
            cashBoxId: $caja,
        );

        $this->assertSame(0, $this->cola('caja_mes_sin_cerrar')['count']);
    }

    public function test_los_meses_pendientes_se_cuentan_por_moneda(): void
    {
        $this->seed(CashBoxSeeder::class);

        $caja = CashBox::query()->where('code', CashBox::HABERES)->firstOrFail();
        $fecha = CarbonImmutable::now()->subMonthNoOverflow()->startOfMonth()->addDay();

        app(PostJournalEntry::class)->handle(
            type: FinancialEventType::FundsReceived,
            idempotencyKey: 'cobro-usd-del-mes-pasado',
            lines: [
                EntryLine::debit(LedgerAccount::CashOnHand, '100.00')
                    ->in(Currency::Usd)
                    ->onCashBox((int) $caja->id),
                EntryLine::credit(LedgerAccount::UnassignedFunds, '100.00')
                    ->in(Currency::Usd)
                    ->onCashBox((int) $caja->id),
            ],
            date: $fecha,
            cashBoxId: (int) $caja->id,
        );

        $pendientes = app(CashMonthsPendingClosing::class);

        $this->assertSame(0, $pendientes->count((int) $caja->id, now(), Currency::Ars));
        $this->assertSame(1, $pendientes->count((int) $caja->id, now(), Currency::Usd));
    }

    public function test_el_estado_de_la_caja_llega_en_su_propio_grupo(): void
    {
        $this->seed(CashBoxSeeder::class);

        $this->colas('cashDay,cashBoxes')
            ->assertJsonPath('props.cashDay.balances.code', 'haberes')
            ->assertJsonCount(3, 'props.cashBoxes')
            ->assertJsonPath('props.cashBoxes.0.code', 'haberes')
            // Las otras dos cajas no tienen módulo: `null`, no cero.
            ->assertJsonPath('props.cashBoxes.1.count', null);
    }

    public function test_un_usuario_sin_permisos_no_recibe_datos_operativos_diferidos(): void
    {
        $this->seed(CashBoxSeeder::class);
        $usuario = User::factory()->create();

        $this->recargaParcial($usuario, 'queues,cashDay,cashBoxes,movements')
            ->assertJsonCount(0, 'props.queues')
            ->assertJsonPath('props.cashDay', null)
            ->assertJsonCount(0, 'props.cashBoxes')
            ->assertJsonCount(0, 'props.movements');
    }

    public function test_los_movimientos_recientes_se_ordenan_por_registracion_y_no_por_fecha_operativa(): void
    {
        $this->seed(CashBoxSeeder::class);
        $caja = CashBox::query()->where('code', CashBox::HABERES)->firstOrFail();

        try {
            CarbonImmutable::setTestNow('2026-09-08 09:00:00');
            $this->asentarMovimiento($caja, '2026-09-07', 'Registrado primero');

            CarbonImmutable::setTestNow('2026-09-08 10:00:00');
            $this->asentarMovimiento($caja, '2026-08-01', 'Registrado último');

            $movimientos = app(RecentMovements::class)->latest();

            $this->assertSame('Registrado último', $movimientos[0]->description);
            $this->assertSame('2026-08-01', $movimientos[0]->eventDate);
            $this->assertStringContainsString('2026-09-08T10:00:00', $movimientos[0]->recordedAt);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    /*
    |---------------------------------------------------------------------
    | Qué cuenta cada cola
    |---------------------------------------------------------------------
    */

    /**
     * Ninguna cola se calcula para no dibujarse.
     *
     * `GRUPOS_DE_COLAS` en `inicio.tsx` es una lista blanca: la pantalla
     * recorre los grupos y filtra las colas por clave, así que una clave
     * que no figura en ninguno **desaparece sin que nada falle**. Las
     * props viajan, el test de props pasa y el tablero no la muestra.
     *
     * Pasó con dos a la vez —«Comprobantes sin cruzar» y «Meses sin
     * cerrar»—, y ninguna de las dos prueba que las contaba se enteró.
     */
    public function test_toda_cola_del_tablero_tiene_donde_dibujarse(): void
    {
        $this->seed(CashBoxSeeder::class);

        $pantalla = (string) file_get_contents(resource_path('js/pages/inicio.tsx'));
        $desde = strpos($pantalla, 'const GRUPOS_DE_COLAS = [');
        $hasta = strpos($pantalla, '] as const;', $desde === false ? 0 : $desde);

        $this->assertIsInt($desde, 'No se encontró `GRUPOS_DE_COLAS` en inicio.tsx.');
        $this->assertIsInt($hasta);

        $grupos = substr($pantalla, $desde, $hasta - $desde);
        $claves = $this->clavesDeColas();

        $this->assertNotEmpty($claves);

        foreach ($claves as $clave) {
            $this->assertStringContainsString(
                "'{$clave}'",
                $grupos,
                "La cola «{$clave}» se calcula y no está en ningún grupo de inicio.tsx: no se dibuja.",
            );
        }
    }

    /**
     * El comprobante cargado que todavía no cruzó contra el extracto.
     *
     * Era el único estado pendiente del circuito sin cola: el papel llega
     * con el expediente y el movimiento aparece días después, así que
     * quien cargó ocho comprobantes de ocho expedientes distintos no tenía
     * forma de barrerlos salvo acordándose de cada uno.
     */
    public function test_los_comprobantes_sin_cruzar_cuentan_los_que_esperan(): void
    {
        $this->seed(CashBoxSeeder::class);
        $this->seedHaberes();

        $this->comprobante(DepositTicketStatus::Waiting);
        $this->comprobante(DepositTicketStatus::Waiting);
        // Cruzado y descartado dejaron de esperar a nadie.
        $this->comprobante(DepositTicketStatus::Matched);
        $this->comprobante(DepositTicketStatus::Discarded);

        $cola = $this->cola('comprobantes_sin_cruzar');

        $this->assertSame(2, $cola['count']);
        // El enlace lleva al listado filtrado por la misma condición que
        // produjo el número.
        $this->assertSame('/depositos?estado=waiting', $cola['href']);
    }

    /** Sin comprobantes esperando, la cola no pide atención. */
    public function test_sin_comprobantes_esperando_la_cola_queda_saldada(): void
    {
        $this->seed(CashBoxSeeder::class);

        $cola = $this->cola('comprobantes_sin_cruzar');

        $this->assertSame(0, $cola['count']);
        $this->assertSame('done', $cola['tone']);
    }

    /**
     * Un crédito sin conciliar es plata de alguien que todavía no se sabe
     * de quién es. Un débito no es un fondo que entre, y uno ya conciliado
     * dejó de esperar a nadie.
     */
    public function test_los_fondos_sin_identificar_cuentan_creditos_pendientes(): void
    {
        $this->seed(CashBoxSeeder::class);

        $this->movimiento(TransactionDirection::Credit, ReconciliationStatus::Pending);
        $this->movimiento(TransactionDirection::Credit, ReconciliationStatus::Pending);
        $this->movimiento(TransactionDirection::Credit, ReconciliationStatus::Reconciled);
        // `partial` ya tiene una recepcion empezada: alguien lo esta mirando.
        $this->movimiento(TransactionDirection::Credit, ReconciliationStatus::Partial);
        $this->movimiento(TransactionDirection::Debit, ReconciliationStatus::Pending);

        $cola = $this->cola('fondos_sin_identificar');

        $this->assertSame(2, $cola['count']);
        // El enlace lleva al listado filtrado con el mismo par de
        // condiciones que produjo el número.
        $this->assertSame(
            '/banco/movimientos?estado=pending&sentido=credit',
            $cola['href'],
        );
    }

    /**
     * Una cola en cero no es una cola en rojo: no hay nada pendiente y eso
     * es una buena noticia, no una alarma.
     */
    public function test_una_cola_vacia_deja_de_pedir_atencion(): void
    {
        $this->seed(CashBoxSeeder::class);

        $cola = $this->cola('fondos_sin_identificar');

        $this->assertSame(0, $cola['count']);
        $this->assertSame('done', $cola['tone']);
    }

    /**
     * La cuota cobrada por mostrador **no** espera ninguna Orden: el §2.4.6
     * separa cómo entró el dinero de cómo sale, y el efectivo que sigue en
     * la caja se paga en mano. Contarla sería reclamar un papel que no
     * corresponde emitir.
     */
    public function test_la_cuota_de_mostrador_no_entra_en_las_cuotas_sin_orden(): void
    {
        $this->seedHaberes();
        $this->cuotaCobradaEnEfectivo();

        $colas = $this->colas();

        $this->assertSame(0, $this->cola('cuotas_sin_orden', $colas)['count']);
        $this->assertSame(0, $this->cola('beneficiarios_sin_cbu', $colas)['count']);
    }

    /**
     * La otra cara: la cuota que sí lleva Orden y no tiene el CBU
     * verificado no puede emitirla, y esa es una cola distinta de «se
     * puede emitir y nadie lo hizo».
     */
    public function test_la_cuota_por_transferencia_sin_cbu_queda_en_su_cola(): void
    {
        $this->seedHaberes();

        $cuota = $this->cuotaAcreditadaEnElBanco();

        $sinCbu = $this->cola('beneficiarios_sin_cbu');

        $this->assertSame(1, $sinCbu['count']);
        // Sin listado que las filtre, la cola ofrece el caso concreto.
        $this->assertCount(1, $sinCbu['samples']);
        $this->assertSame('sin CBU verificado', $sinCbu['samples'][0]['detail']);

        $this->cuentaVerificada($cuota);

        /*
         * Verificado el CBU, la misma cuota cambia de cola sin que nadie
         * la toque: pasa de «no se puede» a «se puede y falta hacerlo».
         */
        $colas = $this->colas();

        $this->assertSame(1, $this->cola('cuotas_sin_orden', $colas)['count']);
        $this->assertSame(0, $this->cola('beneficiarios_sin_cbu', $colas)['count']);
    }

    /*
    |---------------------------------------------------------------------
    | Actividad de la propia cuenta
    |---------------------------------------------------------------------
    */

    public function test_muestra_los_cinco_accesos_mas_recientes_de_tu_cuenta(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 7) as $i) {
            UserLoginEvent::query()->create([
                'user_id' => $user->id,
                'username_attempted' => $user->username,
                'event_type' => LoginEventType::LoginSuccess,
                'ip_address' => '10.0.0.'.$i,
                'created_at' => now()->subMinutes(8 - $i),
            ]);
        }

        $this->actingAs($user)
            ->get(route('inicio'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('recentAccess', 5)
                // El más reciente primero.
                ->where('recentAccess.0.ipAddress', '10.0.0.7')
                ->where('recentAccess.0.label', 'Ingreso')
            );
    }

    public function test_nunca_ves_la_actividad_de_otro(): void
    {
        $user = User::factory()->create();
        $otro = User::factory()->create();

        UserLoginEvent::query()->create([
            'user_id' => $otro->id,
            'username_attempted' => $otro->username,
            'event_type' => LoginEventType::LoginSuccess,
            'ip_address' => '192.168.1.1',
            'created_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('inicio'))
            ->assertInertia(fn (Assert $page) => $page->has('recentAccess', 0));
    }

    /*
    |---------------------------------------------------------------------
    | Andamiaje
    |---------------------------------------------------------------------
    */

    /**
     * Pide el tablero como lo pide el navegador cuando llegan las props
     * diferidas: una recarga parcial del mismo componente.
     */
    private function colas(string $only = 'queues'): TestResponse
    {
        return $this->recargaParcial($this->operador('contador'), $only);
    }

    /**
     * Una cola del tablero, buscada por su clave.
     *
     * Afirmar por posición ataba cada prueba al orden del tablero: agregar
     * una cola en el medio corría a todas las que seguían y diez
     * aserciones pasaban a hablar de otra cola **sin fallar primero**, que
     * es la peor forma de romperse. La clave no se mueve.
     *
     * @return array<string, mixed>
     */
    private function cola(string $clave, ?TestResponse $respuesta = null): array
    {
        /** @var array<int, array<string, mixed>> $colas */
        $colas = ($respuesta ?? $this->colas())->json('props.queues') ?? [];

        foreach ($colas as $cola) {
            if (($cola['key'] ?? null) === $clave) {
                return $cola;
            }
        }

        $this->fail("El tablero no trae la cola «{$clave}».");
    }

    /**
     * Las claves del tablero, en orden.
     *
     * @return list<string>
     */
    private function clavesDeColas(): array
    {
        /** @var array<int, array<string, mixed>> $colas */
        $colas = $this->colas()->json('props.queues') ?? [];

        /** @var list<string> $claves */
        $claves = array_values(array_map(
            fn (array $cola): string => (string) $cola['key'],
            $colas,
        ));

        return $claves;
    }

    private function recargaParcial(User $usuario, string $only): TestResponse
    {

        /*
         * La version sale de la primera respuesta y no de
         * `Inertia::getVersion()`: el middleware la calcula por request a
         * partir del manifiesto de assets, asi que fuera del ciclo todavia
         * no existe. Sin ella Inertia contesta 409 —«recarga entero»— y la
         * recarga parcial nunca llega a correr.
         */
        $version = $this->actingAs($usuario)
            ->get(route('inicio'))
            ->assertOk()
            ->viewData('page')['version'];

        return $this->actingAs($usuario)
            ->get(route('inicio'), [
                'X-Inertia' => 'true',
                'X-Inertia-Partial-Component' => 'inicio',
                'X-Inertia-Partial-Data' => $only,
                'X-Inertia-Version' => $version,
            ])
            ->assertOk();
    }

    private function asentarMovimiento(CashBox $caja, string $fecha, string $descripcion): void
    {
        app(PostJournalEntry::class)->handle(
            type: FinancialEventType::FundsReceived,
            idempotencyKey: 'inicio-'.Str::random(12),
            lines: [
                EntryLine::debit(LedgerAccount::CashOnHand, '1000.00')->onCashBox((int) $caja->id),
                EntryLine::credit(LedgerAccount::UnassignedFunds, '1000.00')->onCashBox((int) $caja->id),
            ],
            date: CarbonImmutable::parse($fecha),
            cashBoxId: (int) $caja->id,
            description: $descripcion,
        );
    }

    private function seedHaberes(): void
    {
        $this->seed(HaberesDemoSeeder::class);
        $this->seed(CashBoxSeeder::class);
        $this->seed(DocumentSeriesSeeder::class);
    }

    /** Cobrada por mostrador: el efectivo sigue en la caja. */
    private function cuotaCobradaEnEfectivo(): BeneficiaryInstallment
    {
        $cuota = $this->primeraCuota();
        $cuota->forceFill(['expected_medium' => 'cash'])->save();

        app(CollectAndIssueReceipt::class)->handle(
            installment: $cuota->refresh(),
            idempotencyKey: 'cobro-'.Str::random(8),
            actorId: $this->operador()->id,
        );

        return $cuota->refresh();
    }

    /**
     * El circuito completo del efectivo que nadie retiró (§2.4.7).
     *
     * La cuota entró en efectivo, la contadora lo depositó y el banco lo
     * acreditó: sin que nadie la toque, pasa de mostrador a transferencia
     * y empieza a pedir Orden. Es el camino más corto para tener una
     * cuota del canal `transfer` sin escribir filas a mano.
     */
    private function cuotaAcreditadaEnElBanco(): BeneficiaryInstallment
    {
        $cuota = $this->cuotaCobradaEnEfectivo();
        $this->completarDatosDelBeneficiario($cuota);

        $traslado = app(DepositCashToBank::class)->handle(
            installment: $cuota,
            ticket: [
                'bankAccountId' => $this->cuentaDelOrganismo()->id,
                'depositDate' => now()->parse('2026-05-28'),
                'depositTime' => '10:35:00',
                'operationNumber' => '995979830',
                'terminal' => 'T-014',
                'notes' => null,
            ],
            foto: UploadedFile::fake()->image('ticket.jpg'),
            idempotencyKey: 'traslado-'.Str::random(8),
            actorId: $this->operador()->id,
        );

        app(ConfirmCashDepositCredit::class)->handle(
            transfer: $traslado,
            transaction: $this->movimiento(
                TransactionDirection::Credit,
                ReconciliationStatus::Pending,
                $traslado->amount,
            ),
            idempotencyKey: 'acreditacion-'.Str::random(8),
            actorId: $this->operador()->id,
        );

        return $cuota->refresh();
    }

    private function primeraCuota(): BeneficiaryInstallment
    {
        $expediente = Expediente::query()
            ->whereNotNull('employer_id')
            ->orderBy('id')
            ->firstOrFail();

        $expediente->forceFill(['received_date' => '2026-05-15'])->save();

        return $expediente->haberes()->orderBy('id')->firstOrFail()
            ->installments()->orderBy('installment_number')->firstOrFail();
    }

    private function completarDatosDelBeneficiario(BeneficiaryInstallment $cuota): void
    {
        $cuota->loadMissing('haber.beneficiary');

        $cuota->haber->beneficiary->forceFill([
            'document' => '38.357.040',
            'address' => 'B° San Jorge Mza 54',
            'phone' => '387-6004216',
        ])->save();
    }

    private function cuentaVerificada(BeneficiaryInstallment $cuota): PersonBankAccount
    {
        $cuota->loadMissing('haber.beneficiary');

        return PersonBankAccount::query()->create([
            'person_id' => $cuota->haber->beneficiary->id,
            'cbu' => self::CBU_VALIDO,
            'bank_name' => 'Banco Macro',
            'verification_status' => 'verified',
            'verified_by' => $this->operador()->id,
            'verified_at' => now(),
            'is_active' => true,
        ]);
    }

    /**
     * Un comprobante en el estado pedido, con lo que ese estado exige.
     *
     * La base ata las dos cosas: el cruzado no existe sin su movimiento y
     * el descartado no existe sin su motivo —descartarlo es afirmar que
     * ese depósito no va a aparecer nunca—.
     */
    private function comprobante(DepositTicketStatus $estado): DepositTicket
    {
        $cruce = $estado === DepositTicketStatus::Matched
            ? [
                'bank_transaction_id' => $this->movimiento(
                    TransactionDirection::Credit,
                    ReconciliationStatus::Reconciled,
                )->id,
                'matched_at' => now(),
                'matched_by' => $this->operador()->id,
            ]
            : [];

        return DepositTicket::query()->create([
            'expediente_id' => Expediente::query()->firstOrFail()->id,
            'bank_account_id' => $this->cuentaDelOrganismo()->id,
            'deposited_at' => '2026-04-24',
            'amount' => '72000.00',
            'deposit_kind' => 'cash_deposit',
            'status' => $estado,
            'discarded_reason' => $estado === DepositTicketStatus::Discarded
                ? 'El empleador avisó que el depósito nunca se hizo.'
                : null,
            ...$cruce,
        ]);
    }

    private function cuentaDelOrganismo(): BankAccount
    {
        return BankAccount::query()->firstOrCreate(
            ['account_number' => '23456789'],
            [
                'label' => 'Cta. Cte. 2693 — Haberes en consignación',
                'bank_name' => 'Banco Macro',
                'currency' => 'ARS',
                'is_active' => true,
            ],
        );
    }

    private function movimiento(
        TransactionDirection $direction,
        ReconciliationStatus $status,
        string $amount = '1000.00',
    ): BankTransaction {
        $cuenta = $this->cuentaDelOrganismo();

        return BankTransaction::query()->create([
            'bank_account_id' => $cuenta->id,
            'first_seen_import_id' => $this->importacion($cuenta)->id,
            'transaction_date' => '2026-05-28',
            'amount' => $amount,
            'direction' => $direction,
            'reconciliation_status' => $status,
            'description' => 'Deposito en efectivo',
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
            'period_from' => '2026-05-28',
            'period_to' => '2026-05-28',
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
