<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * El sistema no tiene registro público: "Acceso exclusivo para personal
 * autorizado". Las altas las hace un administrador desde el módulo de
 * usuarios. Este test existe para que nadie reactive `Features::registration()`
 * sin darse cuenta de lo que implica.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_there_is_no_public_registration_route()
    {
        $this->assertFalse(Route::has('register'), 'La ruta de registro público no debería existir.');
        $this->assertFalse(Route::has('register.store'), 'El alta pública de usuarios no debería existir.');
    }

    public function test_posting_to_the_registration_endpoint_is_not_found()
    {
        $this->post('/register', [
            'firstName' => 'Intruso',
            'lastName' => 'Anónimo',
            'username' => 'intruso',
            'password' => 'Contrasena.Segura.2026',
            'password_confirmation' => 'Contrasena.Segura.2026',
        ])->assertNotFound();

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }
}
