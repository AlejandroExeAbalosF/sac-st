import { ExternalLink, Printer } from 'lucide-react';
import { useState } from 'react';
import { cn } from '@/lib/utils';
import DocumentFrame from './document-frame';

export type DocumentoParaVer = {
    /** Cómo se llama en la solapa: «Orden de Pago», «Nota de Pase». */
    etiqueta: string;
    urlVista: string;
    urlImpresion: string;
};

/**
 * La hoja, escalada para entrar donde la pongan.
 *
 * Sirve en dos lugares y por eso vive suelta: **al lado del formulario que
 * la está armando**, donde cada cambio se ve al escribirlo, y dentro del
 * diálogo que muestra los documentos ya emitidos.
 *
 * Se mira en HTML y no en PDF a propósito: en PDF el navegador levantaría
 * su propio lector adentro, que pesa más y se ve distinto en cada máquina.
 * El botón de imprimir sí abre el PDF, que es lo que va al papel.
 *
 * Con más de un documento dibuja solapas; con uno solo queda igual que una
 * hoja suelta.
 */
export default function DocumentPreview({
    documentos,
    alto = '60vh',
    compacto = false,
    onCargado,
}: {
    documentos: DocumentoParaVer[];
    /** Hasta dónde puede crecer la hoja; ver `DocumentFrame`. */
    alto?: string;
    /** Al lado del formulario el espacio es poco: achica los controles. */
    compacto?: boolean;
    /**
     * Avisa cuando una hoja terminó de renderizar, con su etiqueta.
     *
     * Lo usa quien necesita saber que el papel se vio de verdad. Abrir el
     * diálogo no alcanza: si el PDF no carga —la trampa de
     * `X-Frame-Options`, que ya mordió una vez— el visor queda en blanco
     * y nadie leyó nada.
     */
    onCargado?: (etiqueta: string) => void;
}) {
    const [activo, setActivo] = useState(0);

    const documento = documentos[activo] ?? documentos[0];

    if (documento === undefined) {
        return null;
    }

    /*
     * `min-w-0` en la raíz no es decoración: sin él, el ancho mínimo de
     * este bloque es el de su contenido —los 794 px del iframe—, y como
     * `DialogContent` es una grilla, el ítem se niega a achicarse y la hoja
     * se sale por la derecha. Con `min-w-0` la caja puede encogerse, el
     * observador mide el ancho real y la escala baja.
     */
    return (
        <div className="flex min-w-0 flex-col gap-2">
            {documentos.length > 1 && (
                <div className="flex shrink-0 gap-1 rounded-md border p-1 text-xs">
                    {documentos.map((doc, i) => (
                        <button
                            key={doc.etiqueta}
                            type="button"
                            onClick={() => setActivo(i)}
                            className={cn(
                                'flex-1 rounded px-2 py-1 transition-colors',
                                i === activo
                                    ? 'bg-primary text-primary-foreground'
                                    : 'text-muted-foreground hover:bg-muted',
                            )}
                        >
                            {doc.etiqueta}
                        </button>
                    ))}
                </div>
            )}

            <DocumentFrame
                url={documento.urlVista}
                titulo={documento.etiqueta}
                className="w-full"
                maxAlto={alto}
                sinInteraccion={compacto}
                onCargado={() => onCargado?.(documento.etiqueta)}
            />

            <div
                className={cn(
                    'flex shrink-0 flex-wrap items-center gap-x-3 gap-y-1',
                    compacto ? 'text-xs' : 'text-sm',
                )}
            >
                <a
                    href={documento.urlVista}
                    target="_blank"
                    rel="noreferrer"
                    className="inline-flex items-center gap-1 text-muted-foreground underline underline-offset-2 hover:text-primary"
                >
                    <ExternalLink className="size-3.5" aria-hidden="true" />
                    Ver en grande
                </a>
                <a
                    href={documento.urlImpresion}
                    target="_blank"
                    rel="noreferrer"
                    className="inline-flex items-center gap-1 text-muted-foreground underline underline-offset-2 hover:text-primary"
                >
                    <Printer className="size-3.5" aria-hidden="true" />
                    Imprimir
                </a>
            </div>
        </div>
    );
}
