import { Link, usePage } from '@inertiajs/react';
import { CalendarX, Lock } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { date as formatDate } from '@/lib/format';
import { index as cierres } from '@/routes/caja/cierres';

type Aviso = {
    periodType: string;
    from: string;
    to: string;
    attemptedOn: string;
    message: string;
    canSeeClosings: boolean;
    /** Identifica este rechazo, para poder descartarlo sin tapar el próximo. */
    occurrence: string;
};

/**
 * La caja está cerrada para esa fecha.
 *
 * **Es una situación prevista, no una falla.** Antes llegaba como una
 * `QueryException` del trigger `financial_events_period_open`: una traza de
 * PostgreSQL en la cara de quien solo quería emitir un recibo, sin decirle
 * qué pasó ni dónde se arregla.
 *
 * Vive en el layout persistente y no en cada pantalla porque cualquier
 * operación que mueva dinero puede toparse con esto —cobrar, imputar,
 * entregar, trasladar al banco— y la explicación es siempre la misma.
 *
 * El camino de salida se ofrece solo a quien puede recorrerlo: sin
 * `cierres.ver`, el enlace llevaría a un 403.
 */
export default function ClosedPeriodDialog() {
    const { flash } = usePage().props as unknown as {
        flash?: { closedPeriod?: Aviso | null };
    };

    const aviso = flash?.closedPeriod ?? null;

    /*
     * Lo que se guarda es lo **descartado**, no lo abierto: el estado se
     * deriva y no hay que sincronizarlo con la prop. Se recuerda por
     * ocurrencia porque el layout no se remonta al navegar — si se guardara
     * un booleano, el segundo intento idéntico nacería cerrado.
     */
    const [descartado, setDescartado] = useState<string | null>(null);

    if (aviso === null) {
        return null;
    }

    const abierto = aviso.occurrence !== descartado;
    const cerrar = () => setDescartado(aviso.occurrence);

    return (
        <Dialog open={abierto} onOpenChange={(open) => open || cerrar()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <Lock className="size-4 text-warning-strong" />
                        La caja está cerrada para esa fecha
                    </DialogTitle>
                    <DialogDescription>
                        La operación no se registró. Un período cerrado tiene
                        sus totales congelados, así que no admite movimientos
                        con fecha adentro.
                    </DialogDescription>
                </DialogHeader>

                <div className="rounded-lg border bg-muted/40 px-4 py-3 text-sm">
                    <p className="flex items-center gap-2">
                        <CalendarX className="size-4 shrink-0 text-muted-foreground" />
                        <span>
                            Cierre <strong>{aviso.periodType}</strong> del{' '}
                            {formatDate(aviso.from)} al {formatDate(aviso.to)}
                        </span>
                    </p>
                    <p className="mt-2 text-muted-foreground">
                        Se intentó registrar un movimiento con fecha{' '}
                        {formatDate(aviso.attemptedOn)}.
                    </p>
                </div>

                <p className="text-sm text-muted-foreground">
                    {aviso.canSeeClosings
                        ? 'Si el movimiento corresponde igual, hay que reabrir el período —queda registrado quién y por qué— y volver a intentarlo.'
                        : 'Si el movimiento corresponde igual, pedile a quien lleva los cierres que reabra el período.'}
                </p>

                <DialogFooter>
                    <Button variant="outline" onClick={cerrar} type="button">
                        Entendido
                    </Button>
                    {aviso.canSeeClosings && (
                        <Button asChild>
                            <Link href={cierres().url} onClick={cerrar}>
                                Ir a Cierres
                            </Link>
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
