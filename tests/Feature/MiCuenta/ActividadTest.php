<?php

declare(strict_types=1);

namespace Tests\Feature\MiCuenta;

use App\Models\User;
use App\Modules\Shared\Actions\RevokeUserSessions;
use App\Modules\Shared\Enums\LoginEventType;
use App\Modules\Shared\Models\UserLoginEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * La actividad de la cuenta propia no lleva permiso, pero sí un límite
 * estricto: cada uno ve lo suyo. Si esta pantalla filtrara mal, cualquier
 * operador leería el historial de accesos de la contadora.
 */
class ActividadTest extends TestCase
{
    use RefreshDatabase;

    public function test_cada_uno_ve_solo_sus_propios_accesos(): void
    {
        $propio = User::factory()->create();
        $ajeno = User::factory()->create();

        UserLoginEvent::query()->create([
            'user_id' => $propio->id,
            'event_type' => LoginEventType::LoginSuccess,
            'ip_address' => '10.0.0.1',
            'created_at' => now(),
        ]);

        UserLoginEvent::query()->create([
            'user_id' => $ajeno->id,
            'event_type' => LoginEventType::LoginSuccess,
            'ip_address' => '10.0.0.2',
            'created_at' => now(),
        ]);

        $this
            ->actingAs($propio)
            ->get(route('mi-cuenta.actividad'))
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('mi-cuenta/actividad')
                    ->has('events.data', 1)
                    ->where('events.data.0.ipAddress', '10.0.0.1')
            );
    }

    public function test_cada_uno_ve_solo_sus_propias_sesiones(): void
    {
        $propio = User::factory()->create();
        $ajeno = User::factory()->create();

        DB::table('sessions')->insert([
            [
                'id' => 'sesion-propia',
                'user_id' => $propio->id,
                'ip_address' => '10.0.0.1',
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/138.0',
                'payload' => '',
                'last_activity' => now()->timestamp,
            ],
            [
                'id' => 'sesion-ajena',
                'user_id' => $ajeno->id,
                'ip_address' => '10.0.0.2',
                'user_agent' => null,
                'payload' => '',
                'last_activity' => now()->timestamp,
            ],
        ]);

        $this
            ->actingAs($propio)
            ->get(route('mi-cuenta.actividad'))
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->has('sessions', 1)
                    ->where('sessions.0.id', 'sesion-propia')
                    ->where('sessions.0.deviceLabel', 'Chrome 138 / Windows 10/11')
            );
    }

    /**
     * El Action se prueba directo y no por HTTP: los tests corren con el
     * driver de sesion `array`, que genera un id nuevo en cada request, asi
     * que «la sesion actual» no existe como cosa estable a la que apuntar.
     * Lo que importa —que conserve exactamente la que se le indica— se
     * verifica aca sin depender de eso.
     */
    public function test_cerrar_las_demas_conserva_la_sesion_indicada(): void
    {
        $user = User::factory()->create();

        DB::table('sessions')->insert([
            [
                'id' => 'la-actual',
                'user_id' => $user->id,
                'ip_address' => '10.0.0.1',
                'user_agent' => null,
                'payload' => '',
                'last_activity' => now()->timestamp,
            ],
            [
                'id' => 'otra-maquina',
                'user_id' => $user->id,
                'ip_address' => '10.0.0.7',
                'user_agent' => null,
                'payload' => '',
                'last_activity' => now()->timestamp,
            ],
            [
                'id' => 'de-otro-usuario',
                'user_id' => User::factory()->create()->id,
                'ip_address' => '10.0.0.8',
                'user_agent' => null,
                'payload' => '',
                'last_activity' => now()->timestamp,
            ],
        ]);

        $cerradas = app(RevokeUserSessions::class)->handle(
            $user,
            exceptSessionId: 'la-actual',
            reason: 'cerrada por el titular',
        );

        $this->assertSame(1, $cerradas);
        $this->assertDatabaseHas('sessions', ['id' => 'la-actual']);
        $this->assertDatabaseMissing('sessions', ['id' => 'otra-maquina']);
        // Nunca toca las de otro usuario.
        $this->assertDatabaseHas('sessions', ['id' => 'de-otro-usuario']);

        $evento = UserLoginEvent::query()
            ->where('user_id', $user->id)
            ->where('event_type', LoginEventType::SessionRevoked->value)
            ->firstOrFail();

        $this->assertSame('otra-maquina', $evento->meta['revoked_session_id']);
    }

    public function test_el_titular_puede_cerrar_sus_demas_sesiones(): void
    {
        $user = User::factory()->create();

        DB::table('sessions')->insert([
            'id' => 'otra-maquina',
            'user_id' => $user->id,
            'ip_address' => '10.0.0.7',
            'user_agent' => null,
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $this
            ->actingAs($user)
            ->from(route('mi-cuenta.actividad'))
            ->delete(route('mi-cuenta.sesiones.destroy'))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('mi-cuenta.actividad'));

        $this->assertDatabaseMissing('sessions', ['id' => 'otra-maquina']);
    }

    public function test_el_perfil_muestra_los_datos_que_asigna_un_administrador(): void
    {
        $user = User::factory()->create([
            'username' => 'n.achad',
            'position' => 'Asesor Contable',
        ]);

        $this
            ->actingAs($user)
            ->get(route('mi-cuenta.perfil'))
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->component('mi-cuenta/perfil')
                    ->where('assigned.username', 'n.achad')
                    ->where('assigned.position', 'Asesor Contable')
            );
    }
}
