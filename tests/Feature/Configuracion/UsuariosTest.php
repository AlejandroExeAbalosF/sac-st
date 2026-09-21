<?php

declare(strict_types=1);

namespace Tests\Feature\Configuracion;

use App\Models\User;
use App\Modules\Shared\Enums\LoginEventType;
use App\Modules\Shared\Models\AuditEvent;
use App\Modules\Shared\Models\UserLoginEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UsuariosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

    }

    private function administrador(): User
    {
        $user = User::factory()->create();
        $user->assignRole('administrador');

        return $user;
    }

    public function test_el_alta_devuelve_una_contrasena_temporal_y_obliga_a_cambiarla(): void
    {
        $response = $this
            ->actingAs($this->administrador())
            ->from(route('configuracion.usuarios.index'))
            ->post(route('configuracion.usuarios.store'), [
                'firstName' => 'Nora',
                'lastName' => 'Achad',
                'username' => 'n.achad',
                'documentNumber' => '20111222',
                'email' => 'n.achad@salta.gob.ar',
                'position' => 'Asesor Contable',
                'role' => 'contador',
            ]);

        $response->assertSessionHasNoErrors();

        $creado = User::query()->where('username', 'n.achad')->firstOrFail();

        $this->assertTrue($creado->must_change_password);
        $this->assertTrue($creado->is_active);
        $this->assertTrue($creado->hasRole('contador'));
        $this->assertSame('Asesor Contable', $creado->position);

        // La clave viaja una sola vez, en el flash, y sirve para entrar.
        $temporal = session('temporaryPassword');
        $this->assertIsArray($temporal);
        $this->assertSame('n.achad', $temporal['username']);
        $this->assertTrue(Hash::check($temporal['password'], $creado->password));

        $this->assertDatabaseHas('audit_events', [
            'action' => 'usuario.creado',
            'subject_type' => 'User',
            'subject_id' => $creado->id,
        ]);
    }

    public function test_la_contrasena_temporal_cumple_la_politica_del_sistema(): void
    {
        $this
            ->actingAs($this->administrador())
            ->post(route('configuracion.usuarios.store'), [
                'firstName' => 'Gabriel',
                'lastName' => 'Sosa',
                'username' => 'g.sosa',
                'documentNumber' => '20111333',
                'email' => 'g.sosa@salta.gob.ar',
                'role' => 'administrativo',
            ]);

        $password = session('temporaryPassword')['password'];

        // Doce caracteres, mayúscula, minúscula, número y símbolo: si la
        // clave generada no pasara la política, el usuario quedaría sin
        // poder cambiarla usándola como contraseña actual.
        $this->assertGreaterThanOrEqual(12, strlen($password));
        $this->assertMatchesRegularExpression('/[a-z]/', $password);
        $this->assertMatchesRegularExpression('/[A-Z]/', $password);
        $this->assertMatchesRegularExpression('/[0-9]/', $password);
        $this->assertMatchesRegularExpression('/[^a-zA-Z0-9]/', $password);
    }

    public function test_un_administrativo_no_llega_a_la_pantalla_de_usuarios(): void
    {
        $user = User::factory()->create();
        $user->assignRole('administrativo');

        $this->actingAs($user)
            ->get(route('configuracion.usuarios.index'))
            ->assertForbidden();
    }

    public function test_el_nombre_de_usuario_no_se_edita(): void
    {
        $otro = User::factory()->create(['username' => 'g.sosa']);
        $otro->assignRole('administrativo');

        $this
            ->actingAs($this->administrador())
            ->patch(route('configuracion.usuarios.update', $otro), [
                'firstName' => 'Gabriel',
                'lastName' => 'Sosa',
                'username' => 'otro.nombre',
                'documentNumber' => $otro->document_number,
                'email' => $otro->email,
                'role' => 'contador',
            ])
            ->assertSessionHasNoErrors();

        $otro->refresh();

        $this->assertSame('g.sosa', $otro->username);
        // `name` la arma la base: «Apellido, Nombre».
        $this->assertSame('Sosa, Gabriel', $otro->name);
        $this->assertTrue($otro->hasRole('contador'));
    }

    public function test_nadie_se_desactiva_a_si_mismo(): void
    {
        $admin = $this->administrador();

        $this
            ->actingAs($admin)
            ->from(route('configuracion.usuarios.index'))
            ->patch(route('configuracion.usuarios.estado', $admin), ['isActive' => false])
            ->assertSessionHasErrors('isActive');

        $this->assertTrue($admin->refresh()->is_active);
    }

    public function test_no_se_desactiva_al_ultimo_administrador_activo(): void
    {
        $admin = $this->administrador();
        $otroAdmin = User::factory()->create();
        $otroAdmin->assignRole('administrador');

        // Con dos administradores, desactivar a uno se permite.
        $this
            ->actingAs($admin)
            ->from(route('configuracion.usuarios.index'))
            ->patch(route('configuracion.usuarios.estado', $otroAdmin), ['isActive' => false])
            ->assertSessionHasNoErrors();

        $this->assertFalse($otroAdmin->refresh()->is_active);

        // Y ahora que queda uno solo, ya no.
        $tercero = User::factory()->create();
        $tercero->assignRole('administrador');

        $this
            ->actingAs($tercero)
            ->from(route('configuracion.usuarios.index'))
            ->patch(route('configuracion.usuarios.estado', $admin), ['isActive' => false])
            ->assertSessionHasNoErrors();

        $this
            ->actingAs($admin)
            ->from(route('configuracion.usuarios.index'))
            ->patch(route('configuracion.usuarios.estado', $tercero), ['isActive' => false])
            ->assertSessionHasErrors('isActive');

        $this->assertTrue($tercero->refresh()->is_active);
    }

    public function test_desactivar_cierra_las_sesiones_del_usuario(): void
    {
        $operador = User::factory()->create();
        $operador->assignRole('administrativo');

        DB::table('sessions')->insert([
            'id' => 'sesion-del-operador',
            'user_id' => $operador->id,
            'ip_address' => '10.0.0.5',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/138.0',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $this
            ->actingAs($this->administrador())
            ->from(route('configuracion.usuarios.index'))
            ->patch(route('configuracion.usuarios.estado', $operador), ['isActive' => false])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('sessions', ['id' => 'sesion-del-operador']);

        $this->assertTrue(
            UserLoginEvent::query()
                ->where('user_id', $operador->id)
                ->where('event_type', LoginEventType::SessionRevoked->value)
                ->exists()
        );
    }

    /**
     * La puerta que deja al administrador afuera de su propio sistema: se
     * autogenera una clave que se muestra una sola vez, se le cierran todas
     * las sesiones —incluida la que esta usando— y si no la copio no queda
     * nadie que pueda rescatarlo, porque rescatarlo exige entrar como
     * administrador. Paso en desarrollo antes de que existiera la guarda.
     */
    public function test_nadie_restablece_su_propia_contrasena(): void
    {
        $admin = $this->administrador();
        $anterior = $admin->password;

        $this
            ->actingAs($admin)
            ->from(route('configuracion.usuarios.index'))
            ->post(route('configuracion.usuarios.contrasena', $admin))
            ->assertSessionHasErrors('resetPassword');

        $admin->refresh();

        $this->assertSame($anterior, $admin->password);
        $this->assertFalse($admin->must_change_password);
        $this->assertNull(session('temporaryPassword'));
    }

    public function test_la_contrasena_propia_se_cambia_desde_mi_cuenta(): void
    {
        // La salida que la guarda deja abierta: pide la actual, deja elegir
        // la nueva y no cierra la sesion desde la que se esta trabajando.
        $admin = $this->administrador();

        $this
            ->actingAs($admin)
            ->put(route('user-password.update'), [
                'current_password' => 'password',
                'password' => 'Contrasena.Propia.2026',
                'password_confirmation' => 'Contrasena.Propia.2026',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('Contrasena.Propia.2026', $admin->refresh()->password));
        $this->assertFalse($admin->must_change_password);
    }

    public function test_restablecer_la_contrasena_la_marca_provisoria_y_cierra_sesiones(): void
    {
        $operador = User::factory()->create();
        $operador->assignRole('administrativo');
        $anterior = $operador->password;

        DB::table('sessions')->insert([
            'id' => 'sesion-a-cerrar',
            'user_id' => $operador->id,
            'ip_address' => '10.0.0.6',
            'user_agent' => null,
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $this
            ->actingAs($this->administrador())
            ->from(route('configuracion.usuarios.index'))
            ->post(route('configuracion.usuarios.contrasena', $operador))
            ->assertSessionHasNoErrors();

        $operador->refresh();

        $this->assertNotSame($anterior, $operador->password);
        $this->assertTrue($operador->must_change_password);
        $this->assertDatabaseMissing('sessions', ['id' => 'sesion-a-cerrar']);

        $this->assertTrue(
            UserLoginEvent::query()
                ->where('user_id', $operador->id)
                ->where('event_type', LoginEventType::PasswordReset->value)
                ->exists()
        );

        $this->assertTrue(
            AuditEvent::query()
                ->where('action', 'usuario.password_restablecida')
                ->where('subject_id', $operador->id)
                ->exists()
        );
    }
}
