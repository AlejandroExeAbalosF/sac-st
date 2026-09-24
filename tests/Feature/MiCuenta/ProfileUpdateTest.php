<?php

declare(strict_types=1);

namespace Tests\Feature\MiCuenta;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('mi-cuenta.perfil'));

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('mi-cuenta.perfil.update'), [
                'firstName' => 'Test',
                'lastName' => 'User',
                'email' => 'test@example.com',
                'currentPassword' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('mi-cuenta.perfil'));

        $user->refresh();

        $this->assertSame('User, Test', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    /**
     * El correo es a donde llega la recuperación de la contraseña.
     *
     * Quien se encuentra una sesión abierta y lo cambia por el suyo se
     * queda con la cuenta pidiendo «olvidé mi contraseña». Por eso cambiarlo
     * pide la contraseña actual.
     */
    public function test_cambiar_el_correo_exige_la_contrasena_actual(): void
    {
        $user = User::factory()->create(['email' => 'original@example.com']);

        $this->actingAs($user)
            ->patch(route('mi-cuenta.perfil.update'), [
                'firstName' => 'Test',
                'lastName' => 'User',
                'email' => 'intruso@example.com',
            ])
            ->assertSessionHasErrors([
                'currentPassword' => 'Para cambiar el correo, confirmá tu contraseña actual.',
            ]);

        $this->actingAs($user)
            ->patch(route('mi-cuenta.perfil.update'), [
                'firstName' => 'Test',
                'lastName' => 'User',
                'email' => 'intruso@example.com',
                'currentPassword' => 'otra-cosa',
            ])
            ->assertSessionHasErrors(['currentPassword' => 'La contraseña no es correcta.']);

        $this->assertSame('original@example.com', $user->refresh()->email);
    }

    /** Corregir el nombre no pide la contraseña. */
    public function test_corregir_el_nombre_no_pide_la_contrasena(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch(route('mi-cuenta.perfil.update'), [
                'firstName' => 'Otro',
                'lastName' => 'Nombre',
                'email' => $user->email,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Nombre, Otro', $user->refresh()->name);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('mi-cuenta.perfil.update'), [
                'firstName' => 'Test',
                'lastName' => 'User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('mi-cuenta.perfil'));

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    /**
     * No hay baja de cuenta autogestionada: un usuario que operó sobre
     * dinero tiene que seguir siendo identificable. La baja se hace
     * marcandolo inactivo, no borrandolo.
     */
    public function test_there_is_no_self_service_account_deletion()
    {
        $this->assertFalse(Route::has('mi-cuenta.perfil.destroy'));

        $user = User::factory()->create();

        $this->actingAs($user)
            ->delete('/mi-cuenta', ['password' => 'password'])
            ->assertMethodNotAllowed();

        $this->assertNotNull($user->fresh());
    }
}
