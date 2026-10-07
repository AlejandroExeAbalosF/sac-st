import { Link, usePage } from '@inertiajs/react';
import { BookOpen } from 'lucide-react';
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
import { aperturaPendiente } from '@/features/caja/apertura';
import type { CurrencyCode } from '@/lib/format';

type Aviso = {
    currency: CurrencyCode;
    currencyLabel: string;
    attemptedOn: string;
    /** La fecha de la apertura, cuando lo que falló es ser anterior a ella. */
    openedOn: string | null;
    message: string;
    canOpen: boolean;
    /** Identifica este rechazo, para poder descartarlo sin tapar el próximo. */
    occurrence: string;
};

/**
 * La operación chocó con un libro sin apertura, o con fecha anterior a ella.
 *
 * Como el período cerrado, es una situación prevista y con salida, y puede
 * aparecer desde cualquier pantalla que mueva dinero —un cobro de un haber
 * en dólares, por ejemplo—, así que vive en el layout persistente.
 *
 * Cuando la apertura existe y el problema es la fecha, no hay nada que
 * abrir: se muestra el motivo y nada más.
 */
export default function MissingOpeningDialog() {
    const { flash } = usePage().props as unknown as {
        flash?: { missingOpening?: Aviso | null };
    };

    const aviso = flash?.missingOpening ?? null;

    /* Ver `ClosedPeriodDialog`: se recuerda lo descartado, por ocurrencia. */
    const [descartado, setDescartado] = useState<string | null>(null);

    if (aviso === null) {
        return null;
    }

    const abierto = aviso.occurrence !== descartado;
    const cerrar = () => setDescartado(aviso.occurrence);
    const sinApertura = aviso.openedOn === null;
    const salida = aperturaPendiente(aviso.currency, aviso.canOpen);

    return (
        <Dialog open={abierto} onOpenChange={(open) => open || cerrar()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <BookOpen className="size-4 text-warning-strong" />
                        {sinApertura
                            ? salida.titulo
                            : 'La fecha es anterior a la apertura'}
                    </DialogTitle>
                    <DialogDescription>
                        La operación no se registró.
                    </DialogDescription>
                </DialogHeader>

                {/*
                 * Sin apertura, el título ya lo dice: alcanza con explicar
                 * qué falta y quién lo hace. Con la fecha anterior, el
                 * mensaje del servidor es el que trae las dos fechas.
                 */}
                {sinApertura ? (
                    <div className="space-y-2 text-sm">
                        <p>{salida.detalle}</p>
                        {salida.href === null && (
                            <p className="font-medium">{salida.accion}</p>
                        )}
                    </div>
                ) : (
                    <p className="text-sm">{aviso.message}</p>
                )}

                <DialogFooter>
                    <Button variant="outline" onClick={cerrar} type="button">
                        Entendido
                    </Button>
                    {sinApertura && salida.href !== null && (
                        <Button asChild>
                            <Link href={salida.href} onClick={cerrar}>
                                {salida.accion}
                            </Link>
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
