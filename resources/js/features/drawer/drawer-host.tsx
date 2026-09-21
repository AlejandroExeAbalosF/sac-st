import { Sheet, SheetContent } from '@/components/ui/sheet';
import ReceiptPanel from '@/features/caja/components/receipt-panel';
import HistoryPanel from '@/features/haberes/components/history-panel';
import { useDrawer } from './drawer-context';

/**
 * Dibuja el panel del sujeto que esté abierto.
 *
 * Un solo host para todos los sujetos, y no un componente por tipo: cada
 * sujeto nuevo agrega un renglón acá, no una pieza nueva de plomería.
 *
 * El contenido se monta al abrir y se desmonta al cerrar —es el
 * comportamiento por omisión de Radix, sin `forceMount`— y eso es lo que
 * hace que cada apertura vuelva a pedir los datos en lugar de mostrar los
 * de la vez pasada.
 */
export default function DrawerHost() {
    const { subject, open, closeDrawer } = useDrawer();

    return (
        <Sheet
            open={open}
            onOpenChange={(siguiente) => {
                if (!siguiente) {
                    closeDrawer();
                }
            }}
        >
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto sm:max-w-md"
            >
                {/*
                 * La `key` no es cosmética: abrir otro sujeto sin cerrar
                 * el panel remonta el cuerpo en lugar de reusarlo, y así
                 * el estado de carga arranca limpio sin que el efecto
                 * tenga que resetearlo a mano. Incluye el `kind` porque
                 * dos sujetos distintos pueden compartir el id.
                 */}
                {subject?.kind === 'receipt' && (
                    <ReceiptPanel
                        key={`receipt-${subject.id}`}
                        receiptId={subject.id}
                    />
                )}

                {subject?.kind === 'history' && (
                    <HistoryPanel
                        key={`history-${subject.url}`}
                        subject={subject.subject}
                        url={subject.url}
                        label={subject.label}
                    />
                )}
            </SheetContent>
        </Sheet>
    );
}
