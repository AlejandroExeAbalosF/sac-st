<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use App\Modules\Shared\Enums\LoginEventType;
use App\Modules\Shared\Models\UserLoginEvent;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;
use Tests\TestCase;

/**
 * Una sesión dura mientras la persona esté habilitada, y solo si nació de
 * un ingreso explícito.
 *
 * El control de usuario activo estaba solo en el login con contraseña. La
 * cookie de «recordarme» (400 días) y la passkey lo salteaban, y dar de
 * baja o restablecer la clave borraba las sesiones pero no la cookie: el
 * usuario volvía a entrar solo al día siguiente.
 */
class SesionesSegurasTest extends TestCase
{
    use RefreshDatabase;

    public function test_desactivar_saca_al_usuario_en_el_pedido_siguiente(): void
    {
        $user = $this->operador();

        $this->actingAs($user)->get(route('mi-cuenta.perfil'))->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->get(route('mi-cuenta.perfil'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('username');

        $this->assertGuest();
        $this->assertTrue(
            UserLoginEvent::query()
                ->where('user_id', $user->id)
                ->where('event_type', LoginEventType::SessionRevoked->value)
                ->exists(),
        );
    }

    /**
     * «Recordarme» ya no existe, y una cookie que alguien conserve no
     * resucita la sesión: el ingreso por cookie de recordatorio se rechaza.
     */
    public function test_una_cookie_de_recordatorio_no_abre_sesion(): void
    {
        $user = $this->operador();
        $user->forceFill(['remember_token' => 'token-de-recordatorio-valido'])->save();

        $this->withCookie(
            Auth::guard()->getRecallerName(),
            $user->id.'|token-de-recordatorio-valido|'.$user->getAuthPassword(),
        )
            ->get(route('mi-cuenta.perfil'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    /**
     * Cerrar el navegador cierra la sesión: la cookie sale sin fecha.
     *
     * No es una garantía —un navegador que restaura la sesión la conserva—,
     * pero es el valor por defecto y no depende del `.env` del despliegue.
     */
    public function test_la_cookie_de_sesion_no_sobrevive_al_navegador(): void
    {
        $cookie = $this->get(route('login'))->getCookie(config('session.cookie'), false);

        $this->assertNotNull($cookie);
        $this->assertSame(0, $cookie->getExpiresTime());
    }

    public function test_el_login_no_ofrece_recordar_la_sesion(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('Recordar sesión');
    }

    /** Cerrar las sesiones invalida también toda cookie de recordatorio. */
    public function test_restablecer_la_clave_rota_el_token_de_recordatorio(): void
    {
        $admin = $this->operador('administrador');
        $user = $this->operador();
        $user->forceFill(['remember_token' => 'token-viejo'])->save();

        $this->actingAs($admin)
            ->post(route('configuracion.usuarios.contrasena', $user))
            ->assertSessionHasNoErrors();

        $this->assertNotSame('token-viejo', $user->refresh()->remember_token);
    }

    public function test_la_passkey_de_un_usuario_inactivo_no_autoriza_el_ingreso(): void
    {
        $user = $this->operador();
        $user->forceFill(['is_active' => false])->save();

        $passkey = new Passkey;
        $passkey->setRelation('user', $user);

        $this->assertFalse(Passkeys::allowsLogin(Request::create('/'), $passkey));

        $user->forceFill(['is_active' => true])->save();

        $this->assertTrue(Passkeys::allowsLogin(Request::create('/'), $passkey));
    }

    /** Quien cambia la clave porque sospecha que otro la conoce, lo saca. */
    public function test_cambiar_la_clave_cierra_las_otras_sesiones(): void
    {
        $user = $this->operador();
        $user->forceFill(['remember_token' => 'token-viejo'])->save();
        $this->sesionAbierta($user, 'otra-sesion');

        $this->actingAs($user)
            ->put(route('user-password.update'), [
                'current_password' => 'password',
                'password' => 'Contrasena.Nueva.2026',
                'password_confirmation' => 'Contrasena.Nueva.2026',
            ])
            ->assertSessionHasNoErrors();

        $this->assertFalse(DB::table('sessions')->where('id', 'otra-sesion')->exists());
        $this->assertNotSame('token-viejo', $user->refresh()->remember_token);
    }

    public function test_recuperar_la_clave_por_correo_cierra_todas_las_sesiones(): void
    {
        $this->skipUnlessFortifyHas(Features::resetPasswords());
        Notification::fake();

        $user = $this->operador();
        $this->sesionAbierta($user, 'sesion-perdida');

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $this->post(route('password.update'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'Contrasena.Nueva.2026',
                'password_confirmation' => 'Contrasena.Nueva.2026',
            ])->assertSessionHasNoErrors();

            return true;
        });

        $this->assertFalse(DB::table('sessions')->where('id', 'sesion-perdida')->exists());
    }

    /**
     * «Olvidé mi contraseña» no dice si el correo existe.
     *
     * Si respondiera distinto, la pantalla serviría para averiguar qué
     * casillas tienen usuario.
     */
    public function test_la_recuperacion_responde_igual_exista_o_no_el_correo(): void
    {
        $this->skipUnlessFortifyHas(Features::resetPasswords());
        Notification::fake();

        $user = $this->operador();

        $existente = $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => $user->email]);
        $inexistente = $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => 'nadie@example.com']);

        $existente->assertSessionHasNoErrors()->assertSessionHas('status');
        $inexistente->assertSessionHasNoErrors()->assertSessionHas('status');
        $this->assertSame(
            $existente->getSession()->get('status'),
            $inexistente->getSession()->get('status'),
        );
    }

    /** Pedir enlaces sin tope sirve para inundar casillas o probar correos. */
    public function test_la_recuperacion_tiene_limite_de_intentos(): void
    {
        $this->skipUnlessFortifyHas(Features::resetPasswords());
        Notification::fake();

        for ($i = 1; $i <= 5; $i++) {
            $this->post(route('password.email'), ['email' => "persona{$i}@example.com"])
                ->assertRedirect();
        }

        $this->post(route('password.email'), ['email' => 'persona6@example.com'])
            ->assertTooManyRequests();
    }

    /** Un ataque contra el segundo factor deja rastro. */
    public function test_un_codigo_de_segundo_factor_invalido_queda_en_el_historial(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        // Un secreto base32 de verdad: el de la fábrica es demasiado corto
        // para Google2FA y el desafío reventaría antes de evaluar el código.
        $user = User::factory()->withTwoFactor()->create([
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'),
        ]);

        $this->post(route('login'), [
            'username' => $user->username,
            'password' => 'password',
        ]);

        $this->post('/two-factor-challenge', ['code' => '000000']);

        $this->assertTrue(
            UserLoginEvent::query()
                ->where('user_id', $user->id)
                ->where('event_type', LoginEventType::TwoFactorFailed->value)
                ->exists(),
        );
    }

    private function sesionAbierta(User $user, string $id): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => '10.0.0.9',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/138.0',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);
    }
}
