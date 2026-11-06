import { useForm, usePage } from '@inertiajs/react';
import { Ban } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import ReasonFields from '@/components/reason-fields';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { money } from '@/lib/format';
import { voidCollection as anularCobro } from '@/routes/haberes/installments';

type Cuota = App.Modules.Haberes.Data.InstallmentListItemData;

/**
 * Anular el cobro: por mostrador el dinero nunca entró; por banco se deshace
 * la conciliación. La diferencia está en `VoidCashCollection`.
 *
 * Deja la cuota sin cobrar y el recibo anulado con su número. Después el
 * circuito normal la cobra bien y emite uno nuevo que apunta al anulado.
 */
export default function VoidCollectionDialog({
    cuota,
    abierto,
    onCerrar,
}: {
    cuota: Cuota;
    abierto: boolean;
    onCerrar: () => void;
}) {
    const [clave] = useState(
        () => `anular-cobro:${cuota.id}:${crypto.randomUUID()}`,
    );

    const form = useForm({
        reasonCode: '' as App.Modules.Haberes.Enums.HaberesReasonCode | '',
        reason: '',
        idempotencyKey: clave,
    });

    const { errors } = usePage().props as unknown as {
        errors?: Record<string, string>;
    };

    // Con la plata ya liberada no sale nada de la caja: solo cae el recibo.
    const conPlata = Number(cuota.fundedAmount) > 0;

    const enviar = (e: React.FormEvent) => {
        e.preventDefault();

        form.post(anularCobro(cuota.id).url, {
            preserveScroll: true,
            onSuccess: () => onCerrar(),
        });
    };

    return (
        <Dialog open={abierto} onOpenChange={(v) => !v && onCerrar()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Anular el recibo de ingreso</DialogTitle>
                    <DialogDescription>
                        {!conPlata
                            ? 'El recibo queda anulado con su número.'
                            : cuota.effectiveMedium === 'bank'
                              ? 'La cuota queda sin cobrar y el movimiento del extracto vuelve a quedar disponible para conciliar.'
                              : `Salen ${money(cuota.fundedAmount)} de la caja y la cuota queda sin cobrar. Si el dinero entró, corresponde liberarlo.`}
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={enviar} className="mt-4 space-y-4">
                    <ReasonFields
                        act="collection_void"
                        code={form.data.reasonCode}
                        detail={form.data.reason}
                        onCodeChange={(codigo) =>
                            form.setData('reasonCode', codigo)
                        }
                        onDetailChange={(detalle) =>
                            form.setData('reason', detalle)
                        }
                        codeError={form.errors.reasonCode}
                        detailError={form.errors.reason}
                        idPrefix={`motivo-anular-${cuota.id}`}
                        maxLength={250}
                    />

                    <InputError message={errors?.installmentId} />

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
                            <Ban className="size-4" />
                            Anular el recibo
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
