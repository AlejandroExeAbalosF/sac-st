import { FileUp, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

/**
 * La foto de un comprobante bancario, con el papel a la vista.
 *
 * Lo usan los dos circuitos que transcriben un ticket del banco: el que
 * llega con el expediente y el del depósito que hace el área cuando el
 * beneficiario no retira su efectivo. Son hechos opuestos —uno es dinero
 * que entra, el otro dinero que sale de la caja— pero la tarea del
 * operador es la misma: copiar un papel que tiene en la mano.
 *
 * **La foto va al lado del formulario**, no debajo ni detrás de un clic.
 * Cambiar de ventana en cada campo es donde se pierde el tiempo y donde se
 * cuela el dígito equivocado.
 *
 * Quién la exige es de cada circuito, no de acá: para el comprobante del
 * expediente es opcional —a veces el papel se traspapela y el dato hay que
 * cargarlo igual— y para el traslado es obligatoria, porque es la única
 * prueba de que el efectivo salió.
 */
export function useTicketPhoto() {
    /*
     * El archivo y su URL van juntos en un solo estado.
     *
     * Separarlos obligaba a sincronizarlos con un efecto, y el estado
     * derivado dentro de un efecto es justo lo que produce el parpadeo de
     * un render con la foto vieja. Acá se crean y se descartan en el mismo
     * acto.
     */
    const [comprobante, setComprobante] = useState<{
        file: File;
        url: string;
    } | null>(null);
    const inputRef = useRef<HTMLInputElement>(null);

    const foto = comprobante?.file ?? null;
    const vistaPrevia = comprobante?.url ?? null;

    // Liberar la URL al cambiarla o al salir de la pantalla: sin esto cada
    // foto elegida queda retenida en memoria hasta recargar.
    useEffect(() => {
        if (vistaPrevia === null) {
            return;
        }

        return () => URL.revokeObjectURL(vistaPrevia);
    }, [vistaPrevia]);

    const elegir = (file: File | null) => {
        setComprobante(
            file === null ? null : { file, url: URL.createObjectURL(file) },
        );
    };

    return { foto, vistaPrevia, inputRef, elegir };
}

/** El panel en sí: la zona de arrastre, la vista previa y el botón. */
export default function TicketPhotoPanel({
    foto,
    vistaPrevia,
    inputRef,
    onElegir,
    error,
}: {
    foto: File | null;
    vistaPrevia: string | null;
    inputRef: React.RefObject<HTMLInputElement | null>;
    onElegir: (file: File | null) => void;
    error?: string;
}) {
    const [encima, setEncima] = useState(false);
    const esPdf = foto?.type === 'application/pdf';

    return (
        <div className="lg:sticky lg:top-6 lg:self-start">
            <div
                onDragOver={(e) => {
                    e.preventDefault();
                    setEncima(true);
                }}
                onDragLeave={() => setEncima(false)}
                onDrop={(e) => {
                    e.preventDefault();
                    setEncima(false);
                    onElegir(e.dataTransfer.files?.[0] ?? null);
                }}
                className={cn(
                    'group rounded-lg border transition-colors',
                    /*
                     * Sin foto esto es una zona para soltar un archivo, y
                     * tiene que verse como tal. Con un borde punteado de un
                     * píxel al mismo color que el de la tarjeta de al lado
                     * se leía como un panel vacío, no como algo que espera
                     * que le tiren algo encima.
                     */
                    foto === null &&
                        'border-2 border-dashed border-muted-foreground/40 bg-muted/20 hover:border-primary/60 hover:bg-primary/5',
                    encima && 'border-primary bg-primary/10',
                )}
            >
                <input
                    ref={inputRef}
                    type="file"
                    accept="image/*,application/pdf"
                    capture="environment"
                    className="sr-only"
                    onChange={(e) => onElegir(e.target.files?.[0] ?? null)}
                />

                {foto === null || vistaPrevia === null ? (
                    <button
                        type="button"
                        onClick={() => inputRef.current?.click()}
                        className="flex w-full cursor-pointer flex-col items-center gap-2 rounded-lg px-6 py-16 text-center transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
                    >
                        <FileUp className="size-7 text-muted-foreground transition-colors group-hover:text-primary" />
                        <span className="text-sm font-medium">
                            Sacá una foto del comprobante
                        </span>
                        <span className="text-xs text-muted-foreground">
                            Se muestra acá al lado mientras copiás los datos
                        </span>
                        {/*
                         * Qué se acepta, antes de que lo diga un error: el
                         * operador está con el teléfono en la mano.
                         */}
                        <span className="mt-1 text-[0.6875rem] text-muted-foreground">
                            Arrastrala acá o tocá para elegirla · JPG, PNG, WEBP
                            o PDF, hasta 10 MB
                        </span>
                    </button>
                ) : (
                    <div className="space-y-2 p-2">
                        <div className="flex items-center justify-between px-1">
                            <span className="truncate text-xs text-muted-foreground">
                                {foto.name}
                            </span>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                aria-label="Quitar el comprobante"
                                onClick={() => onElegir(null)}
                            >
                                <X className="size-4" />
                            </Button>
                        </div>

                        {esPdf ? (
                            <object
                                data={vistaPrevia}
                                type="application/pdf"
                                className="h-[32rem] w-full rounded"
                                aria-label="Comprobante en PDF"
                            />
                        ) : (
                            <img
                                src={vistaPrevia}
                                alt="Comprobante del depósito"
                                className="w-full rounded"
                            />
                        )}

                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="w-full"
                            onClick={() => inputRef.current?.click()}
                        >
                            Cambiar
                        </Button>
                    </div>
                )}
            </div>

            <InputError message={error} className="mt-2" />
        </div>
    );
}
