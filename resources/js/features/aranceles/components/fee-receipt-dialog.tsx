import { Printer } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import DocumentFrame, {
    HOJA_ALTO_A5,
} from '@/features/haberes/components/document-frame';
import { print, view } from '@/routes/aranceles/recibos';

/** El recibo ya emitido, para mirarlo antes de mandarlo al papel. */
export default function FeeReceiptDialog({
    receiptId,
    onClose,
}: {
    receiptId: number;
    onClose: () => void;
}) {
    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent className="sm:max-w-5xl">
                <DialogHeader>
                    <DialogTitle>Recibo de Aranceles</DialogTitle>
                    <DialogDescription>
                        Así va a salir impreso, en papel en blanco. Lo que dice
                        quedó fijado al emitirlo.
                    </DialogDescription>
                </DialogHeader>

                <DocumentFrame
                    url={view(receiptId).url}
                    titulo="Recibo de Aranceles"
                    hojaAlto={HOJA_ALTO_A5}
                    className="w-full"
                    maxAlto="60vh"
                />

                <DialogFooter>
                    <Button variant="outline" asChild className="sm:mr-auto">
                        <a
                            href={print(receiptId).url}
                            target="_blank"
                            rel="noreferrer"
                        >
                            <Printer aria-hidden="true" />
                            Imprimir
                        </a>
                    </Button>
                    <Button variant="outline" onClick={onClose}>
                        Cerrar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
