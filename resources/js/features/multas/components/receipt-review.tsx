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

/** La vista previa que armó el servidor y el número que le tocaría. */
export type Revision = { url: string; numero: string };

/**
 * El segundo paso del cobro en caja: el recibo como va a salir, antes de
 * emitirlo.
 *
 * El número es un anticipo: el definitivo se toma al emitir, y si en el
 * medio se emite otro recibo de Multas, este toma el siguiente. Volver
 * conserva lo cargado en el formulario.
 */
export default function ReceiptReview({
    revision,
    processing,
    onBack,
    onConfirm,
    onClose,
}: {
    revision: Revision;
    processing: boolean;
    onBack: () => void;
    onConfirm: () => void;
    onClose: () => void;
}) {
    const [sobreTalonario, setSobreTalonario] = useState(false);

    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent className="sm:max-w-5xl">
                <DialogHeader>
                    <DialogTitle>Revisar el recibo</DialogTitle>
                    <DialogDescription>
                        Así va a salir. Se emite con el número{' '}
                        <span className="font-mono">{revision.numero}</span> si
                        no se emite otro recibo antes; un recibo emitido no se
                        borra, solo se anula.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex items-center gap-2">
                    <Switch
                        id="revision-talonario"
                        checked={sobreTalonario}
                        onCheckedChange={setSobreTalonario}
                    />
                    <Label htmlFor="revision-talonario">
                        Sobre la hoja del talonario
                    </Label>
                </div>

                <DocumentFrame
                    url={revision.url + (sobreTalonario ? '&talonario=1' : '')}
                    titulo={`Recibo de Multas ${revision.numero}, sin emitir`}
                    hojaAlto={HOJA_ALTO_A5}
                    className="w-full"
                    maxAlto="60vh"
                />

                <DialogFooter>
                    <Button
                        variant="outline"
                        className="sm:mr-auto"
                        onClick={onBack}
                        disabled={processing}
                    >
                        Volver
                    </Button>
                    <Button
                        variant="ghost"
                        onClick={onClose}
                        disabled={processing}
                    >
                        Cancelar
                    </Button>
                    <Button onClick={onConfirm} disabled={processing}>
                        {processing ? 'Cobrando…' : 'Cobrar y emitir recibo'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
