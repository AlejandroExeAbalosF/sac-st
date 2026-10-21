import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { businessToday } from '@/lib/format';

export type TicketFields = {
    depositDate: string;
    depositTime: string;
    operationNumber: string;
    terminal: string;
    notes: string;
};

function Opcional() {
    return (
        <span className="ml-1 text-xs font-normal text-muted-foreground">
            opcional
        </span>
    );
}

/**
 * Lo que dice el ticket del cajero: fecha, hora, operación, terminal y
 * observaciones.
 *
 * Es el mismo papel en todas las cajas —Haberes lo transcribe por cuota,
 * Multas por cobro—, así que los campos son uno solo. La foto va aparte, en
 * `TicketPhotoPanel`, al lado del formulario.
 */
export default function DepositTicketFields({
    data,
    setData,
    errors,
}: {
    data: TicketFields;
    setData: (campo: keyof TicketFields, valor: string) => void;
    errors: Partial<Record<keyof TicketFields, string>>;
}) {
    const hoy = businessToday();

    return (
        <>
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="depositDate">Fecha del depósito</Label>
                    <Input
                        id="depositDate"
                        type="date"
                        max={hoy}
                        value={data.depositDate}
                        onChange={(e) => setData('depositDate', e.target.value)}
                    />
                    <InputError message={errors.depositDate} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="depositTime">
                        Hora
                        <Opcional />
                    </Label>
                    <Input
                        id="depositTime"
                        type="time"
                        value={data.depositTime}
                        onChange={(e) => setData('depositTime', e.target.value)}
                    />
                    <InputError message={errors.depositTime} />
                </div>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="operationNumber">
                        N.º de operación
                        <Opcional />
                    </Label>
                    <Input
                        id="operationNumber"
                        value={data.operationNumber}
                        onChange={(e) =>
                            setData('operationNumber', e.target.value)
                        }
                        placeholder="995979830"
                        className="font-mono tabular-nums"
                    />
                    <InputError message={errors.operationNumber} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="terminal">
                        Terminal
                        <Opcional />
                    </Label>
                    <Input
                        id="terminal"
                        value={data.terminal}
                        onChange={(e) => setData('terminal', e.target.value)}
                    />
                    <InputError message={errors.terminal} />
                </div>
            </div>

            <p className="text-xs text-muted-foreground">
                Se guardan como evidencia de lo que dice el papel. La
                acreditación se busca por fecha e importe.
            </p>

            <div className="grid gap-2">
                <Label htmlFor="notes">
                    Observaciones
                    <Opcional />
                </Label>
                <Input
                    id="notes"
                    value={data.notes}
                    onChange={(e) => setData('notes', e.target.value)}
                />
                <InputError message={errors.notes} />
            </div>
        </>
    );
}
