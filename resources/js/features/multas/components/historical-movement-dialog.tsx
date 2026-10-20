import { router, useForm } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useState } from 'react';
import AmountInput from '@/components/amount-input';
import Money from '@/components/money';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { date, parseAmount } from '@/lib/format';
import { cn } from '@/lib/utils';
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
    const [termino, setTermino] = useState('');
    const [buscando, setBuscando] = useState(false);
    const { data, setData, post, processing, errors } = useForm<{
        bankTransactionId: number | null;
        evidence: string;
    }>({ bankTransactionId: null, evidence: '' });

    const buscar = () => {
        setBuscando(true);
        router.reload({
            only: ['creditosDelBanco'],
            data: { credito: termino },
            onFinish: () => setBuscando(false),
        });
    };

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

            <div>
                <Label htmlFor="credito-busqueda">Buscar el crédito</Label>
                <div className="mt-1 flex gap-2">
                    <Input
                        id="credito-busqueda"
                        value={termino}
                        onChange={(e) => setTermino(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                buscar();
                            }
                        }}
                        placeholder="Importe, empresa, CUIT u operación"
                    />
                    <Button
                        type="button"
                        variant="outline"
                        onClick={buscar}
                        disabled={buscando}
                    >
                        <Search className="size-4" aria-hidden="true" />
                        Buscar
                    </Button>
                </div>
            </div>

            {creditos !== undefined && (
                <div className="max-h-60 overflow-y-auto rounded-md border">
                    {creditos.length === 0 ? (
                        <p className="p-3 text-sm text-muted-foreground">
                            No hay créditos anteriores a la apertura que
                            coincidan y estén libres.
                        </p>
                    ) : (
                        <ul className="divide-y" role="radiogroup">
                            {creditos.map((credito) => {
                                const elegido =
                                    data.bankTransactionId === credito.id;

                                return (
                                    <li key={credito.id}>
                                        <button
                                            type="button"
                                            role="radio"
                                            aria-checked={elegido}
                                            onClick={() =>
                                                setData(
                                                    'bankTransactionId',
                                                    credito.id,
                                                )
                                            }
                                            className={cn(
                                                'flex w-full flex-wrap items-baseline gap-x-3 gap-y-0.5 px-3 py-2 text-left text-sm hover:bg-muted/50',
                                                elegido &&
                                                    'bg-primary/10 hover:bg-primary/10',
                                            )}
                                        >
                                            <span className="font-medium">
                                                <Money value={credito.amount} />
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {date(credito.date)}
                                            </span>
                                            <span className="min-w-0 flex-1 truncate text-xs text-muted-foreground">
                                                {credito.counterparty ??
                                                    credito.description ??
                                                    ''}
                                                {credito.operationId &&
                                                    ` · Op. ${credito.operationId}`}
                                            </span>
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>
            )}
            <FieldError message={errors.bankTransactionId} />

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
