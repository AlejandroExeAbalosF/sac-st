<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Navigation\Breadcrumbs;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Pantallas que informan el `status` por su cuenta.
     *
     * Fortify se lo pasa como prop a su vista y la pantalla lo dibuja dentro
     * de la tarjeta, que es donde se lee: «te enviamos el enlace» pertenece
     * al formulario que lo pidió, no a una esquina de la ventana. Sin esta
     * lista el puente lo duplicaría —card y toast a la vez— y en
     * `verification.notice` además tostaría el literal
     * `verification-link-sent`, que es un slug y no un mensaje.
     *
     * @var list<string>
     */
    private const PANTALLAS_CON_AVISO_PROPIO = [
        'login',
        'password.request',
        'verification.notice',
    ];

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $this->flashLegacyStatusAsToast($request);

        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,

                /*
                 * El rol viaja en cada respuesta y se muestra junto al
                 * nombre. En un sistema con cuatro roles y permisos
                 * distintos, saber con cuál se está operando evita la
                 * pregunta más frecuente del soporte: «¿por qué no puedo
                 * validar este egreso?».
                 *
                 * Va acá y no en cada controlador porque es información
                 * del encabezado, que vive en el layout persistente: si
                 * se cargara una sola vez quedaría congelada al cambiar
                 * de pantalla.
                 */
                'role' => $user?->getRoleNames()->first(),

                /*
                 * Los permisos que la barra lateral necesita para decidir
                 * qué grupos muestra. Solo esos: el juego completo son
                 * sesenta banderas viajando en cada respuesta para usar
                 * tres.
                 *
                 * Van resueltos y no como nombres de rol porque los
                 * permisos de cada rol se editan desde Configuración:
                 * preguntar «¿es administrador?» dejaría de ser la
                 * pregunta correcta el día que el área mueva uno.
                 */
                'can' => $user === null ? [] : [
                    'usuarios.ver' => $user->can('usuarios.ver'),
                    'roles.gestionar' => $user->can('roles.gestionar'),
                    'auditoria.accesos.ver' => $user->can('auditoria.accesos.ver'),
                    'auditoria.operaciones.ver' => $user->can('auditoria.operaciones.ver'),
                    /*
                     * «Egresos» es el único ítem de Operación que no le
                     * corresponde a todos los roles: el de consulta no
                     * trabaja colas. Sin esta bandera la barra lo mostraría
                     * igual y la puerta terminaría en un 403.
                     */
                    'egresos.registrar' => $user->can('egresos.registrar'),
                ],
            ],
            'flash' => [
                /*
                 * La contraseña temporal de un alta o un restablecimiento.
                 * Viaja una sola vez y no queda guardada en ningún lado:
                 * si se pierde, se restablece de nuevo, que es exactamente
                 * lo que corresponde cuando nadie sabe quién la tiene.
                 */
                'temporaryPassword' => fn () => $request->session()->get('temporaryPassword'),
                /*
                 * El período cerrado que frenó una operación. Va con el
                 * permiso resuelto: el diálogo ofrece ir a Cierres solo a
                 * quien puede hacer algo ahí.
                 */
                'closedPeriod' => function () use ($request): ?array {
                    $aviso = $request->session()->get('closedPeriod');

                    if (! is_array($aviso)) {
                        return null;
                    }

                    return [
                        ...$aviso,
                        'canSeeClosings' => $request->user()?->can('cierres.ver') ?? false,
                        /*
                         * Identifica **este** rechazo. El diálogo vive en el
                         * layout persistente, que no se remonta al navegar:
                         * sin algo que distinga una ocurrencia de la
                         * siguiente, un segundo intento idéntico se abriría
                         * descartado.
                         */
                        'occurrence' => (string) Str::uuid(),
                    ];
                },
            ],

            /*
             * El camino de migas se arma en el servidor, con los modelos ya
             * vinculados por la ruta: es lo que permite que el encabezado diga
             * «EXP-1234/2026» y no «Detalle del expediente». El árbol completo
             * vive en routes/breadcrumbs.php.
             *
             * Va como prop compartida y no declarada por pantalla porque el
             * encabezado pertenece al layout persistente, igual que el rol.
             */
            'breadcrumbs' => fn (): array => Breadcrumbs::resolve(
                $request->route()?->getName(),
                $request->route()?->parameters() ?? [],
            ),

            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',

            /*
             * Qué direcciones pueden mirarse dentro de un `<iframe>`.
             *
             * Van las plantillas de las rutas declaradas en
             * `security.headers.same_origin_frame_routes`, que es la lista
             * que decide las cabeceras. El front la usa para avisar cuando
             * alguien enmarca un documento sin declararlo: sin eso el
             * navegador descarta la respuesta y el visor queda en blanco
             * sin ningún error del lado del servidor. Pasó tres veces.
             *
             * Se comparte la plantilla y no el nombre de la ruta porque lo
             * que el componente tiene en la mano es una URL. Cotejar lo
             * mismo que el navegador va a pedir cierra el circuito; hacer
             * que cada visor repita un nombre de ruta a mano lo reabre.
             */
            'framableDocuments' => fn (): array => $this->plantillasEnmarcables(),
        ];
    }

    /**
     * Las plantillas de las rutas que sí pueden enmarcarse.
     *
     * `haberes/cuotas/{installment}/recibo/previsualizar`, con el
     * parámetro sin resolver: el front la convierte en una expresión y la
     * coteja contra la dirección que está por enmarcar.
     *
     * Un nombre que no existe se saltea en silencio. No hace falta gritar
     * acá: `SecurityHeadersTest` ya falla cuando eso pasa, y con un
     * mensaje que explica qué corregir.
     *
     * @return list<string>
     */
    private function plantillasEnmarcables(): array
    {
        $nombres = config('security.headers.same_origin_frame_routes', []);

        if (! is_array($nombres)) {
            return [];
        }

        $rutas = app('router')->getRoutes();

        return array_values(array_filter(array_map(
            fn (mixed $nombre): ?string => is_string($nombre)
                ? $rutas->getByName($nombre)?->uri()
                : null,
            $nombres,
        )));
    }

    /**
     * Lleva los `status` históricos al canal efímero de Inertia.
     *
     * Los controladores existentes todavía confirman operaciones con
     * `->with('status', ...)`. Compartir ese valor como prop lo dejaba dentro
     * de la página y obligaba a cada pantalla a dibujar y cerrar su propio
     * aviso. El flash nativo no se guarda en el historial del navegador y el
     * layout persistente lo presenta una sola vez como toast.
     *
     * Un toast tipado explícito tiene prioridad: así una operación puede
     * comunicar `warning` o `error` sin que este puente la convierta en éxito.
     */
    private function flashLegacyStatusAsToast(Request $request): void
    {
        if ($request->routeIs(...self::PANTALLAS_CON_AVISO_PROPIO)) {
            return;
        }

        if (array_key_exists('toast', Inertia::getFlashed($request))) {
            return;
        }

        $status = $request->session()->get('status');

        if (! is_string($status) || trim($status) === '') {
            return;
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $status,
        ]);
    }
}
