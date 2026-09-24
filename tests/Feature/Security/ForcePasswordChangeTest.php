<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\Shared\Enums\LoginEventType;
use App\Modules\Shared\Models\UserLoginEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mientras el usuario siga usando la clave que le dictó un administrador,
 * el sistema no lo deja hacer otra cosa que cambiarla.
 *
 * Es lo que le pone plazo al único momento en que dos personas conocen la
 * misma contraseña. Sin esto, la clave temporal se vuelve permanente el día
 * que se entrega, y la firma de un recibo deja de identificar a una sola
 * persona.
 */
class ForcePasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private function conClaveProvisoria(): User
    {
        $user = User::factory()->create(['must_change_password' => true]);
        $user->assignRole('administrativo');

        return $user;
    }

    public function test_con_la_marca_puesta_toda_pantalla_lleva_a_seguridad(): void
    {
        $this
            ->actingAs($this->conClaveProvisoria())
            ->get(route('inicio'))
            ->assertRedirect(route('mi-cuenta.seguridad'));
    }

    public function test_la_salida_sigue_disponible(): void
    {
        $this
            ->actingAs($this->conClaveProvisoria())
            ->post(route('logout'))
            ->assertRedirect();

        $this->assertGuest();
    }

    public function test_la_confirmacion_de_contrasena_no_queda_atrapada(): void
    {
        // `mi-cuenta.seguridad` está detrás de RequirePassword: si esta
        // ruta también redirigiera, el usuario quedaría en un ciclo entre
        // las dos sin poder llegar nunca al formulario.
        $this
            ->actingAs($this->conClaveProvisoria())
            ->get(route('password.confirm'))
            ->assertOk();
    }

    public function test_cambiar_la_contrasena_limpia_la_marca_y_deja_el_evento(): void
    {
        $user = $this->conClaveProvisoria();

        $this
            ->actingAs($user)
            ->put(route('user-password.update'), [
                'current_password' => 'password',
                'password' => 'Contrasena.Nueva.2026',
                'password_confirmation' => 'Contrasena.Nueva.2026',
            ])
            ->assertSessionHasNoErrors()
            // Venía obligado: al terminar se lo lleva al sistema, no de
            // vuelta a la misma pantalla.
            ->assertRedirect(route('inicio'));

        $this->assertFalse($user->refresh()->must_change_password);

        $this->assertTrue(
            UserLoginEvent::query()
                ->where('user_id', $user->id)
                ->where('event_type', LoginEventType::PasswordChanged->value)
                ->exists()
        );
    }

    public function test_sin_la_marca_el_sistema_no_interfiere(): void
    {
        $user = User::factory()->create();
        $user->assignRole('administrativo');

        $this->actingAs($user)
            ->get(route('inicio'))
            ->assertOk();
    }
}
