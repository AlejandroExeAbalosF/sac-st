<?php

declare(strict_types=1);

namespace Tests\Feature\Configuracion;

use App\Models\User;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Shared\Models\AuditEvent;
use App\Modules\Shared\Support\OperationAuditQuery;
use App\Support\Excel\SpreadsheetReader;
use Carbon\CarbonImmutable;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * La auditoría de operaciones: qué se hizo, sobre qué, quién y cuándo.
 *
 * Los eventos se escriben a mano, sin pasar por los Actions: lo que se
 * prueba es cómo se buscan y se leen, no quién los dispara.
 */
class AuditoriaDeOperacionesTest extends TestCase
{
    use RefreshDatabase;

    private const FILTROS_VACIOS = [
        'desde' => null,
        'hasta' => null,
        'usuario' => null,
        'accion' => null,
        'entidad' => null,
        'criticas' => false,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HaberesDemoSeeder::class);
    }

    /*
    |---------------------------------------------------------------------
    | Acceso
    |---------------------------------------------------------------------
    */

    public function test_quien_opera_no_ve_la_auditoria(): void
    {
        $administrativo = $this->operador('administrativo');

        $this->actingAs($administrativo)->get(route('configuracion.auditoria.index'))->assertForbidden();
        $this->actingAs($administrativo)->get(route('configuracion.auditoria.exportar'))->assertForbidden();
    }

    public function test_el_contador_y_el_administrador_la_ven_con_su_entrada_y_su_camino(): void
    {
        foreach (['contador', 'administrador'] as $rol) {
            $this->actingAs($this->operador($rol))
                ->get(route('configuracion.auditoria.index'))
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->component('configuracion/auditoria')
                    ->where('auth.can', fn ($can): bool => $can['auditoria.operaciones.ver'] === true)
                    ->where('breadcrumbs.1.title', 'Auditoría')
                    ->where('can.export', true));
        }
    }

    public function test_mirar_no_habilita_a_exportar(): void
    {
        $soloVer = User::factory()->create();
        $soloVer->givePermissionTo('auditoria.operaciones.ver');

        $this->actingAs($soloVer)
            ->get(route('configuracion.auditoria.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.export', false));

        $this->actingAs($soloVer)->get(route('configuracion.auditoria.exportar'))->assertForbidden();
    }

    /*
    |---------------------------------------------------------------------
    | Lectura
    |---------------------------------------------------------------------
    */

    public function test_los_eventos_van_del_mas_reciente_al_mas_antiguo_y_desempata_el_id(): void
    {
        $instante = CarbonImmutable::parse('2026-09-15 12:00:00');
        $viejo = $this->evento('usuario.creado', at: $instante->subHour());
        $primero = $this->evento('usuario.actualizado', at: $instante);
        $segundo = $this->evento('usuario.desactivado', at: $instante);

        $this->ver()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('events.data.0.id', $segundo->id)
            ->where('events.data.1.id', $primero->id)
            ->where('events.data.2.id', $viejo->id));
    }

    public function test_pagina_de_a_cincuenta(): void
    {
        foreach (range(1, 51) as $minuto) {
            $this->evento('usuario.actualizado', at: CarbonImmutable::parse('2026-09-15 12:00')->addMinutes($minuto));
        }

        $this->ver()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('events.data', 50)
            ->where('events.total', 51));
    }

    public function test_el_evento_llega_rotulado_y_con_el_sujeto_nombrado_y_enlazado(): void
    {
        $expediente = Expediente::query()->orderBy('id')->firstOrFail();
        $contador = $this->operador('contador');

        $this->evento('expediente.anulado', 'Expediente', $expediente->id, userId: $contador->id, old: ['status' => 'active'], new: ['status' => 'cancelled'], meta: ['motivo' => 'Duplicado']);

        $this->ver($contador)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('events.data.0.label', 'Se anuló el expediente')
            ->where('events.data.0.critical', true)
            ->where('events.data.0.category', 'Haberes')
            ->where('events.data.0.userName', $contador->name)
            ->where('events.data.0.subjectLabel', 'Expediente')
            ->where('events.data.0.subjectDescription', "Expte. {$expediente->display_number}")
            ->where('events.data.0.subjectUrl', route('expedientes.show', $expediente))
            ->where('events.data.0.changes', [
                ['field' => 'Estado', 'before' => 'Activo', 'after' => 'Anulado'],
                ['field' => 'Motivo', 'before' => null, 'after' => 'Duplicado'],
            ]));
    }

    public function test_sin_permiso_sobre_el_destino_no_hay_enlace(): void
    {
        $expediente = Expediente::query()->orderBy('id')->firstOrFail();
        $auditor = User::factory()->create();
        $auditor->givePermissionTo('auditoria.operaciones.ver');

        $this->evento('expediente.corregido', 'Expediente', $expediente->id);

        $this->ver($auditor)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('events.data.0.subjectDescription', "Expte. {$expediente->display_number}")
            ->where('events.data.0.subjectUrl', null));
    }

    public function test_un_registro_que_ya_no_existe_se_describe_sin_enlace(): void
    {
        $this->evento('expediente.corregido', 'Expediente', 999999);

        $this->ver()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('events.data.0.subjectDescription', 'Expediente #999999')
            ->where('events.data.0.subjectUrl', null));
    }

    public function test_lo_que_el_catalogo_no_conoce_se_muestra_con_su_codigo(): void
    {
        $this->evento('algo.historico', 'Inventado', 5);

        $this->ver()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('events.data.0.label', 'algo.historico')
            ->where('events.data.0.category', 'Otras')
            ->where('events.data.0.subjectLabel', 'Inventado')
            ->where('events.data.0.subjectDescription', 'Inventado #5')
            ->where('events.data.0.subjectUrl', null));
    }

    /**
     * La metadata no se vuelca entera: solo lo que la acción declaró, y el
     * motivo.
     */
    public function test_se_muestran_los_metadatos_declarados_y_no_los_demas(): void
    {
        $cuota = Expediente::query()->orderBy('id')->firstOrFail()->haberes()->firstOrFail()->installments()->firstOrFail();

        $this->evento('cobro.anulado', 'BeneficiaryInstallment', $cuota->id, meta: [
            'fund_receipt_id' => 44,
            'amount' => '1500.00',
            'motivo' => 'Error de carga',
        ]);

        $this->ver()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('events.data.0.changes', [
                ['field' => 'Importe', 'before' => null, 'after' => '$ 1.500,00'],
                ['field' => 'Motivo', 'before' => null, 'after' => 'Error de carga'],
            ]));
    }

    /*
    |---------------------------------------------------------------------
    | Filtros
    |---------------------------------------------------------------------
    */

    /**
     * Las 23:30 del 9 en Salta son las 02:30 del 10 en UTC. Filtrar el
     * día en UTC —lo que hace `whereDate`— lo mandaría al día siguiente.
     */
    public function test_las_fechas_son_dias_de_salta(): void
    {
        $nocheDelNueve = $this->evento('usuario.creado', at: CarbonImmutable::parse('2026-09-10 02:30:00', 'UTC'));
        $mananaDelDiez = $this->evento('usuario.creado', at: CarbonImmutable::parse('2026-09-10 03:30:00', 'UTC'));

        $this->assertSame([$nocheDelNueve->id], $this->ids(['desde' => '2026-09-09', 'hasta' => '2026-09-09']));
        $this->assertSame([$mananaDelDiez->id], $this->ids(['desde' => '2026-09-10', 'hasta' => '2026-09-10']));
        $this->assertSame([$mananaDelDiez->id, $nocheDelNueve->id], $this->ids(['desde' => '2026-09-09']));
    }

    public function test_filtra_por_usuario_y_por_el_sistema(): void
    {
        $contador = $this->operador('contador');
        $suyo = $this->evento('usuario.creado', userId: $contador->id);
        $delSistema = $this->evento('banco.extracto.importado', 'BankStatementImport', 1);

        $this->assertSame([$suyo->id], $this->ids(['usuario' => (string) $contador->id]));
        $this->assertSame([$delSistema->id], $this->ids(['usuario' => 'sistema']));
    }

    public function test_filtra_por_accion_por_entidad_y_por_criticas(): void
    {
        $expediente = Expediente::query()->orderBy('id')->firstOrFail();
        $anulado = $this->evento('expediente.anulado', 'Expediente', $expediente->id);
        $corregido = $this->evento('expediente.corregido', 'Expediente', $expediente->id);
        $usuario = $this->evento('usuario.creado');

        $this->assertSame([$corregido->id], $this->ids(['accion' => 'expediente.corregido']));
        $this->assertSame([$usuario->id], $this->ids(['entidad' => 'User']));
        $this->assertSame([$anulado->id], $this->ids(['criticas' => '1']));
    }

    /**
     * Un filtro inválido no se ignora: vuelve a la pantalla sin filtros y
     * con el error. Una auditoría que muestra otra cosa que la pedida sin
     * avisar es peor que ninguna.
     *
     * @return iterable<string, array{0: array<string, string>, 1: string}>
     */
    public static function filtrosInvalidos(): iterable
    {
        yield 'fecha mal escrita' => [['desde' => '09/09/2026'], 'desde'];
        yield 'hasta antes que desde' => [['desde' => '2026-09-10', 'hasta' => '2026-09-09'], 'hasta'];
        yield 'usuario inexistente' => [['usuario' => '999999'], 'usuario'];
        yield 'usuario que no es un número' => [['usuario' => 'admin'], 'usuario'];
        yield 'acción fuera del catálogo' => [['accion' => 'algo.inventado'], 'accion'];
        yield 'entidad fuera del catálogo' => [['entidad' => 'Inventado'], 'entidad'];
    }

    /**
     * @param  array<string, string>  $filtros
     */
    #[DataProvider('filtrosInvalidos')]
    public function test_un_filtro_invalido_vuelve_con_el_error(array $filtros, string $campo): void
    {
        $this->actingAs($this->operador('contador'))
            ->get(route('configuracion.auditoria.index', $filtros))
            ->assertRedirect(route('configuracion.auditoria.index'))
            ->assertSessionHasErrors($campo);
    }

    /*
    |---------------------------------------------------------------------
    | Excel
    |---------------------------------------------------------------------
    */

    public function test_el_excel_respeta_los_filtros_y_trae_una_fila_por_cambio(): void
    {
        $contador = $this->operador('contador');

        $this->evento('usuario.creado', 'User', $contador->id, at: CarbonImmutable::parse('2026-09-15 12:00'), userId: $contador->id, new: [
            'username' => 'mperez',
            'position' => 'Tesorería',
        ]);
        $sinCambios = $this->evento('usuario.password_restablecida', 'User', $contador->id, at: CarbonImmutable::parse('2026-09-15 13:00'));
        $this->evento('banco.extracto.importado', 'BankStatementImport', 1, at: CarbonImmutable::parse('2026-09-15 14:00'));

        $filas = $this->excel($contador, ['entidad' => 'User']);

        $this->assertSame([
            'N.º evento', 'Fecha y hora (Salta)', 'Usuario', 'Código de acción', 'Acción', 'Crítica',
            'Entidad', 'ID entidad', 'Descripción', 'Campo', 'Antes', 'Después', 'IP',
        ], $filas[0]);

        // El más reciente primero, igual que en la pantalla. El evento sin
        // cambios ocupa una fila; el alta, una por campo.
        $this->assertCount(4, $filas);
        $this->assertSame((string) $sinCambios->id, $filas[1][0]);
        $this->assertSame('15/09/2026 10:00:00', $filas[1][1]);
        $this->assertSame('Sistema', $filas[1][2]);
        $this->assertSame('Sí', $filas[1][5]);
        // Sin campo: la planilla recorta las celdas vacías del final.
        $this->assertSame('', $filas[1][9] ?? '');

        // En el orden de `jsonb`: primero las claves más cortas.
        $this->assertSame(['Cargo', 'Tesorería'], [$filas[2][9], $filas[2][11]]);
        $this->assertSame(['Usuario', 'mperez'], [$filas[3][9], $filas[3][11]]);
        $this->assertSame('Usuario', $filas[3][6]);
        $this->assertSame((string) $contador->id, $filas[3][7]);
    }

    /**
     * Por bloques, el orden es el mismo que el de la pantalla —también
     * entre eventos del mismo instante—, y lo registrado durante la
     * descarga no se cuela.
     */
    public function test_el_recorrido_por_bloques_es_estable(): void
    {
        $instante = CarbonImmutable::parse('2026-09-15 12:00:00.123456');

        foreach ([0, 0, 0, 1, 2, 2, 3] as $segundos) {
            $this->evento('usuario.actualizado', at: $instante->subSeconds($segundos));
        }

        $consulta = app(OperationAuditQuery::class);
        $esperado = $consulta->query(self::FILTROS_VACIOS)->pluck('id')->all();

        $recorrido = [];

        foreach ($consulta->chunks(self::FILTROS_VACIOS, 2) as $numero => $bloque) {
            array_push($recorrido, ...$bloque->pluck('id')->all());

            if ($numero === 0) {
                // Más viejo que todos: sin la foto inicial caería en un
                // bloque posterior.
                $this->evento('usuario.actualizado', at: $instante->subDay());
            }
        }

        $this->assertSame($esperado, $recorrido);
    }

    /*
    |---------------------------------------------------------------------
    | Andamiaje
    |---------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     * @param  array<string, mixed>|null  $meta
     */
    private function evento(
        string $action,
        string $type = 'User',
        ?int $id = null,
        ?CarbonImmutable $at = null,
        ?int $userId = null,
        ?array $old = null,
        ?array $new = null,
        ?array $meta = null,
    ): AuditEvent {
        return AuditEvent::query()->create([
            'user_id' => $userId,
            'action' => $action,
            'subject_type' => $type,
            'subject_id' => $id ?? 1,
            'old_values' => $old,
            'new_values' => $new,
            'metadata' => $meta,
            'occurred_at' => $at ?? now(),
        ]);
    }

    private function ver(?User $viewer = null): TestResponse
    {
        return $this->actingAs($viewer ?? $this->operador('contador'))
            ->get(route('configuracion.auditoria.index'))
            ->assertOk();
    }

    /**
     * @param  array<string, string>  $filtros
     * @return list<int>
     */
    private function ids(array $filtros): array
    {
        $respuesta = $this->actingAs($this->operador('contador'))
            ->get(route('configuracion.auditoria.index', $filtros))
            ->assertOk();

        /** @var list<array{id: int}> $eventos */
        $eventos = $respuesta->viewData('page')['props']['events']['data'];

        return array_column($eventos, 'id');
    }

    /**
     * @param  array<string, string>  $filtros
     * @return list<list<string>>
     */
    private function excel(User $viewer, array $filtros): array
    {
        $respuesta = $this->actingAs($viewer)
            ->get(route('configuracion.auditoria.exportar', $filtros))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $archivo = tempnam(sys_get_temp_dir(), 'auditoria').'.xlsx';
        file_put_contents($archivo, $respuesta->streamedContent());

        try {
            return app(SpreadsheetReader::class)->spreadsheet($archivo);
        } finally {
            @unlink($archivo);
        }
    }
}
