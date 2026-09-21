<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Listeners\ConfirmPasswordOnLogin;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Entrar con la contraseña vale como haberla confirmado.
 *
 * `RequirePassword` cubre un caso puntual: la sesión que quedó abierta y
 * alguien más se sienta en esa máquina. Pedírsela de nuevo a quien la tipeó
 * hace tres segundos no cubre nada; lo que hacía era frenar al usuario
 * recién dado de alta —obligado a cambiar su clave provisoria— con una
 * pantalla más en el camino.
 */
class PasswordConfirmationOnLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_entrar_con_contrasena_abre_la_seguridad_sin_pedirla_de_nuevo(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'username' => $user->username,
            'password' => 'password',
        ])->assertRedirect();

        $this->get(route('mi-cuenta.seguridad'))->assertOk();
    }

    public function test_una_sesion_sin_la_marca_sigue_pidiendo_la_contrasena(): void
    {
        // Es el caso que la confirmación existe para cubrir: una sesión
        // abierta hace horas, o alguien que llegó por otro camino.
        $this
            ->actingAs(User::factory()->create())
            ->get(route('mi-cuenta.seguridad'))
            ->assertRedirect(route('password.confirm'));
    }

    public function test_la_marca_caduca_con_la_ventana_configurada(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'username' => $user->username,
            'password' => 'password',
        ]);

        $vencida = time() - (int) config('auth.password_timeout') - 60;

        $this
            ->withSession(['auth.password_confirmed_at' => $vencida])
            ->actingAs($user)
            ->get(route('mi-cuenta.seguridad'))
            ->assertRedirect(route('password.confirm'));
    }

    /**
     * El listener decide por la ruta del ingreso, no por el hecho de haber
     * entrado. Se lo invoca directo porque completar un ingreso con passkey o
     * con segundo factor exige un autenticador de verdad, y lo que hay que
     * verificar es la decision, no el mecanismo.
     *
     * @param  bool  $esperada  Si esa ruta tiene que dejar la marca.
     */
    #[DataProvider('rutasDeIngreso')]
    public function test_solo_las_rutas_de_contrasena_dejan_la_marca(string $ruta, bool $esperada): void
    {
        $user = User::factory()->create();

        $request = Request::create(route($ruta), 'POST');
        $request->setLaravelSession(app('session.store'));
        $request->setRouteResolver(
            fn () => app('router')->getRoutes()->getByName($ruta),
        );

        app(ConfirmPasswordOnLogin::class)->onLogin(new Login('web', $user, false), $request);

        $this->assertSame(
            $esperada,
            $request->session()->has('auth.password_confirmed_at'),
        );
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function rutasDeIngreso(): array
    {
        return [
            'ingreso con contrasena' => ['login.store', true],
            // El request trae el codigo, pero la contrasena ya se verifico en
            // el paso anterior de la misma sesion.
            'segundo factor' => ['two-factor.login.store', true],
            // Nunca tipeo una contrasena: tocar la seguridad se la exige.
            'ingreso con passkey' => ['passkey.login', false],
        ];
    }

    public function test_la_pantalla_de_confirmacion_vive_dentro_del_sistema(): void
    {
        $user = User::factory()->create();

        // Con la portada partida de las pantallas de acceso era
        // indistinguible del login, y quien ya había entrado creía que lo
        // habían echado. El camino de migas es lo que prueba que ahora se
        // dibuja adentro.
        $this
            ->actingAs($user)
            ->get(route('password.confirm'))
            ->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->component('mi-cuenta/confirmar-contrasena')
                    ->where('breadcrumbs.0.title', 'Mi cuenta')
                    ->where('breadcrumbs.1.title', 'Confirmar la contraseña')
            );
    }
}
