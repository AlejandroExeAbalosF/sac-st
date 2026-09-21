import { Check, Copy, TriangleAlert } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useClipboard } from '@/hooks/use-clipboard';

export type TemporaryPassword = {
    username: string;
    name: string;
    password: string;
};

/**
 * La contraseña temporal, mostrada una única vez.
 *
 * No se guarda en ningún lado y no hay pantalla que permita volver a
 * consultarla: si se pierde, se restablece. Eso no es una limitación del
 * diseño, es la propiedad que hace que la clave la termine conociendo una
 * sola persona.
 *
 * El diálogo no se cierra con Escape ni haciendo clic afuera: cerrarlo sin
 * haberla copiado obliga a restablecerla, y ese descuido se comete una vez
 * cada tres altas.
 */
export default function TemporaryPasswordDialog({
    credentials,
    onClose,
}: {
    credentials: TemporaryPassword | null;
    onClose: () => void;
}) {
    const [copiado, copiar] = useClipboard();

    if (credentials === null) {
        return null;
    }

    const yaCopiada = copiado === credentials.password;

    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent
                className="sm:max-w-lg"
                onEscapeKeyDown={(e) => e.preventDefault()}
                onInteractOutside={(e) => e.preventDefault()}
            >
                <DialogHeader>
                    <DialogTitle>Contraseña de un solo uso</DialogTitle>
                    <DialogDescription>
                        Entregásela a {credentials.name}. El sistema le va a
                        exigir cambiarla apenas entre.
                    </DialogDescription>
                </DialogHeader>

                <dl className="grid gap-3 rounded-lg border bg-muted/30 p-4 text-sm">
                    <div className="flex items-baseline justify-between gap-4">
                        <dt className="text-xs tracking-wide text-field-label uppercase">
                            Usuario
                        </dt>
                        <dd className="font-mono">{credentials.username}</dd>
                    </div>

                    <div className="flex items-center justify-between gap-4 border-t pt-3">
                        <dt className="text-xs tracking-wide text-field-label uppercase">
                            Contraseña
                        </dt>
                        <dd className="flex items-center gap-2">
                            <code className="rounded-md border bg-card px-2.5 py-1.5 font-mono text-base tracking-wide select-all">
                                {credentials.password}
                            </code>
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                aria-label="Copiar la contraseña"
                                onClick={() =>
                                    void copiar(credentials.password)
                                }
                            >
                                {yaCopiada ? (
                                    <Check
                                        className="size-4 text-success-strong"
                                        aria-hidden="true"
                                    />
                                ) : (
                                    <Copy
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                )}
                            </Button>
                        </dd>
                    </div>
                </dl>

                <p className="flex items-start gap-2 rounded-lg border border-warning bg-warning-soft px-3 py-2 text-xs text-warning-strong">
                    <TriangleAlert
                        className="mt-0.5 size-3.5 shrink-0"
                        aria-hidden="true"
                    />
                    Esta es la única vez que se muestra. Si cerrás sin copiarla,
                    hay que restablecerla.
                </p>

                <DialogFooter>
                    <Button type="button" onClick={onClose}>
                        Ya la entregué
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
