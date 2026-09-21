<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La raíz no muestra una portada: el sistema es una intranet.
 */
class HomeRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_the_login_screen()
    {
        $this->get(route('home'))->assertRedirect(route('login'));
    }

    public function test_authenticated_users_are_sent_to_the_dashboard()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('home'))
            ->assertRedirect(route('inicio'));
    }
}
