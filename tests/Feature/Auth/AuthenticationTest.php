<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Shared\Enums\LoginEventType;
use App\Modules\Shared\Enums\LoginFailureReason;
use App\Modules\Shared\Models\UserLoginEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Features;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered()
    {
        $response = $this->get(route('login'));

        $response->assertOk();
    }

    public function test_users_authenticate_with_their_username()
    {
        $user = User::factory()->create(['username' => 'g.sosa']);

        $response = $this->post(route('login.store'), [
            'username' => 'g.sosa',
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('inicio', absolute: false));
    }

    public function test_the_username_is_not_case_sensitive()
    {
        User::factory()->create(['username' => 'g.sosa']);

        $this->post(route('login.store'), [
            'username' => 'G.SOSA',
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
    }

    public function test_the_email_is_not_a_valid_credential()
    {
        $user = User::factory()->create(['email' => 'g.sosa@salta.gob.ar']);

        $this->post(route('login.store'), [
            'username' => $user->email,
            'password' => 'password',
        ]);

        $this->assertGuest();
    }

    public function test_users_with_two_factor_enabled_are_redirected_to_two_factor_challenge()
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]);

        $user = User::factory()->withTwoFactor()->create();

        $response = $this->post(route('login'), [
            'username' => $user->username,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('two-factor.login'));
        $response->assertSessionHas('login.id', $user->id);
        $this->assertGuest();
    }

    public function test_users_can_not_authenticate_with_invalid_password()
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'username' => $user->username,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    /**
     * Un usuario dado de baja conserva sus credenciales válidas y aun así
     * no entra: la baja no borra al usuario porque sus operaciones pasadas
     * tienen que seguir siendo atribuibles.
     */
    public function test_inactive_users_can_not_authenticate_even_with_valid_credentials()
    {
        $user = User::factory()->inactive()->create();

        $response = $this->post(route('login.store'), [
            'username' => $user->username,
            'password' => 'password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('username');

        $this->assertDatabaseHas('user_login_events', [
            'user_id' => $user->id,
            'event_type' => LoginEventType::LoginFailed->value,
            'failure_reason' => LoginFailureReason::AccountDisabled->value,
        ]);
    }

    public function test_users_can_logout()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('logout'));

        $response->assertRedirect(route('home'));

        $this->assertGuest();
    }

    public function test_users_are_rate_limited()
    {
        $user = User::factory()->create();

        RateLimiter::increment(md5('login'.implode('|', [$user->username, '127.0.0.1'])), amount: 5);

        $response = $this->post(route('login.store'), [
            'username' => $user->username,
            'password' => 'wrong-password',
        ]);

        $response->assertTooManyRequests();
    }

    public function test_a_successful_login_is_recorded_in_the_access_history()
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'username' => $user->username,
            'password' => 'password',
        ]);

        $event = UserLoginEvent::query()
            ->where('user_id', $user->id)
            ->where('event_type', LoginEventType::LoginSuccess)
            ->sole();

        $this->assertSame($user->username, $event->username_attempted);
        $this->assertNotNull($event->ip_address);
        $this->assertNotNull($user->refresh()->last_login_at);
    }

    public function test_a_failed_login_against_an_unknown_username_is_recorded()
    {
        $this->post(route('login.store'), [
            'username' => 'nadie.existe',
            'password' => 'lo-que-sea',
        ]);

        $this->assertDatabaseHas('user_login_events', [
            'user_id' => null,
            'username_attempted' => 'nadie.existe',
            'event_type' => LoginEventType::LoginFailed->value,
            'failure_reason' => LoginFailureReason::UnknownUsername->value,
        ]);
    }
}
