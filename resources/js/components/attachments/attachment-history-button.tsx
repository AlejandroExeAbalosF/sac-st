import { router } from '@inertiajs/react';
import { History, Loader2 } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { history as historyRoute } from '@/routes/adjuntos';
import { AttachmentGallery } from './attachment-gallery';
import type { Attachment } from './types';

type Historial = App.Modules.Shared.Data.AttachmentHistoryData;

function DialogoDeHistorial({
    history,
    onClose,
}: {
    history: Historial;
    onClose: () => void;
}) {
    const [archivos, setArchivos] = useState<Attachment[] | null>(null);
    const [error, setError] = useState(false);

    const cargar = useCallback(() => {
        const url = historyRoute({
            query: {
                subject: history.subjectType,
                id: history.subjectId,
                type: history.documentType ?? undefined,
            },
        }).url;

        fetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((r) => {
                if (!r.ok) {
                    throw new Error(String(r.status));
                }

                return r.json() as Promise<Attachment[]>;
            })
            .then((lista) => {
                setArchivos(lista);
                setError(false);
            })
            .catch(() => setError(true));
    }, [history.subjectType, history.subjectId, history.documentType]);

    // Al abrir, y otra vez después de anular o corregir algo desde acá: la
    // pantalla de atrás se recarga sola, este listado no.
    useEffect(() => {
        cargar();

        return router.on('success', cargar);
    }, [cargar]);

    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Historial de archivos</DialogTitle>
                    <DialogDescription>
                        Todos los que se cargaron, del más nuevo al más viejo.
                        Los anulados siguen guardados.
                    </DialogDescription>
                </DialogHeader>

                {error ? (
                    <p className="text-sm text-destructive-strong">
                        No se pudo traer el historial.
                    </p>
                ) : archivos === null ? (
                    <p className="flex items-center gap-2 text-sm text-muted-foreground">
                        <Loader2 className="size-4 animate-spin" />
                        Buscando los archivos…
                    </p>
                ) : (
                    <AttachmentGallery attachments={archivos} showVoided />
                )}
            </DialogContent>
        </Dialog>
    );
}

/**
 * El camino a todos los archivos de algo, anulados incluidos.
 *
 * Va al lado del archivo vigente en las pantallas que muestran uno solo —el
 * ticket de una cuota, la foto de un papel—. Aparece cuando hay algo más
 * que ver que lo que ya está a la vista: otro archivo o uno anulado. **Con
 * el único anulado no hay miniatura**, y este botón es el único camino para
 * llegar a él.
 */
export function AttachmentHistoryButton({
    history,
    hasCurrent,
    className,
}: {
    history: Historial | null;
    /** Si la pantalla ya muestra el vigente al lado. */
    hasCurrent: boolean;
    className?: string;
}) {
    const [abierto, setAbierto] = useState(false);

    if (history === null || history.total <= (hasCurrent ? 1 : 0)) {
        return null;
    }

    return (
        <>
            <button
                type="button"
                onClick={() => setAbierto(true)}
                className={cn(
                    'inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground hover:underline',
                    className,
                )}
            >
                <History className="size-3.5" aria-hidden="true" />
                Historial ({history.total})
                {history.voided > 0 && (
                    <span className="text-destructive-strong">
                        · {history.voided} anulado
                        {history.voided === 1 ? '' : 's'}
                    </span>
                )}
            </button>
            {abierto && (
                <DialogoDeHistorial
                    history={history}
                    onClose={() => setAbierto(false)}
                />
            )}
        </>
    );
}
