import { useForm, usePage } from '@inertiajs/react';
import { Megaphone } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { businessToday, money } from '@/lib/format';
import { report as informar } from '@/routes/haberes/installments/disbursement';

type Cuota = App.Modules.Haberes.Data.InstallmentListItemData;
type Estado = App.Modules.Haberes.Data.InstallmentDisbursementData;

/**
 * El aviso del organismo de que ejecutó la transferencia — §2.3.1.
 *
 * **Es un aviso, no una prueba**, y el diálogo lo dice: lo que confirma
 * que el dinero salió es el débito en el extracto. Por eso al guardarlo el
 * egreso no queda pagado sino esperando, y el invariante 12 del DER lo
 * sostiene —*«un informe sin débito no genera egreso»*—.
 *
 * La referencia es opcional porque no siempre viene, pero cuando está es
 * lo que después hace que el débito se reconozca solo entre veinte
 * movimientos del mismo importe.
 */
export default function TransferReportDialog({
    cuota,
    estado,
    abierto,
    onCerrar,
}: {
    cuota: Cuota;
    estado: Estado;
    abierto: boolean;
    onCerrar: () => void;
}) {
    const hoy = businessToday();

    const form = useForm({
        reportedAt: hoy,
        reference: '',
        notes: '',
    });

    const { errors } = usePage().props as unknown as {
        errors?: Record<string, string>;
    };
    const erroresDelServidor = errors?.installmentId;

    const [enviado, setEnviado] = useState(false);

    const guardar = (e: React.FormEvent) => {
        e.preventDefault();
        setEnviado(true);

        form.post(informar(cuota.id).url, {
            preserveScroll: true,
            onSuccess: () => onCerrar(),
        });
    };

    return (
        <Dialog open={abierto} onOpenChange={(v) => !v && onCerrar()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        El organismo informó la transferencia
                    </DialogTitle>
                    <DialogDescription>
                        Queda registrado el aviso. El pago se da por hecho
                        recién cuando el débito aparece en el extracto y el
                        contador coteja los dos contra la Orden.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={guardar} className="mt-2 space-y-4">
                    <dl className="space-y-2 rounded-lg border p-3 text-sm">
                        <div className="flex justify-between gap-3">
                            <dt className="text-xs text-field-label">Orden</dt>
                            <dd className="font-mono">
                                {estado.orderNumber ?? '—'}
                            </dd>
                        </div>
                        <div className="flex justify-between gap-3">
                            <dt className="text-xs text-field-label">
                                Importe
                            </dt>
                            <dd className="font-mono font-semibold tabular-nums">
                                {money(estado.amount)}
                            </dd>
                        </div>
                    </dl>

                    <div className="space-y-1.5">
                        <Label htmlFor={`informe-fecha-${cuota.id}`}>
                            Fecha del informe
                        </Label>
                        <Input
                            id={`informe-fecha-${cuota.id}`}
                            type="date"
                            max={hoy}
                            value={form.data.reportedAt}
                            onChange={(e) =>
                                form.setData('reportedAt', e.target.value)
                            }
                        />
                        <InputError message={form.errors.reportedAt} />
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor={`informe-ref-${cuota.id}`}>
                            Referencia de la transferencia
                            <span className="ml-1 text-xs font-normal text-muted-foreground">
                                opcional
                            </span>
                        </Label>
                        <Input
                            id={`informe-ref-${cuota.id}`}
                            value={form.data.reference}
                            onChange={(e) =>
                                form.setData('reference', e.target.value)
                            }
                            className="font-mono tabular-nums"
                        />
                        <p className="text-xs text-muted-foreground">
                            El número con que el organismo la identificó. Si
                            está, el débito se reconoce solo en el extracto.
                        </p>
                        <InputError message={form.errors.reference} />
                    </div>

                    <div className="space-y-1.5">
                        <Label htmlFor={`informe-notas-${cuota.id}`}>
                            Observaciones
                            <span className="ml-1 text-xs font-normal text-muted-foreground">
                                opcional
                            </span>
                        </Label>
                        <Textarea
                            id={`informe-notas-${cuota.id}`}
                            rows={2}
                            value={form.data.notes}
                            onChange={(e) =>
                                form.setData('notes', e.target.value)
                            }
                        />
                        <InputError message={form.errors.notes} />
                    </div>

                    {enviado && <InputError message={erroresDelServidor} />}

                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onCerrar}
                        >
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            <Megaphone className="size-4" />
                            Registrar el informe
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
