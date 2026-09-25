<?php

declare(strict_types=1);

namespace Tests\Feature\Configuracion;

use App\Models\User;
use App\Modules\Shared\Actions\RevokeSession;
use App\Modules\Shared\Enums\LoginEventType;
use App\Modules\Shared\Enums\LoginFailureReason;
use App\Modules\Shared\Http\Controllers\AccessAuditController;
use App\Modules\Shared\Models\UserLoginEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AccesosYSesionesTest extends TestCase
{
    use RefreshDatabase;

    private function administrador(): User
    {
        $user = User::factory()->create();
        $user->assignRole('administrador');

        return $user;
    }

    public function test_el_historial_lista_los_intentos_contra_usuarios_inexistentes(): void
    {
        // La fila más importante de la tabla: alguien probando un nombre
        // que no existe. No apunta a ningún usuario y por eso el listado no
        // puede depender de la relación para mostrarla.
        UserLoginEvent::query()->create([
            'user_id' => null,
            'username_attempted' => 'administrator',
            'event_type' => LoginEventType::LoginFailed,
            'failure_reason' => LoginFailureReason::UnknownUsername,
            'ip_address' => '190.1.2.3',
            'created_at' => now(),
        ]);

        $this
            ->actingAs($this->administrador())
            ->get(route('configuracion.accesos.index'))
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('configuracion/accesos')
                    ->where('events.data.0.usernameAttempted', 'administrator')
                    ->where('events.data.0.userName', null)
                    ->where('events.data.0.suspicious', true)
            );
    }

    public function test_el_historial_se_filtra_por_tipo_de_evento(): void
    {
        $usuario = User::factory()->create();

        UserLoginEvent::query()->create([
            'user_id' => $usuario->id,
            'event_type' => LoginEventType::LoginSuccess,
            'ip_address' => '10.0.0.1',
            'created_at' => now(),
        ]);

        UserLoginEvent::query()->create([
            'user_id' => $usuario->id,
            'event_type' => LoginEventType::Logout,
            'ip_address' => '10.0.0.1',
            'created_at' => now(),
        ]);

        $this
            ->actingAs($this->administrador())
            ->get(route('configuracion.accesos.index', ['tipo' => 'logout']))
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('configuracion/accesos')
                    ->has('events.data', 1)
                    ->where('events.data.0.type', 'logout')
            );
    }

    public function test_revocar_una_sesion_la_borra_y_deja_el_evento(): void
    {
        $operador = User::factory()->create();

        DB::table('sessions')->insert([
            'id' => 'sesion-ajena',
            'user_id' => $operador->id,
            'ip_address' => '10.0.0.9',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/138.0',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $this
            ->actingAs($this->administrador())
            ->from(route('configuracion.accesos.index'))
            ->delete(route('configuracion.sesiones.destroy'), ['sessionId' => 'sesion-ajena'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('sessions', ['id' => 'sesion-ajena']);

        $evento = UserLoginEvent::query()
            ->where('user_id', $operador->id)
            ->where('event_type', LoginEventType::SessionRevoked->value)
            ->firstOrFail();

        $this->assertSame('sesion-ajena', $evento->meta['revoked_session_id']);
        $this->assertSame('cerrada por un administrador', $evento->meta['reason']);
    }

    /**
     * A un `super-admin` solo lo gestiona otro, también para cerrarle una
     * sesión: la auditoría no es un atajo alrededor de esa regla.
     */
    public function test_un_administrador_no_cierra_la_sesion_de_un_super_admin(): void
    {
        $superAdmin = $this->operador('super-admin');

        DB::table('sessions')->insert([
            'id' => 'sesion-del-super-admin',
            'user_id' => $superAdmin->id,
            'ip_address' => '10.0.0.9',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/138.0',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $admin = $this->administrador();

        $this->actingAs($admin)
            ->get(route('configuracion.accesos.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where(
                'sessions',
                fn ($sesiones): bool => collect($sesiones)
                    ->firstWhere('id', 'sesion-del-super-admin')['revokeLockedReason']
                    === 'Un super-admin solo lo gestiona otro super-admin.',
            ));

        $this->actingAs($admin)
            ->from(route('configuracion.accesos.index'))
            ->delete(route('configuracion.sesiones.destroy'), ['sessionId' => 'sesion-del-super-admin'])
            ->assertSessionHasErrors(['sessionId' => 'Un super-admin solo lo gestiona otro super-admin.']);

        $this->assertDatabaseHas('sessions', ['id' => 'sesion-del-super-admin']);

        $this->actingAs($this->operador('super-admin'))
            ->from(route('configuracion.accesos.index'))
            ->delete(route('configuracion.sesiones.destroy'), ['sessionId' => 'sesion-del-super-admin'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('sessions', ['id' => 'sesion-del-super-admin']);
    }

    /**
     * El controlador se invoca directo: los tests corren con el driver de
     * sesion `array`, que genera un id nuevo en cada request, asi que por
     * HTTP no hay forma de mandarle «el id de esta misma sesion». La
     * guarda igual tiene que existir —cerrarse a uno mismo desde la
     * auditoria deja al administrador afuera sin entender por que—, y aca
     * se verifica sobre el mismo codigo que corre en produccion.
     */
    public function test_no_se_revoca_la_sesion_propia_desde_la_auditoria(): void
    {
        // Cuarenta caracteres alfanumericos: `Store::setId()` descarta
        // cualquier otra cosa y genera un id al azar en su lugar, con lo
        // que el id que se manda no coincidiria con el de la sesion y la
        // guarda no llegaria a evaluarse.
        $propia = str_repeat('a', 40);

        $store = app('session.store');
        $store->setId($propia);
        $store->start();

        // `back()` necesita una sesion en el redirector para poder
        // flashear; fuera de un request real nadie se la inyecta.
        app('redirect')->setSession($store);

        DB::table('sessions')->insert([
            'id' => $propia,
            'user_id' => $this->administrador()->id,
            'ip_address' => '10.0.0.1',
            'user_agent' => null,
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $request = Request::create(
            route('configuracion.sesiones.destroy'),
            'DELETE',
            ['sessionId' => $propia],
        );
        $request->setLaravelSession($store);

        app(AccessAuditController::class)->destroySession($request, app(RevokeSession::class));

        // Lo que importa no es el mensaje sino el efecto: la sesion sigue
        // abierta y no se escribio ningun evento de revocacion.
        $this->assertDatabaseHas('sessions', ['id' => $propia]);
        $this->assertSame(
            0,
            UserLoginEvent::query()
                ->where('event_type', LoginEventType::SessionRevoked->value)
                ->count(),
        );
    }

    public function test_un_administrativo_no_ve_la_auditoria(): void
    {
        $user = User::factory()->create();
        $user->assignRole('administrativo');

        $this->actingAs($user)
            ->get(route('configuracion.accesos.index'))
            ->assertForbidden();
    }

    public function test_el_contador_lee_la_auditoria_pero_no_revoca(): void
    {
        $user = User::factory()->create();
        $user->assignRole('contador');

        $this->actingAs($user)
            ->get(route('configuracion.accesos.index'))
            ->assertOk();

        $this->actingAs($user)
            ->delete(route('configuracion.sesiones.destroy'), ['sessionId' => 'lo-que-sea'])
            ->assertForbidden();
    }
}
