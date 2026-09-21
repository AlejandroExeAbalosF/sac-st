<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\ConfirmPasswordOnLogin;
use App\Listeners\RecordAuthEvent;
use App\Models\User;
use App\Modules\Shared\Enums\SystemRole;
use App\Support\Navigation\Breadcrumbs;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
        $this->configureAuthEventLogging();
        $this->configureNavigation();
    }

    /**
     * Carga el árbol de navegación.
     *
     * Se vacía antes de cargar para que un rearranque en caliente —Octane, o
     * el propio suite de tests, que bootea la aplicación una vez por test— no
     * acumule caminos de una carga anterior.
     */
    protected function configureNavigation(): void
    {
        Breadcrumbs::flush();

        require base_path('routes/breadcrumbs.php');
    }

    /**
     * El rol `administrador` recibe todo sin enumerar permisos.
     *
     * Se hace con `Gate::before` y no otorgándole cada permiso, para que
     * agregar un permiso nuevo en una fase futura no exija acordarse de
     * dárselo también al administrador.
     *
     * **`super-admin` recibe todo también, y antes que nadie.** No es «el
     * administrador con algunos permisos más»: la primera línea le concede
     * cualquier capacidad sin filtro.
     *
     * Lo que lo distingue es la excepción del medio, y sin ella el rol no
     * existiría. Las capacidades `dev.*` quedan fuera del comodín del
     * administrador: si las heredara como hereda todo lo demás, un rol
     * «por encima del administrador» sería una etiqueta sin efecto, porque
     * arriba de «todo» no hay nada. Para esas, el administrador pasa por la
     * verificación normal de permisos, que no le da ninguna.
     */
    protected function configureAuthorization(): void
    {
        Gate::before(function ($user, string $ability): ?bool {
            if (! $user instanceof User) {
                return null;
            }

            if ($user->hasRole(SystemRole::SuperAdmin->value)) {
                return true;
            }

            if (SystemRole::isDeveloperAbility($ability)) {
                return null;
            }

            return $user->hasRole(SystemRole::Administrador->value) ? true : null;
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        // Fuera de producción, cualquier lazy loading, atributo descartado
        // en silencio o acceso a una columna inexistente revienta en el
        // acto. En un sistema contable, un dato que "no aparece" es peor
        // que una excepción.
        Model::shouldBeStrict(! app()->isProduction());

        // La política de contraseñas es la misma en todos los entornos: si
        // en desarrollo se admitiera "123456", la política nunca se probaría.
        // Lo único que se reserva a producción es la consulta contra bases
        // de filtraciones conocidas, que exige salida a internet.
        Password::defaults(function (): Password {
            $rules = Password::min(12)->mixedCase()->letters()->numbers()->symbols();

            return app()->isProduction() ? $rules->uncompromised() : $rules;
        });
    }

    /**
     * Conecta los eventos de autenticación con el historial de accesos.
     */
    protected function configureAuthEventLogging(): void
    {
        Event::listen(Login::class, [RecordAuthEvent::class, 'onLogin']);
        /*
         * Entrar con la contraseña vale como haberla confirmado: sin esto,
         * el sistema se la vuelve a pedir tres segundos después de tipearla.
         */
        Event::listen(Login::class, [ConfirmPasswordOnLogin::class, 'onLogin']);
        Event::listen(Failed::class, [RecordAuthEvent::class, 'onFailed']);
        Event::listen(Logout::class, [RecordAuthEvent::class, 'onLogout']);
        Event::listen(Lockout::class, [RecordAuthEvent::class, 'onLockout']);
        Event::listen(PasswordReset::class, [RecordAuthEvent::class, 'onPasswordReset']);
    }
}
