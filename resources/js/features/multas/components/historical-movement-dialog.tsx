import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import AmountInput from '@/components/amount-input';
import PartialDateInput from '@/components/partial-date-input';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import CreditPicker from '@/features/multas/components/credit-picker';
import FormDialog, {
    FieldError,
} from '@/features/multas/components/form-dialog';
import { HISTORICAL_KIND } from '@/features/multas/labels';
import { businessToday, parseAmount } from '@/lib/format';
import { store } from '@/routes/multas/antecedentes';
import { store as storeFromCredit } from '@/routes/multas/antecedentes/credito';

type Kind = App.Modules.Multas.Enums.HistoricalMovementKind;
type Credito = App.Modules.Multas.Data.FineBankCreditData;

/**
 * Un pago —o una devolución— anterior al sistema.
 *
 * No es plata que entra hoy: ya se rindió en su momento. Por eso no genera
 * asientos, y cuenta en el saldo recién cuando alguien con permiso de
 * ajustar lo valida.
 *
 * Si el pago fue una transferencia que el extracto ya trae —un crédito de
 * la cuenta de Multas anterior a la apertura de la caja—, se elige ese
 * crédito: el importe y la fecha salen de él, porque un pago no se reparte.
 */
export default function HistoricalMovementDialog({
    fineId,
    onClose,
    allowBankCredit = false,
    creditos,
}: {
    fineId: number;
    onClose: () => void;
    allowBankCredit?: boolean;
    /** Los créditos viejos que coinciden con la búsqueda; llegan al buscar. */
    creditos?: Credito[];
}) {
    const [delBanco, setDelBanco] = useState(false);

    return delBanco ? (
        <DesdeUnCredito
            fineId={fineId}
            creditos={creditos}
            onClose={onClose}
            onManual={() => setDelBanco(false)}
        />
    ) : (
        <Manual
            fineId={fineId}
            onClose={onClose}
            onCredito={allowBankCredit ? () => setDelBanco(true) : undefined}
        />
    );
}

function Manual({
    fineId,
    onClose,
    onCredito,
}: {
    fineId: number;
    onClose: () => void;
    onCredito?: () => void;
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
            {onCredito && (
                <OrigenDelPago delBanco={false} onChange={onCredito} />
            )}

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
                <PartialDateInput
                    id="antecedente-fecha"
                    className="mt-1"
                    value={data.occurredOn}
                    onChange={(fecha) => setData('occurredOn', fecha)}
                    // Los pagos anteriores del Excel se conocen por el mes.
                    monthFirst
                    max={businessToday()}
                    aria-invalid={Boolean(errors.occurredOn)}
                />
                <FieldError message={errors.occurredOn} />
            </div>

            <Respaldo
                value={data.evidence}
                onChange={(valor) => setData('evidence', valor)}
                error={errors.evidence}
            />
        </FormDialog>
    );
}

function DesdeUnCredito({
    fineId,
    creditos,
    onClose,
    onManual,
}: {
    fineId: number;
    creditos?: Credito[];
    onClose: () => void;
    onManual: () => void;
}) {
    const { data, setData, post, processing, errors } = useForm<{
        bankTransactionId: number | null;
        evidence: string;
    }>({ bankTransactionId: null, evidence: '' });

    return (
        <FormDialog
            title="Registrar un pago anterior"
            description="Un crédito de la cuenta de Multas anterior a la apertura de la caja. El importe y la fecha son los del banco: el pago no se reparte."
            onClose={onClose}
            onSubmit={() =>
                post(storeFromCredit(fineId).url, {
                    preserveScroll: true,
                    onSuccess: onClose,
                })
            }
            processing={processing}
            submitLabel="Registrar"
        >
            <OrigenDelPago delBanco onChange={onManual} />

            <CreditPicker
                prop="creditosDelBanco"
                param="credito"
                creditos={creditos}
                selected={data.bankTransactionId}
                onSelect={(id) => setData('bankTransactionId', id)}
                emptyText="No hay créditos anteriores a la apertura que coincidan y estén libres."
                error={errors.bankTransactionId}
            />

            <Respaldo
                value={data.evidence}
                onChange={(valor) => setData('evidence', valor)}
                error={errors.evidence}
                placeholder="Cómo se sabe que fue de esta multa: «nota de la empresa», «recibo de papel 64685»."
            />
        </FormDialog>
    );
}

function OrigenDelPago({
    delBanco,
    onChange,
}: {
    delBanco: boolean;
    onChange: () => void;
}) {
    return (
        <label className="flex items-start gap-3 rounded-md border p-3 text-sm">
            <Checkbox
                className="mt-0.5"
                checked={delBanco}
                onCheckedChange={onChange}
            />
            <span>
                <span className="font-medium">
                    Viene de un crédito del banco
                </span>
                <span className="mt-0.5 block text-xs text-muted-foreground">
                    Una transferencia que el extracto ya trae, anterior a la
                    apertura de la caja de Multas.
                </span>
            </span>
        </label>
    );
}

function Respaldo({
    value,
    onChange,
    error,
    placeholder = 'De dónde sale el dato: «Excel 2025, columna julio», «recibo de papel 64685».',
}: {
    value: string;
    onChange: (valor: string) => void;
    error?: string;
    placeholder?: string;
}) {
    return (
        <div>
            <Label htmlFor="antecedente-respaldo">Respaldo</Label>
            <Textarea
                id="antecedente-respaldo"
                className="mt-1"
                rows={2}
                maxLength={500}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                placeholder={placeholder}
                aria-invalid={Boolean(error)}
            />
            <FieldError message={error} />
        </div>
    );
}
