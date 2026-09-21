import { Head, Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { inicio } from '@/routes';
import type { Auth } from '@/types';

type Props = {
    status: number;
    titulo: string;
    detalle: string;
    volver: string;
    ayuda: string;
};

/**
 * El rechazo que ocurre navegando, mostrado adentro del sistema.
 *
 * Cuando Inertia recibe una respuesta que no es suya abre un modal con el
 * HTML crudo: la pantalla de error tapando la aplicación, sin barra lateral y
 * sin forma de seguir salvo recargar. Para los códigos que no significan que
 * algo se rompió —403, 404, 429— el servidor devuelve esta pantalla en su
 * lugar (ver `bootstrap/app.php`), y el operador conserva la navegación.
 *
 * Los textos vienen del servidor, de `lang/es/errores.php`, que es el mismo
 * archivo que usan las vistas Blade: la situación se explica una sola vez.
 *
 * Sirve con sesión y sin ella. Con sesión la envuelve `AppLayout` y la barra
 * lateral ya lleva la marca; sin sesión llega sola, y entonces la marca la
 * pone la propia pantalla. Quién decide eso está en `app.tsx`.
 */
export default function ErrorPage({
    status,
    titulo,
    detalle,
    volver,
    ayuda,
}: Props) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const conSesion = Boolean(auth?.user);

    return (
        <>
            <Head title={titulo} />

            <div
                className={
                    conSesion
                        ? 'flex flex-1 items-center justify-center p-6'
                        : 'flex min-h-dvh items-center justify-center bg-background p-6'
                }
            >
                <div className="w-full max-w-md overflow-hidden rounded-xl border bg-card shadow-sm">
                    {/* El mismo filete que ata la tarjeta del ingreso al panel. */}
                    <div aria-hidden="true" className="h-1 bg-primary/85" />

                    <div className="p-8">
                        {/* Sin sesión no hay barra lateral que identifique al
                            sistema, así que la marca la pone la pantalla. */}
                        {!conSesion && (
                            <div className="mb-7 flex items-center gap-2.5">
                                <AppLogoIcon
                                    className="size-9"
                                    aria-hidden="true"
                                />
                                <div className="leading-tight">
                                    <p className="text-sm font-bold tracking-wide">
                                        SAC
                                    </p>
                                    <p className="text-[11px] text-muted-foreground">
                                        Sistema administrativo contable
                                    </p>
                                </div>
                            </div>
                        )}

                        <p className="text-[11px] font-semibold tracking-[0.16em] text-muted-foreground uppercase">
                            Error {status}
                        </p>
                        <h1 className="mt-2 text-xl font-semibold tracking-tight">
                            {titulo}
                        </h1>
                        <p className="mt-2.5 text-sm text-muted-foreground">
                            {detalle}
                        </p>

                        <Button asChild className="mt-7">
                            <Link href={inicio()}>{volver}</Link>
                        </Button>

                        <p className="mt-7 border-t pt-4 text-xs text-muted-foreground">
                            {ayuda}
                        </p>
                    </div>
                </div>
            </div>
        </>
    );
}
