<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
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
 *
 * El cambio en sí —qué pide, qué rechaza, qué registra— se prueba en
 * InitialPasswordTest. Acá, solo la puerta.
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

    public function test_con_la_marca_puesta_toda_pantalla_lleva_al_primer_ingreso(): void
    {
        $this
            ->actingAs($this->conClaveProvisoria())
            ->get(route('inicio'))
            ->assertRedirect(route('primer-ingreso'));
    }

    public function test_la_seguridad_de_la_cuenta_tampoco_queda_abierta(): void
    {
        // Antes era la pantalla del cambio obligatorio. Ahora es una más:
        // con la barra lateral y las secciones de la cuenta, que con la
        // marca puesta no llevaban a ningún lado.
        $user = $this->conClaveProvisoria();

        $this->actingAs($user)
            ->get(route('mi-cuenta.seguridad'))
            ->assertRedirect(route('primer-ingreso'));

        // Ni su guardado: el cambio obligatorio tiene una sola puerta.
        $this->actingAs($user)
            ->put(route('user-password.update'), [
                'current_password' => 'password',
                'password' => 'Contrasena.Nueva.2026',
                'password_confirmation' => 'Contrasena.Nueva.2026',
            ])
            ->assertRedirect(route('primer-ingreso'));

        $this->assertTrue($user->refresh()->must_change_password);
    }

    public function test_la_confirmacion_de_contrasena_ya_no_hace_falta(): void
    {
        // El primer ingreso pide la temporal en su propio formulario cuando
        // corresponde; la pantalla de confirmación no tiene nada que hacer
        // con la marca puesta.
        $this
            ->actingAs($this->conClaveProvisoria())
            ->get(route('password.confirm'))
            ->assertRedirect(route('primer-ingreso'));
    }

    public function test_la_salida_sigue_disponible(): void
    {
        $this
            ->actingAs($this->conClaveProvisoria())
            ->post(route('logout'))
            ->assertRedirect();

        $this->assertGuest();
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
