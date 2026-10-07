import { Link } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import { useDrawer } from '@/features/drawer/drawer-context';
import { useIsMobile } from '@/hooks/use-mobile';

/*
 * Las piezas con que se arman los paneles laterales: un bloque con título,
 * un dato con su rótulo y un enlace que, en pantalla chica, cierra el
 * panel. Las comparten el panel de un recibo y el de una cuota, para que
 * los dos se lean igual.
 */

export function Bloque({
    titulo,
    children,
}: {
    titulo: string;
    children: React.ReactNode;
}) {
    return (
        <section>
            <h3 className="mb-2 text-[0.6875rem] tracking-wide text-field-label uppercase">
                {titulo}
            </h3>
            <dl className="grid grid-cols-2 gap-x-4 gap-y-3 rounded-lg border p-3 text-sm">
                {children}
            </dl>
        </section>
    );
}

export function Dato({
    etiqueta,
    children,
}: {
    etiqueta: string;
    children: React.ReactNode;
}) {
    return (
        <div className="min-w-0">
            <dt className="text-xs text-muted-foreground">{etiqueta}</dt>
            <dd className="mt-0.5 break-words">{children}</dd>
        </div>
    );
}

/**
 * Un enlace que, en pantalla chica, además cierra el panel.
 *
 * En el escritorio el panel convive con la pantalla de atrás y dejarlo
 * abierto es útil: se sigue viendo de qué comprobante se vino. En una
 * pantalla angosta ocupa el ancho completo, así que ir al haber lo dejaría
 * tapando justo lo que se fue a ver.
 *
 * El umbral es el de `useIsMobile`, que es la noción de «pantalla chica»
 * que el resto del sistema ya usa. No coincide exacto con el ancho al que
 * este panel pasa a ocupar todo, y no hace falta: entre uno y otro la
 * pantalla de atrás queda igual de tapada.
 */
export function Enlace({
    href,
    children,
}: {
    href: string;
    children: React.ReactNode;
}) {
    const { closeDrawer } = useDrawer();
    const pantallaChica = useIsMobile();

    return (
        <Link
            href={href}
            onClick={() => pantallaChica && closeDrawer()}
            className="inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-xs font-medium transition-colors hover:bg-muted"
        >
            <ExternalLink className="size-3.5" aria-hidden="true" />
            {children}
        </Link>
    );
}
