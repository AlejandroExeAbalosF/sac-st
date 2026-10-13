import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type Props = {
    abierto: boolean;
    /** Qué se pierde, dicho en una oración: «Los 3 cambios en los permisos de contador…». */
    detalle: string;
    onDescartar: () => void;
    onSeguir: () => void;
};

/**
 * La pregunta antes de perder cambios sin guardar.
 *
 * Seguir editando es la opción por defecto —la que toma Escape o un clic
 * afuera—, porque es la que no destruye nada.
 */
export default function DiscardChangesDialog({
    abierto,
    detalle,
    onDescartar,
    onSeguir,
}: Props) {
    return (
        <Dialog open={abierto} onOpenChange={(open) => !open && onSeguir()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Hay cambios sin guardar</DialogTitle>
                    <DialogDescription>{detalle}</DialogDescription>
                </DialogHeader>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onSeguir}>
                        Seguir editando
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        onClick={onDescartar}
                    >
                        Descartar cambios
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
