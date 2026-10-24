import { useForm } from '@inertiajs/react';
import AmountInput from '@/components/amount-input';
import DateInput from '@/components/date-input';
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
import FormDialog, {
    FieldError,
} from '@/features/multas/components/form-dialog';
import { businessToday, money, parseAmount } from '@/lib/format';
import { store } from '@/routes/multas/cobros';

export type Firmante = { id: number; name: string; position: string | null };

const SIN_FIRMANTE = 'ninguno';

/**
 * El cobro en la caja: efectivo o cheque, con su recibo en el acto (M02).
 *
 * El importe arranca en el saldo, pero se cobra lo que se recibe: un pago
 * parcial deja saldo, y uno que lo supera deja excedente, que no se devuelve
 * solo (M05). El cheque entra por su importe entero.
 *
 * La fecha cambia solo para cargar un cobro de un día anterior, y entonces
 * pide el número del recibo de talonario que se entregó ese día.
 */
export default function CollectPaymentDialog({
    fineId,
    caption,
    debt,
    idempotencyKey,
    firmantes,
    onClose,
}: {
    fineId: number;
    caption: string;
    debt: string;
    idempotencyKey: string;
    firmantes: Firmante[];
    onClose: () => void;
}) {
    const hoy = businessToday();
    const { data, setData, post, transform, processing, errors } = useForm({
        medium: 'cash' as 'cash' | 'cheque',
        amount: money(debt, { symbol: false }),
        receivedOn: hoy,
        talonarioNumber: '',
        signedById: SIN_FIRMANTE,
        judicial: false,
        judicialReference: '',
        cheque: { number: '', bank: '', issueDate: '' },
    });

    const errores = errors as Record<string, string | undefined>;
    const conCheque = data.medium === 'cheque';
    const atrasado = data.receivedOn !== '' && data.receivedOn < hoy;

    const enviar = () => {
        transform((datos) => ({
            medium: datos.medium,
            amount: parseAmount(datos.amount),
            receivedOn: datos.receivedOn === hoy ? null : datos.receivedOn,
            talonarioNumber: datos.talonarioNumber.trim() || null,
            signedById:
                datos.signedById === SIN_FIRMANTE
                    ? null
                    : Number(datos.signedById),
            judicialReference:
                conCheque && datos.judicial
                    ? datos.judicialReference.trim()
                    : null,
            cheque: conCheque
                ? {
                      number: datos.cheque.number.trim(),
                      bank: datos.cheque.bank.trim() || null,
                      issueDate: datos.cheque.issueDate || null,
                  }
                : null,
            idempotencyKey,
        }));

        post(store(fineId).url, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <FormDialog
            title="Cobrar en caja"
            description={
                <>
                    Recibí de <strong>{caption}</strong>. Saldo {money(debt)}.
                    Al confirmar se registra el cobro, se aplica a la multa y se
                    emite el recibo.
                </>
            }
            onClose={onClose}
            onSubmit={enviar}
            processing={processing}
            submitLabel="Cobrar y emitir recibo"
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <div>
                    <Label htmlFor="cobro-medio">Medio</Label>
                    <Select
                        value={data.medium}
                        onValueChange={(valor) =>
                            setData('medium', valor as 'cash' | 'cheque')
                        }
                    >
                        <SelectTrigger id="cobro-medio" className="mt-1">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="cash">Efectivo</SelectItem>
                            <SelectItem value="cheque">Cheque</SelectItem>
                        </SelectContent>
                    </Select>
                    <FieldError message={errors.medium} />
                </div>
                <div>
                    <Label htmlFor="cobro-importe">
                        {conCheque ? 'Importe del cheque' : 'Importe recibido'}
                    </Label>
                    <div className="mt-1">
                        <AmountInput
                            id="cobro-importe"
                            value={data.amount}
                            onChange={(valor) => setData('amount', valor)}
                            placeholder="0,00"
                            aria-invalid={Boolean(errors.amount)}
                        />
                    </div>
                    <FieldError message={errors.amount} />
                </div>
            </div>

            {conCheque && (
                <>
                    <div className="grid gap-4 sm:grid-cols-3">
                        <div>
                            <Label htmlFor="cobro-cheque">N.º de cheque</Label>
                            <Input
                                id="cobro-cheque"
                                className="mt-1 font-mono"
                                value={data.cheque.number}
                                maxLength={50}
                                onChange={(e) =>
                                    setData('cheque', {
                                        ...data.cheque,
                                        number: e.target.value,
                                    })
                                }
                                aria-invalid={Boolean(errores['cheque.number'])}
                            />
                            <FieldError message={errores['cheque.number']} />
                        </div>
                        <div>
                            <Label htmlFor="cobro-banco">Banco</Label>
                            <Input
                                id="cobro-banco"
                                className="mt-1"
                                value={data.cheque.bank}
                                maxLength={120}
                                onChange={(e) =>
                                    setData('cheque', {
                                        ...data.cheque,
                                        bank: e.target.value,
                                    })
                                }
                            />
                        </div>
                        <div>
                            <Label htmlFor="cobro-cheque-fecha">
                                Fecha del cheque
                            </Label>
                            <DateInput
                                id="cobro-cheque-fecha"
                                className="mt-1"
                                value={data.cheque.issueDate}
                                onChange={(e) =>
                                    setData('cheque', {
                                        ...data.cheque,
                                        issueDate: e.target.value,
                                    })
                                }
                            />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="cobro-judicial"
                                checked={data.judicial}
                                onCheckedChange={(v) =>
                                    setData('judicial', v === true)
                                }
                            />
                            <Label htmlFor="cobro-judicial">
                                Es una orden de pago judicial
                            </Label>
                        </div>
                        {data.judicial && (
                            <div>
                                <Label htmlFor="cobro-judicial-ref">
                                    Expediente judicial
                                </Label>
                                <Input
                                    id="cobro-judicial-ref"
                                    className="mt-1 font-mono"
                                    value={data.judicialReference}
                                    maxLength={120}
                                    onChange={(e) =>
                                        setData(
                                            'judicialReference',
                                            e.target.value,
                                        )
                                    }
                                    aria-invalid={Boolean(
                                        errors.judicialReference,
                                    )}
                                />
                                <FieldError
                                    message={errors.judicialReference}
                                />
                            </div>
                        )}
                    </div>
                </>
            )}

            <div className="grid gap-4 sm:grid-cols-2">
                <div>
                    <Label htmlFor="cobro-fecha">Fecha del cobro</Label>
                    <DateInput
                        id="cobro-fecha"
                        className="mt-1"
                        max={hoy}
                        value={data.receivedOn}
                        onChange={(e) => setData('receivedOn', e.target.value)}
                        aria-invalid={Boolean(errors.receivedOn)}
                    />
                    <FieldError message={errors.receivedOn} />
                </div>
                <div>
                    <Label htmlFor="cobro-talonario">
                        N.º de talonario{atrasado ? '' : ' (opcional)'}
                    </Label>
                    <Input
                        id="cobro-talonario"
                        className="mt-1 font-mono"
                        value={data.talonarioNumber}
                        maxLength={20}
                        required={atrasado}
                        onChange={(e) =>
                            setData('talonarioNumber', e.target.value)
                        }
                        aria-invalid={Boolean(errors.talonarioNumber)}
                    />
                    <FieldError message={errors.talonarioNumber} />
                </div>
            </div>
            {atrasado && (
                <p className="text-xs text-muted-foreground">
                    Un cobro de un día anterior se carga desde el recibo de
                    talonario que se entregó ese día: su número respalda la
                    fecha.
                </p>
            )}

            {firmantes.length > 0 && (
                <div>
                    <Label htmlFor="cobro-firmante">Aclaración al pie</Label>
                    <Select
                        value={data.signedById}
                        onValueChange={(valor) => setData('signedById', valor)}
                    >
                        <SelectTrigger id="cobro-firmante" className="mt-1">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={SIN_FIRMANTE}>
                                Sin aclaración impresa
                            </SelectItem>
                            {firmantes.map((f) => (
                                <SelectItem key={f.id} value={String(f.id)}>
                                    {f.name}
                                    {f.position ? ` · ${f.position}` : ''}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <FieldError message={errors.signedById} />
                </div>
            )}
        </FormDialog>
    );
}
