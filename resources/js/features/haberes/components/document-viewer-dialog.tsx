import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import DocumentPreview from './document-preview';
import type { DocumentoParaVer } from './document-preview';

export type { DocumentoParaVer };

/**
 * Los papeles ya emitidos, en grande.
 *
 * Es `DocumentPreview` dentro de un diálogo: la Orden y el Pase viajan
 * juntos al organismo y se revisan juntos, así que el visor pasa de una
 * hoja a la otra con una solapa en vez de obligar a cerrar y abrir.
 *
 * La pantalla que **arma** los documentos abre este mismo diálogo con los
 * borradores: mirar el papel es un acto aparte de completar los campos, y
 * la hoja necesita el ancho entero para leerse.
 */
export default function DocumentViewerDialog({
    titulo,
    descripcion,
    documentos,
    abierto,
    onCerrar,
    onCargado,
    acciones,
}: {
    titulo: string;
    descripcion: string;
    documentos: DocumentoParaVer[];
    abierto: boolean;
    onCerrar: () => void;
    /** Avisa cuando una hoja terminó de renderizar en el visor. */
    onCargado?: (etiqueta: string) => void;
    /**
     * Lo que se puede hacer con el documento que se está mirando.
     *
     * Existe para que el acto que **exige** haber leído la hoja viva donde
     * la hoja está, y no a media pantalla de distancia detrás de un botón
     * apagado. Va al ras izquierdo del pie, lejos de «Cerrar»: son la
     * acción comprometida y la salida, y no se ponen bajo el mismo pulgar.
     */
    acciones?: ReactNode;
}) {
    return (
        <Dialog open={abierto} onOpenChange={(v) => !v && onCerrar()}>
            {/*
             * Sin `flex` ni `max-h`: el alto lo pone la hoja con su propio
             * tope en `vh`. `DialogContent` es `position: fixed` y no tiene
             * altura contra la que un `flex-1` pueda resolver, y por eso la
             * hoja se salía por abajo del diálogo.
             */}
            <DialogContent className="sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>{titulo}</DialogTitle>
                    <DialogDescription>{descripcion}</DialogDescription>
                </DialogHeader>

                <DocumentPreview
                    documentos={documentos}
                    alto="62vh"
                    onCargado={onCargado}
                />

                <DialogFooter>
                    {acciones !== undefined && (
                        <div className="flex flex-wrap items-center gap-x-3 gap-y-2 sm:mr-auto">
                            {acciones}
                        </div>
                    )}
                    <Button variant="outline" onClick={onCerrar}>
                        Cerrar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
