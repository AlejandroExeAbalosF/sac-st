<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::resetPasswords());
    }

    public function test_reset_password_link_screen_can_be_rendered()
    {
        $response = $this->get(route('password.request'));

        $response->assertOk();
    }

    public function test_reset_password_link_can_be_requested()
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_screen_can_be_rendered()
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $response = $this->get(route('password.reset', $notification->token));

            $response->assertOk();

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token()
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $response = $this->post(route('password.update'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'Contrasena.Nueva.2026',
                'password_confirmation' => 'Contrasena.Nueva.2026',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));

            return true;
        });
    }

    public function test_la_pantalla_trae_la_politica_para_el_checklist(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $this->get(route('password.reset', $notification->token))
                ->assertInertia(fn (Assert $page) => $page
                    ->component('auth/reset-password')
                    ->where('passwordPolicy.minLength', 12),
                );

            return true;
        });
    }

    public function test_recuperarla_por_correo_baja_la_marca_de_la_temporal(): void
    {
        // La eligió el titular desde su correo: mandarlo después al primer
        // ingreso le haría elegir de nuevo lo que acaba de elegir.
        Notification::fake();

        $user = User::factory()->create(['must_change_password' => true]);

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $this->post(route('password.update'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'Contrasena.Nueva.2026',
                'password_confirmation' => 'Contrasena.Nueva.2026',
            ])->assertSessionHasNoErrors();

            return true;
        });

        $this->assertFalse($user->refresh()->must_change_password);
    }

    public function test_no_acepta_la_misma_contrasena_que_ya_tenia(): void
    {
        Notification::fake();

        $user = User::factory()->create(['password' => 'Contrasena.Actual.2026']);

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $this->post(route('password.update'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'Contrasena.Actual.2026',
                'password_confirmation' => 'Contrasena.Actual.2026',
            ])->assertSessionHasErrors([
                'password' => __('validation.custom.password.same_as_current'),
            ]);

            return true;
        });
    }

    public function test_password_cannot_be_reset_with_invalid_token(): void
    {
        $user = User::factory()->create();

        $response = $this->post(route('password.update'), [
            'token' => 'invalid-token',
            'email' => $user->email,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertSessionHasErrors('email');
    }
}
