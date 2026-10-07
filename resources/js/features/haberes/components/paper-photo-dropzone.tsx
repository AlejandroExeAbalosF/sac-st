import { FileText, ImageUp, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

const ACEPTA = 'image/jpeg,image/png,image/webp,application/pdf';

/**
 * La foto de un papel del sistema anterior: se arrastra o se elige.
 *
 * Es la versión compacta del panel de comprobantes —entra en un diálogo,
 * al lado del número y la fecha del papel—: una zona punteada que acepta
 * el archivo soltado encima, y que también se abre con un clic o con el
 * teclado. Elegida la foto, muestra cuál es —una miniatura, o el ícono si
 * es un PDF— para que el operador vea que subió la correcta antes de
 * guardar.
 *
 * Qué tipos y tamaños se aceptan lo decide el servidor
 * (`ScannedDocument`); acá solo se avisa, para no enterarse por un error.
 */
export default function PaperPhotoDropzone({
    id,
    foto,
    onElegir,
    error,
}: {
    id: string;
    foto: File | null;
    onElegir: (archivo: File | null) => void;
    error?: string;
}) {
    const inputRef = useRef<HTMLInputElement>(null);
    const [encima, setEncima] = useState(false);
    const esPdf = foto?.type === 'application/pdf';

    /*
     * La miniatura sale de una URL local del archivo. Se crea cuando cambia
     * la foto y se libera al cambiarla o al cerrar el diálogo: sin eso cada
     * foto elegida queda retenida en memoria hasta recargar la página.
     */
    const miniatura = useMemo(
        () => (foto !== null && !esPdf ? URL.createObjectURL(foto) : null),
        [foto, esPdf],
    );

    useEffect(() => {
        if (miniatura === null) {
            return;
        }

        return () => URL.revokeObjectURL(miniatura);
    }, [miniatura]);

    const abrir = () => inputRef.current?.click();

    return (
        <div className="grid gap-1.5">
            <label
                htmlFor={id}
                className="text-sm leading-none font-medium select-none"
            >
                Foto
                <span className="ml-1 text-xs font-normal text-muted-foreground">
                    opcional
                </span>
            </label>

            <div
                onDragOver={(e) => {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'copy';
                    setEncima(true);
                }}
                onDragLeave={(e) => {
                    // Pasar por encima de un hijo también dispara la salida.
                    if (
                        !e.currentTarget.contains(
                            e.relatedTarget as Node | null,
                        )
                    ) {
                        setEncima(false);
                    }
                }}
                onDrop={(e) => {
                    e.preventDefault();
                    setEncima(false);
                    const archivo = e.dataTransfer.files?.[0];

                    if (archivo !== undefined) {
                        onElegir(archivo);
                    }
                }}
                className={cn(
                    'rounded-lg border-2 border-dashed transition-colors',
                    foto === null
                        ? 'border-muted-foreground/30 bg-muted/20 hover:border-primary/60 hover:bg-primary/5'
                        : 'border-border bg-card',
                    encima && 'border-primary bg-primary/10',
                )}
            >
                <input
                    ref={inputRef}
                    id={id}
                    type="file"
                    accept={ACEPTA}
                    className="sr-only"
                    onChange={(e) => {
                        onElegir(e.target.files?.[0] ?? null);
                        // Para poder volver a elegir el mismo archivo después de quitarlo.
                        e.target.value = '';
                    }}
                />

                {foto === null ? (
                    <button
                        type="button"
                        onClick={abrir}
                        className="flex w-full cursor-pointer items-center gap-3 rounded-md px-4 py-3 text-left outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <ImageUp
                            className="size-6 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <span className="grid gap-0.5">
                            <span className="text-sm font-medium">
                                {encima
                                    ? 'Soltala para agregarla'
                                    : 'Arrastrá la foto acá o hacé clic para elegirla'}
                            </span>
                            <span className="text-xs text-muted-foreground">
                                JPG, PNG, WEBP o PDF, hasta 10 MB
                            </span>
                        </span>
                    </button>
                ) : (
                    <div className="flex items-center gap-3 p-2">
                        {miniatura !== null ? (
                            <img
                                src={miniatura}
                                alt=""
                                className="size-12 shrink-0 rounded object-cover"
                            />
                        ) : (
                            <span className="flex size-12 shrink-0 items-center justify-center rounded bg-muted">
                                <FileText
                                    className="size-6 text-muted-foreground"
                                    aria-hidden="true"
                                />
                            </span>
                        )}
                        <span className="grid min-w-0 flex-1 gap-0.5">
                            <span className="truncate text-sm">
                                {foto.name}
                            </span>
                            <span className="text-xs text-muted-foreground">
                                {pesoLegible(foto.size)}
                                {encima && ' · soltá otra para reemplazarla'}
                            </span>
                        </span>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="h-8 text-xs"
                            onClick={abrir}
                        >
                            Cambiar
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-8"
                            aria-label={`Quitar ${foto.name}`}
                            onClick={() => onElegir(null)}
                        >
                            <X className="size-4" aria-hidden="true" />
                        </Button>
                    </div>
                )}
            </div>

            <InputError message={error} />
        </div>
    );
}

/** El tamaño de un archivo, como lo diría una persona. */
function pesoLegible(bytes: number): string {
    if (bytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    return `${(bytes / (1024 * 1024)).toLocaleString('es-AR', { maximumFractionDigits: 1 })} MB`;
}
