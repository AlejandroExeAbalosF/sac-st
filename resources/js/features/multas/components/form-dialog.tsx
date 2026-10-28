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

/**
 * El marco de los diálogos de Multas: título, explicación, campos y pie.
 *
 * Todos piden lo mismo —un formulario corto que guarda y cierra— y
 * difieren en los campos. Con el marco en un solo lugar no pueden verse
 * distinto ni olvidarse del «Cancelar».
 */
export default function FormDialog({
    title,
    description,
    onClose,
    onSubmit,
    processing,
    submitLabel,
    processingLabel = 'Guardando…',
    destructive = false,
    children,
}: {
    title: string;
    description?: ReactNode;
    onClose: () => void;
    onSubmit: () => void;
    processing: boolean;
    submitLabel: string;
    /** Lo que dice el botón mientras trabaja: no todo diálogo guarda. */
    processingLabel?: string;
    destructive?: boolean;
    children: ReactNode;
}) {
    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    {description && (
                        <DialogDescription>{description}</DialogDescription>
                    )}
                </DialogHeader>

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        onSubmit();
                    }}
                    className="grid gap-4"
                >
                    {children}

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={onClose}
                            disabled={processing}
                        >
                            Cancelar
                        </Button>
                        <Button
                            type="submit"
                            variant={destructive ? 'destructive' : 'default'}
                            disabled={processing}
                        >
                            {processing ? processingLabel : submitLabel}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export function FieldError({ message }: { message?: string }) {
    return message ? (
        <p className="mt-1 text-xs text-destructive-strong">{message}</p>
    ) : null;
}
