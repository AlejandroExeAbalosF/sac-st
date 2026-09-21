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
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('mi-cuenta.perfil'));

        $user->refresh();

        $this->assertSame('User, Test', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
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
