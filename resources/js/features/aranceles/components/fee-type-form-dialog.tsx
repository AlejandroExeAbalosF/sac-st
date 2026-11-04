import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import AmountInput from '@/components/amount-input';
import DateInput from '@/components/date-input';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { businessToday } from '@/lib/format';
import { store, update } from '@/routes/aranceles/tipos';

type Tipo = App.Modules.Aranceles.Data.FeeTypeData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Vacío para dar de alta uno nuevo. */
    tipo: Tipo | null;
    nextCode: number;
};

type Valores = {
    code: string;
    decreeLetter: string;
    name: string;
    calculationMode: string;
    unitLabel: string;
    isActive: boolean;
    validFrom: string;
    value: string;
    legalReference: string;
};

/**
 * Alta y corrección de un tipo de arancel.
 *
 * El alta lleva el primer precio: un tipo sin precio no se podría cobrar. La
 * corrección no toca el precio ni el modo de cálculo: un precio nuevo se agrega
 * desde su historial, y el modo lo fijó el primero.
 */
export default function FeeTypeFormDialog({
    open,
    onOpenChange,
    tipo,
    nextCode,
}: Props) {
    const form = useForm<Valores>({
        code: '',
        decreeLetter: '',
        name: '',
        calculationMode: 'unit',
        unitLabel: '',
        isActive: true,
        validFrom: '',
        value: '',
        legalReference: '',
    });

    useEffect(() => {
        if (!open) {
            return;
        }

        form.setData({
            code: String(tipo?.code ?? nextCode),
            decreeLetter: tipo?.decreeLetter ?? '',
            name: tipo?.name ?? '',
            calculationMode: tipo?.calculationMode ?? 'unit',
            unitLabel: tipo?.unitLabel ?? '',
            isActive: tipo?.isActive ?? true,
            validFrom: businessToday(),
            value: '',
            legalReference: '',
        });
        form.clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, tipo, nextCode]);

    const esPorcentaje = form.data.calculationMode === 'percentage';

    const enviar = (event: React.FormEvent) => {
        event.preventDefault();

        const onSuccess = () => onOpenChange(false);

        if (tipo) {
            form.patch(update(tipo.id).url, { onSuccess });

            return;
        }

        form.post(store().url, { onSuccess });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <form onSubmit={enviar}>
                    <DialogHeader>
                        <DialogTitle>
                            {tipo
                                ? `Corregir el tipo ${tipo.code}`
                                : 'Nuevo tipo de arancel'}
                        </DialogTitle>
                        <DialogDescription>
                            Lo que ya se cobró no cambia: cada recibo guarda el
                            concepto y el precio con los que se emitió.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 py-4">
                        <div className="grid grid-cols-2 gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor="fee-code">Código</Label>
                                <Input
                                    id="fee-code"
                                    inputMode="numeric"
                                    value={form.data.code}
                                    onChange={(e) =>
                                        form.setData('code', e.target.value)
                                    }
                                />
                                <InputError message={form.errors.code} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="fee-letter">
                                    Inciso (opcional)
                                </Label>
                                <Input
                                    id="fee-letter"
                                    maxLength={2}
                                    value={form.data.decreeLetter}
                                    onChange={(e) =>
                                        form.setData(
                                            'decreeLetter',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError
                                    message={form.errors.decreeLetter}
                                />
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="fee-name">Concepto</Label>
                            <Textarea
                                id="fee-name"
                                rows={3}
                                maxLength={300}
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                            />
                            <InputError message={form.errors.name} />
                        </div>

                        {!tipo && (
                            <div className="grid gap-2">
                                <Label htmlFor="fee-mode">
                                    Cómo se calcula
                                </Label>
                                <Select
                                    value={form.data.calculationMode}
                                    onValueChange={(modo) =>
                                        form.setData({
                                            ...form.data,
                                            calculationMode: modo,
                                            unitLabel: '',
                                            value: '',
                                        })
                                    }
                                >
                                    <SelectTrigger
                                        id="fee-mode"
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="unit">
                                            Cantidad por precio
                                        </SelectItem>
                                        <SelectItem value="percentage">
                                            Porcentaje sobre un monto
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <p className="text-xs text-muted-foreground">
                                    No se puede cambiar después de guardar.
                                </p>
                                <InputError
                                    message={form.errors.calculationMode}
                                />
                            </div>
                        )}

                        {!esPorcentaje && (
                            <div className="grid gap-2">
                                <Label htmlFor="fee-unit">
                                    Unidad (opcional)
                                </Label>
                                <Input
                                    id="fee-unit"
                                    maxLength={40}
                                    placeholder="folio"
                                    value={form.data.unitLabel}
                                    onChange={(e) =>
                                        form.setData(
                                            'unitLabel',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError message={form.errors.unitLabel} />
                            </div>
                        )}

                        {!tipo && (
                            <>
                                <div className="grid grid-cols-2 gap-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor="fee-value">
                                            {esPorcentaje
                                                ? 'Tasa (%)'
                                                : 'Precio'}
                                        </Label>
                                        {esPorcentaje ? (
                                            <Input
                                                id="fee-value"
                                                inputMode="decimal"
                                                placeholder="0,07"
                                                value={form.data.value}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'value',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        ) : (
                                            <AmountInput
                                                id="fee-value"
                                                value={form.data.value}
                                                onChange={(valor) =>
                                                    form.setData('value', valor)
                                                }
                                            />
                                        )}
                                        <InputError
                                            message={form.errors.value}
                                        />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="fee-from">
                                            Vigente desde
                                        </Label>
                                        <DateInput
                                            id="fee-from"
                                            value={form.data.validFrom}
                                            onChange={(e) =>
                                                form.setData(
                                                    'validFrom',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        <InputError
                                            message={form.errors.validFrom}
                                        />
                                    </div>
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="fee-reference">
                                        Referencia legal (opcional)
                                    </Label>
                                    <Input
                                        id="fee-reference"
                                        maxLength={200}
                                        placeholder="Decreto 1202/2019"
                                        value={form.data.legalReference}
                                        onChange={(e) =>
                                            form.setData(
                                                'legalReference',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={form.errors.legalReference}
                                    />
                                </div>
                            </>
                        )}

                        {tipo && (
                            <div className="grid gap-1">
                                <label className="flex items-center gap-2 text-sm">
                                    <Checkbox
                                        checked={form.data.isActive}
                                        onCheckedChange={(valor) =>
                                            form.setData(
                                                'isActive',
                                                valor === true,
                                            )
                                        }
                                    />
                                    Activo para cobros nuevos
                                </label>
                                <InputError message={form.errors.isActive} />
                            </div>
                        )}
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                        >
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Guardar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
