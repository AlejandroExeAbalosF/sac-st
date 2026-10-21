import { Printer } from 'lucide-react';
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
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import DocumentFrame, {
    HOJA_ALTO_A5,
} from '@/features/haberes/components/document-frame';
import { print, view } from '@/routes/multas/recibos';

/**
 * El recibo de Multas ya emitido, para mirarlo antes de mandarlo al papel.
 *
 * Igual que el de Haberes: lo que se elige es la salida —papel en blanco o
 * la hoja preimpresa del talonario—, no lo que dice, que quedó fijado al
 * emitirlo.
 */
export default function FineReceiptDialog({
    receiptId,
    numero,
    onClose,
}: {
    receiptId: number;
    numero: string;
    onClose: () => void;
}) {
    const [sobreTalonario, setSobreTalonario] = useState(false);
    const parametros = sobreTalonario ? '?talonario=1' : '';

    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent className="sm:max-w-5xl">
                <DialogHeader>
                    <DialogTitle>Recibo de Multas {numero}</DialogTitle>
                    <DialogDescription>
                        Así va a salir impreso. Lo que dice el recibo quedó
                        fijado al emitirlo.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex items-center gap-2">
                    <Switch
                        id="recibo-talonario"
                        checked={sobreTalonario}
                        onCheckedChange={setSobreTalonario}
                    />
                    <Label htmlFor="recibo-talonario">
                        Sobre la hoja del talonario
                    </Label>
                </div>

                <DocumentFrame
                    url={view(receiptId).url + parametros}
                    titulo={`Recibo de Multas ${numero}`}
                    hojaAlto={HOJA_ALTO_A5}
                    className="w-full"
                    maxAlto="60vh"
                />

                <DialogFooter>
                    <Button variant="outline" asChild className="sm:mr-auto">
                        <a
                            href={print(receiptId).url + parametros}
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
