import { useForm } from '@inertiajs/react';
import AmountInput from '@/components/amount-input';
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
import FormDialog, {
    FieldError,
} from '@/features/multas/components/form-dialog';
import { HISTORICAL_KIND } from '@/features/multas/labels';
import { parseAmount } from '@/lib/format';
import { store } from '@/routes/multas/antecedentes';

type Kind = App.Modules.Multas.Enums.HistoricalMovementKind;

/**
 * Un pago —o una devolución— anterior al sistema.
 *
 * No es plata que entra hoy: ya se rindió en su momento. Por eso no genera
 * asientos, y cuenta en el saldo recién cuando alguien con permiso de
 * ajustar lo valida.
 */
export default function HistoricalMovementDialog({
    fineId,
    onClose,
}: {
    fineId: number;
    onClose: () => void;
}) {
    const { data, setData, post, transform, processing, errors } = useForm({
        kind: 'payment' as Kind,
        amount: '',
        occurredOn: '',
        evidence: '',
    });

    const enviar = () => {
        transform((datos) => ({ ...datos, amount: parseAmount(datos.amount) }));
        post(store(fineId).url, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <FormDialog
            title="Registrar un pago anterior"
            description="Lo que se pagó antes de este sistema, con su respaldo. No mueve la caja ni el banco."
            onClose={onClose}
            onSubmit={enviar}
            processing={processing}
            submitLabel="Registrar"
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <div>
                    <Label htmlFor="antecedente-tipo">Tipo</Label>
                    <Select
                        value={data.kind}
                        onValueChange={(valor) =>
                            setData('kind', valor as Kind)
                        }
                    >
                        <SelectTrigger id="antecedente-tipo" className="mt-1">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {(Object.keys(HISTORICAL_KIND) as Kind[]).map(
                                (kind) => (
                                    <SelectItem key={kind} value={kind}>
                                        {HISTORICAL_KIND[kind]}
                                    </SelectItem>
                                ),
                            )}
                        </SelectContent>
                    </Select>
                </div>
                <div>
                    <Label htmlFor="antecedente-importe">Importe</Label>
                    <div className="mt-1">
                        <AmountInput
                            id="antecedente-importe"
                            value={data.amount}
                            onChange={(valor) => setData('amount', valor)}
                            placeholder="0,00"
                            aria-invalid={Boolean(errors.amount)}
                        />
                    </div>
                    <FieldError message={errors.amount} />
                </div>
            </div>

            <div>
                <Label htmlFor="antecedente-fecha">
                    Fecha
                    <span className="ml-1 font-normal text-muted-foreground">
                        (día, solo el mes, o vacía)
                    </span>
                </Label>
                <Input
                    id="antecedente-fecha"
                    className="mt-1 max-w-48"
                    value={data.occurredOn}
                    onChange={(e) => setData('occurredOn', e.target.value)}
                    placeholder="2025-07"
                    aria-invalid={Boolean(errors.occurredOn)}
                />
                <FieldError message={errors.occurredOn} />
            </div>

            <div>
                <Label htmlFor="antecedente-respaldo">Respaldo</Label>
                <Textarea
                    id="antecedente-respaldo"
                    className="mt-1"
                    rows={2}
                    maxLength={500}
                    value={data.evidence}
                    onChange={(e) => setData('evidence', e.target.value)}
                    placeholder="De dónde sale el dato: «Excel 2025, columna julio», «recibo de papel 64685»."
                    aria-invalid={Boolean(errors.evidence)}
                />
                <FieldError message={errors.evidence} />
            </div>
        </FormDialog>
    );
}
