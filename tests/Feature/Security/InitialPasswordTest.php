<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\Shared\Actions\RecordLoginEvent;
use App\Modules\Shared\Enums\LoginEventType;
use App\Modules\Shared\Models\UserLoginEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * El primer ingreso: donde se deja la contraseña temporal.
 *
 * No pide la actual mientras el login sea reciente, porque quien acaba de
 * entrar la tipeó hace segundos. Lo que ese campo protegía —la sesión que
 * quedó abierta y encontró otro— lo cubre el reloj: pasado
 * `auth.initial_password_timeout`, la vuelve a pedir.
 */
class InitialPasswordTest extends TestCase
{
    use RefreshDatabase;

    /** Una temporal que cumple la política, como las que genera el alta. */
    private const TEMPORAL = 'k7#Qm.Z2xB4pT%9a';

    private const NUEVA = 'Contrasena.Nueva.2026';

    private function conClaveProvisoria(): User
    {
        $user = User::factory()->create([
            'password' => self::TEMPORAL,
            'must_change_password' => true,
        ]);
        $user->assignRole('administrativo');

        return $user;
    }

    /** La sesión tal como la deja el login (ver ConfirmPasswordOnLogin). */
    private function recienEntrado(User $user): static
    {
        return $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()]);
    }

    private function hace(int $segundos, User $user): static
    {
        return $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time() - $segundos]);
    }

    /**
     * @return array<string, string>
     */
    private function nueva(string $password = self::NUEVA): array
    {
        return ['password' => $password, 'password_confirmation' => $password];
    }

    public function test_la_pantalla_se_dibuja_fuera_del_sistema_con_la_politica(): void
    {
        $user = $this->conClaveProvisoria();

        $this->recienEntrado($user)
            ->get(route('primer-ingreso'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                // `auth/` es lo que la saca del AppLayout (ver app.tsx).
                ->component('auth/primer-ingreso')
                ->where('firstName', $user->first_name)
                ->where('wasReset', false)
                ->where('requiresCurrentPassword', false)
                // La política viaja entera: el checklist no tiene copia propia.
                ->where('passwordPolicy.minLength', 12)
                ->where('passwordPolicy.mixedCase', true)
                ->where('passwordPolicy.numbers', true)
                ->where('passwordPolicy.symbols', true)
                ->has('passwordRules'),
            );
    }

    public function test_si_ya_eligio_una_clave_antes_la_pantalla_dice_que_se_la_restablecieron(): void
    {
        $user = $this->conClaveProvisoria();
        app(RecordLoginEvent::class)->handle(type: LoginEventType::PasswordChanged, user: $user);

        $this->recienEntrado($user)
            ->get(route('primer-ingreso'))
            ->assertInertia(fn (Assert $page) => $page->where('wasReset', true));
    }

    public function test_recien_entrado_la_elige_sin_repetir_la_temporal(): void
    {
        $user = $this->conClaveProvisoria();

        DB::table('sessions')->insert([
            'id' => 'otra-sesion',
            'user_id' => $user->id,
            'ip_address' => '10.0.0.9',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/138.0',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        $this->recienEntrado($user)
            ->put(route('primer-ingreso.update'), $this->nueva())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('inicio'));

        $user->refresh();

        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check(self::NUEVA, $user->password));

        // El mismo rastro que deja el cambio desde Mi cuenta: los dos pasan
        // por ChangeOwnPassword.
        $evento = UserLoginEvent::query()
            ->where('user_id', $user->id)
            ->where('event_type', LoginEventType::PasswordChanged->value)
            ->sole();
        $this->assertSame('cambio obligatorio', $evento->meta['reason'] ?? null);

        // Si alguien más había entrado con la temporal, queda afuera.
        $this->assertFalse(DB::table('sessions')->where('id', 'otra-sesion')->exists());
    }

    public function test_no_acepta_quedarse_con_la_temporal(): void
    {
        // «Cambiarla» por sí misma dejaría al administrador conociéndola
        // para siempre, y la marca bajada diría lo contrario.
        $user = $this->conClaveProvisoria();

        $this->recienEntrado($user)
            ->put(route('primer-ingreso.update'), $this->nueva(self::TEMPORAL))
            ->assertSessionHasErrors([
                'password' => __('validation.custom.password.same_as_current'),
            ]);

        $this->assertTrue($user->refresh()->must_change_password);
    }

    public function test_la_nueva_tiene_que_cumplir_la_politica(): void
    {
        $user = $this->conClaveProvisoria();

        $this->recienEntrado($user)
            ->put(route('primer-ingreso.update'), $this->nueva('corta'))
            ->assertSessionHasErrors('password');

        $this->assertTrue($user->refresh()->must_change_password);
    }

    public function test_pasada_la_ventana_la_pantalla_pide_la_temporal(): void
    {
        $user = $this->conClaveProvisoria();
        $vencida = (int) config('auth.initial_password_timeout') + 60;

        $this->hace($vencida, $user)
            ->get(route('primer-ingreso'))
            ->assertInertia(fn (Assert $page) => $page->where('requiresCurrentPassword', true));

        // Sin la temporal, no: es la sesión abandonada que encontró otro.
        $this->hace($vencida, $user)
            ->put(route('primer-ingreso.update'), $this->nueva())
            ->assertSessionHasErrors('current_password');

        $this->hace($vencida, $user)
            ->put(route('primer-ingreso.update'), [
                'current_password' => 'no-es-esta',
                ...$this->nueva(),
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue($user->refresh()->must_change_password);

        $this->hace($vencida, $user)
            ->put(route('primer-ingreso.update'), [
                'current_password' => self::TEMPORAL,
                ...$this->nueva(),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('inicio'));

        $this->assertFalse($user->refresh()->must_change_password);
    }

    public function test_dentro_de_la_ventana_no_la_pide(): void
    {
        $user = $this->conClaveProvisoria();
        $vigente = (int) config('auth.initial_password_timeout') - 60;

        $this->hace($vigente, $user)
            ->put(route('primer-ingreso.update'), $this->nueva())
            ->assertSessionHasNoErrors();

        $this->assertFalse($user->refresh()->must_change_password);
    }

    public function test_una_sesion_que_nunca_confirmo_la_contrasena_la_pide(): void
    {
        // Una sesión abierta por passkey, o por un camino que no pasó por
        // el login con contraseña: no hay nada que pruebe quién es.
        $user = $this->conClaveProvisoria();

        $this->actingAs($user)
            ->put(route('primer-ingreso.update'), $this->nueva())
            ->assertSessionHasErrors('current_password');
    }

    public function test_sin_la_marca_la_pantalla_no_es_un_atajo(): void
    {
        // Si no, cualquiera con una sesión abierta cambiaría la clave sin
        // saber la actual.
        $user = User::factory()->create();
        $user->assignRole('administrativo');

        $this->recienEntrado($user)
            ->get(route('primer-ingreso'))
            ->assertRedirect(route('inicio'));

        $this->recienEntrado($user)
            ->put(route('primer-ingreso.update'), $this->nueva())
            ->assertRedirect(route('inicio'));

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_sin_sesion_lleva_al_login(): void
    {
        $this->get(route('primer-ingreso'))->assertRedirect(route('login'));
    }
}
