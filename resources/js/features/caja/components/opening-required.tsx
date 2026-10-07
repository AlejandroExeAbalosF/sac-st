import { Link } from '@inertiajs/react';
import { BookOpen } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { aperturaPendiente, nombreDeMoneda } from '@/features/caja/apertura';
import { date as formatDate } from '@/lib/format';
import type { CurrencyCode } from '@/lib/format';

/**
 * El libro de esta moneda todavía no se abrió: la pantalla no tiene nada
 * que mostrar ni que hacer.
 *
 * Reemplaza al contenido, no lo acompaña. Sin apertura los saldos son
 * cero, y la base rechaza cualquier cobro, arqueo o cierre en ese libro;
 * mostrar botones que van a fallar sería peor que no mostrarlos. El
 * selector de moneda queda en el encabezado de cada pantalla, así que
 * volver al otro libro sigue a un clic.
 */
export default function OpeningRequired({
    moneda,
    puedeAbrir,
}: {
    moneda: CurrencyCode;
    puedeAbrir: boolean;
}) {
    const aviso = aperturaPendiente(moneda, puedeAbrir);

    return (
        <div className="flex flex-col items-start gap-4 rounded-lg border border-warning-soft bg-warning-soft px-5 py-5 text-warning-strong sm:flex-row sm:items-center">
            <BookOpen className="size-6 shrink-0" />
            <div className="min-w-0 flex-1 text-sm">
                <p className="font-medium">{aviso.titulo}.</p>
                <p className="mt-1">{aviso.detalle}</p>
            </div>
            {aviso.href !== null ? (
                <Button variant="outline" asChild>
                    <Link href={aviso.href}>{aviso.accion}</Link>
                </Button>
            ) : (
                <p className="text-sm font-medium">{aviso.accion}</p>
            )}
        </div>
    );
}

/**
 * El día mirado es anterior a la apertura del libro.
 *
 * Se llega desde el calendario o moviendo la fecha hacia atrás. Ese día el
 * libro no tiene nada —lo que había quedó resumido en la apertura— y la
 * base rechaza contar, cerrar o registrar con esa fecha, así que en lugar
 * del flujo del día va la explicación y el camino al primer día del libro.
 */
export function BeforeOpening({
    moneda,
    fechaApertura,
    hrefApertura,
}: {
    moneda: CurrencyCode;
    fechaApertura: string;
    /** El día de la apertura en la Caja del día. */
    hrefApertura: string;
}) {
    return (
        <div className="flex flex-col items-start gap-4 rounded-lg border bg-muted/40 px-5 py-5 text-sm sm:flex-row sm:items-center">
            <BookOpen className="size-6 shrink-0 text-muted-foreground" />
            <div className="min-w-0 flex-1">
                <p className="font-medium">
                    Este día es anterior a la apertura de los libros en{' '}
                    {nombreDeMoneda(moneda)} ({formatDate(fechaApertura)}).
                </p>
                <p className="mt-1 text-muted-foreground">
                    Lo que había hasta ese día quedó declarado en la apertura, y
                    sus papeles se cargan como cuota histórica. El sistema no
                    registra movimientos, arqueos ni cierres con fecha anterior.
                </p>
            </div>
            <Button variant="outline" asChild>
                <Link href={hrefApertura}>Ir al día de la apertura</Link>
            </Button>
        </div>
    );
}
