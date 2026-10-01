import { useForm } from '@inertiajs/react';
import { Ban, ImageIcon } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import Money from '@/components/money';
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
import { Textarea } from '@/components/ui/textarea';
import { date } from '@/lib/format';
import { preview } from '@/routes/adjuntos';
import { voidMethod as anularPagoAnterior } from '@/routes/haberes/installments/legacy-settlement';

type Estado = App.Modules.Haberes.Data.InstallmentLegacyData;
type Papel = App.Modules.Haberes.Data.LegacyDocumentData;

/**
 * Duplican los `label()` del servidor, como el resto de los enums de
 * dominio del front: el transformador exporta los valores, no los rótulos.
 */
const MODO: Record<App.Modules.Haberes.Enums.LegacySettlementMode, string> = {
    before_opening: 'Pagada antes de la apertura',
    legacy_disbursement: 'Pagada desde Pagos anteriores',
};

const PAPEL: Record<App.Modules.Haberes.Enums.LegacyDocumentKind, string> = {
    income_receipt: 'Recibo de ingreso',
    payment_order: 'Orden de Pago',
    expense_receipt: 'Recibo de egreso',
};

// Es cómo salió la plata: por eso `bank` es transferencia y no depósito.
const MEDIO: Record<string, string> = {
    cash: 'Efectivo',
    cheque: 'Cheque',
    bank: 'Transferencia',
};

/**
 * Una cuota pagada fuera del circuito: cómo, cuándo y con qué papeles.
 *
 * Reemplaza en la tarjeta a los tramos de ingreso, Orden y egreso. No es
 * que no correspondan: ya pasaron, afuera, y lo que queda de ellos son
 * estos papeles.
 */
export default function LegacySettlementPanel({
    cuotaId,
    numero,
    estado,
    puedeAnular,
}: {
    cuotaId: number;
    numero: number;
    estado: Estado;
    puedeAnular: boolean;
}) {
    const [anular, setAnular] = useState(false);
    const registro = estado.settlement;

    if (registro === null) {
        return null;
    }

    return (
        <div className="grid gap-3">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <p className="font-medium">{MODO[registro.mode]}</p>
                <p className="text-xs text-muted-foreground">
                    {registro.paidOn && <>Pagada el {date(registro.paidOn)}</>}
                    {registro.paymentMedium && (
                        <> · {MEDIO[registro.paymentMedium]}</>
                    )}
                </p>
            </div>

            {registro.receiptNumber && (
                <p className="text-xs text-muted-foreground">
                    Recibo de egreso del sistema n.º{' '}
                    <span className="font-mono">{registro.receiptNumber}</span>
                    {registro.receiptReference && (
                        <> · {registro.receiptReference}</>
                    )}
                </p>
            )}

            <ul className="divide-y rounded-lg border">
                {estado.documents.map((papel) => (
                    <FilaDePapel key={papel.id} papel={papel} />
                ))}
            </ul>

            {registro.notes && (
                <p className="text-xs text-muted-foreground italic">
                    {registro.notes}
                </p>
            )}

            <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
                <span>
                    Registrado el {date(registro.recordedAt)}
                    {registro.recordedBy && <> por {registro.recordedBy}</>}
                </span>
                {puedeAnular && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-8 text-xs"
                        onClick={() => setAnular(true)}
                    >
                        <Ban className="size-3.5" aria-hidden="true" />
                        Anular registro
                    </Button>
                )}
            </div>

            {anular && (
                <AnularDialog
                    cuotaId={cuotaId}
                    numero={numero}
                    onCerrar={() => setAnular(false)}
                />
            )}
        </div>
    );
}

function FilaDePapel({ papel }: { papel: Papel }) {
    return (
        <li className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-3 py-2">
            <span>
                {PAPEL[papel.kind]}{' '}
                <span className="font-mono">n.º {papel.number}</span>
                <span className="text-muted-foreground">
                    {' '}
                    · {date(papel.issuedOn)}
                </span>
            </span>
            <span className="flex items-center gap-3">
                <Money value={papel.amount} />
                {papel.attachmentId !== null && (
                    <a
                        href={preview(papel.attachmentId).url}
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex items-center gap-1 text-xs text-primary hover:underline"
                    >
                        <ImageIcon className="size-3.5" aria-hidden="true" />
                        Ver foto
                    </a>
                )}
            </span>
        </li>
    );
}

/**
 * Anular el registro: la cuota vuelve a estar por pagar.
 *
 * Por eso pide un motivo. Si el registro estaba bien y se anula, a alguien
 * se le podría pagar de nuevo algo que ya cobró.
 */
function AnularDialog({
    cuotaId,
    numero,
    onCerrar,
}: {
    cuotaId: number;
    numero: number;
    onCerrar: () => void;
}) {
    const form = useForm({ reason: '' });

    return (
        <Dialog open onOpenChange={(v) => !v && onCerrar()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        Anular el pago anterior de la cuota {numero}
                    </DialogTitle>
                    <DialogDescription>
                        La cuota vuelve a quedar por pagar y sus papeles quedan
                        anulados. Si se pagó desde Pagos anteriores, ese pago
                        sigue en la caja: lo que se deshace es el vínculo.
                    </DialogDescription>
                </DialogHeader>

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(anularPagoAnterior(cuotaId).url, {
                            preserveScroll: true,
                            onSuccess: () => onCerrar(),
                        });
                    }}
                    className="mt-2 space-y-4"
                >
                    <div className="space-y-1.5">
                        <Label htmlFor={`motivo-pago-anterior-${cuotaId}`}>
                            Qué estaba mal
                        </Label>
                        <Textarea
                            id={`motivo-pago-anterior-${cuotaId}`}
                            rows={3}
                            value={form.data.reason}
                            onChange={(e) =>
                                form.setData('reason', e.target.value)
                            }
                            placeholder="Se cargó en la cuota 2 y el recibo es de la cuota 1."
                        />
                        <InputError message={form.errors.reason} />
                    </div>

                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onCerrar}
                        >
                            Cancelar
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={form.processing}
                        >
                            Anular el registro
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
