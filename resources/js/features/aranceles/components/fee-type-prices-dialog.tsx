import { useForm } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { useEffect, useState } from 'react';
import AmountInput from '@/components/amount-input';
import DateInput from '@/components/date-input';
import InputError from '@/components/input-error';
import StatusBadge from '@/components/status-badge';
import type { StatusTone } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { feeTypeValue } from '@/features/aranceles/lib/fee-type-value';
import { businessToday, date } from '@/lib/format';
import { anular } from '@/routes/aranceles/precios';
import { store } from '@/routes/aranceles/tipos/precios';

type Tipo = App.Modules.Aranceles.Data.FeeTypeData;
type Precio = App.Modules.Aranceles.Data.FeeTypePriceData;

const ESTADOS: Record<string, { label: string; tone: StatusTone }> = {
    current: { label: 'Vigente', tone: 'done' },
    scheduled: { label: 'Programado', tone: 'progress' },
    past: { label: 'Anterior', tone: 'neutral' },
    voided: { label: 'Anulado', tone: 'neutral' },
};

type Props = {
    tipo: Tipo | null;
    onClose: () => void;
    canManage: boolean;
    onEdit: (tipo: Tipo) => void;
};

/** La ficha de un tipo: su historial de precios y, a quien puede, cargar otro. */
export default function FeeTypePricesDialog({
    tipo,
    onClose,
    canManage,
    onEdit,
}: Props) {
    return (
        <Dialog
            open={tipo !== null}
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
        >
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                {tipo && (
                    <>
                        <DialogHeader>
                            <DialogTitle>
                                {tipo.code}
                                {tipo.decreeLetter &&
                                    ` (inciso ${tipo.decreeLetter})`}
                            </DialogTitle>
                            <DialogDescription>{tipo.name}</DialogDescription>
                        </DialogHeader>

                        {canManage && (
                            <div>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => onEdit(tipo)}
                                >
                                    <Pencil
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    Corregir o desactivar
                                </Button>
                            </div>
                        )}

                        {/*
                         * Anular un precio libera su fecha: la clave vuelve a
                         * armar el formulario, sin el error que decía que
                         * estaba ocupada.
                         */}
                        {canManage && (
                            <NuevoPrecio
                                key={
                                    tipo.prices.filter(
                                        (precio) => precio.status === 'voided',
                                    ).length
                                }
                                tipo={tipo}
                            />
                        )}

                        <section className="space-y-2">
                            <h3 className="text-sm font-medium">
                                Historial de precios
                            </h3>
                            <ul className="divide-y rounded-lg border">
                                {tipo.prices.map((precio) => (
                                    <FilaDePrecio
                                        key={precio.id}
                                        tipo={tipo}
                                        precio={precio}
                                        canManage={canManage}
                                    />
                                ))}
                            </ul>
                        </section>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}

function NuevoPrecio({ tipo }: { tipo: Tipo }) {
    const form = useForm({
        validFrom: businessToday(),
        value: '',
        legalReference: '',
    });
    const esPorcentaje = tipo.calculationMode === 'percentage';

    const enviar = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(store(tipo.id).url, {
            preserveScroll: true,
            onSuccess: () => form.reset('value', 'legalReference'),
        });
    };

    return (
        <form
            onSubmit={enviar}
            className="grid gap-3 rounded-lg border p-3 sm:grid-cols-[1fr_1fr_1.4fr_auto] sm:items-start"
        >
            <div className="grid gap-1.5">
                <Label htmlFor="price-value">
                    {esPorcentaje ? 'Nueva tasa (%)' : 'Nuevo precio'}
                </Label>
                {esPorcentaje ? (
                    <Input
                        id="price-value"
                        inputMode="decimal"
                        value={form.data.value}
                        onChange={(e) => form.setData('value', e.target.value)}
                    />
                ) : (
                    <AmountInput
                        id="price-value"
                        value={form.data.value}
                        onChange={(valor) => form.setData('value', valor)}
                    />
                )}
                <InputError message={form.errors.value} />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor="price-from">Vigente desde</Label>
                <DateInput
                    id="price-from"
                    value={form.data.validFrom}
                    onChange={(e) => form.setData('validFrom', e.target.value)}
                />
                <InputError message={form.errors.validFrom} />
            </div>
            <div className="grid gap-1.5">
                <Label htmlFor="price-reference">Referencia (opcional)</Label>
                <Input
                    id="price-reference"
                    maxLength={200}
                    value={form.data.legalReference}
                    onChange={(e) =>
                        form.setData('legalReference', e.target.value)
                    }
                />
                <InputError message={form.errors.legalReference} />
            </div>
            <Button
                type="submit"
                disabled={form.processing}
                className="sm:mt-[1.375rem]"
            >
                Agregar
            </Button>
        </form>
    );
}

function FilaDePrecio({
    tipo,
    precio,
    canManage,
}: {
    tipo: Tipo;
    precio: Precio;
    canManage: boolean;
}) {
    const [anulando, setAnulando] = useState(false);
    const form = useForm({ reason: '' });
    const estado = ESTADOS[precio.status] ?? ESTADOS.past;
    const anulado = precio.status === 'voided';

    useEffect(() => {
        if (!anulando) {
            form.reset();
            form.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [anulando]);

    const enviar = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(anular(precio.id).url, {
            preserveScroll: true,
            onSuccess: () => setAnulando(false),
        });
    };

    return (
        <li className="space-y-2 p-3 text-sm">
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <span
                    className={
                        anulado
                            ? 'font-medium tabular-nums line-through'
                            : 'font-medium tabular-nums'
                    }
                >
                    {feeTypeValue(tipo.calculationMode, precio)}
                </span>
                <span className="text-muted-foreground">
                    desde {date(precio.validFrom)}
                </span>
                <StatusBadge
                    label={estado.label}
                    tone={estado.tone}
                    voided={anulado}
                />
                {canManage && !anulado && !anulando && (
                    <Button
                        variant="ghost"
                        size="sm"
                        className="ml-auto"
                        onClick={() => setAnulando(true)}
                    >
                        Anular
                    </Button>
                )}
            </div>
            {(precio.legalReference || precio.createdBy) && (
                <p className="text-xs text-muted-foreground">
                    {[precio.legalReference, precio.createdBy]
                        .filter(Boolean)
                        .join(' · ')}
                </p>
            )}
            {precio.voidReason && (
                <p className="text-xs text-muted-foreground">
                    Motivo de la anulación: {precio.voidReason}
                </p>
            )}
            {anulando && (
                <form onSubmit={enviar} className="grid gap-2">
                    <Label htmlFor={`void-${precio.id}`}>
                        Por qué se anula este precio
                    </Label>
                    <Input
                        id={`void-${precio.id}`}
                        maxLength={300}
                        autoFocus
                        value={form.data.reason}
                        onChange={(e) => form.setData('reason', e.target.value)}
                    />
                    <InputError message={form.errors.reason} />
                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => setAnulando(false)}
                        >
                            Volver
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            size="sm"
                            disabled={form.processing}
                        >
                            Anular precio
                        </Button>
                    </div>
                </form>
            )}
        </li>
    );
}
