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
import { Textarea } from '@/components/ui/textarea';
import FormDialog, {
    FieldError,
} from '@/features/multas/components/form-dialog';
import { businessToday, money, parseAmount } from '@/lib/format';
import { store } from '@/routes/multas/pagos-bancarios';

type Pago = App.Modules.Multas.Data.FinePaymentData;
type Medio = 'bank_transfer' | 'direct_deposit' | 'echeq';

const MEDIOS: Record<Medio, string> = {
    bank_transfer: 'Transferencia',
    direct_deposit: 'Depósito directo',
    echeq: 'Cheque electrónico',
};

/**
 * Un pago que llega por el banco (M02): solo se **informa**.
 *
 * Alguien presentó el comprobante y el pago queda a la espera del crédito,
 * sin tocar el saldo. Conciliarlo es otro paso, con «Verificar» en la fila
 * del pago, igual que en Haberes se carga el comprobante y después se cruza
 * con el extracto. Informar primero es lo que permite cargar la fecha real
 * de presentación, que decide el término (M08).
 *
 * Un crédito que nadie informó se identifica desde Banco › Movimientos. La
 * fecha de pago del comprobante se carga con su archivo, en «Comprobante».
 */
export default function BankPaymentDialog({
    fineId,
    idempotencyKey,
    corrige,
    onClose,
}: {
    fineId: number;
    idempotencyKey: string;
    /** El pago informado que se corrige, si es una corrección. */
    corrige?: Pago;
    onClose: () => void;
}) {
    const hoy = businessToday();
    const { data, setData, post, transform, processing, errors } = useForm({
        medium: (corrige?.medium ?? 'bank_transfer') as Medio,
        amount: corrige ? money(corrige.amount, { symbol: false }) : '',
        presentedOn: hoy,
        presentationEvidence: '',
        judicial: Boolean(corrige?.judicialReference),
        judicialReference: corrige?.judicialReference ?? '',
        correctionReason: '',
    });
    const errores = errors as Record<string, string | undefined>;
    const atrasado = data.presentedOn !== '' && data.presentedOn < hoy;
    const transferencia = data.medium === 'bank_transfer';

    const enviar = () => {
        const judicial =
            transferencia && data.judicial
                ? data.judicialReference.trim()
                : null;

        transform((datos) => ({
            medium: datos.medium,
            amount: parseAmount(datos.amount),
            presentedOn: datos.presentedOn === hoy ? null : datos.presentedOn,
            presentationEvidence: atrasado
                ? datos.presentationEvidence.trim()
                : null,
            judicialReference: judicial,
            ...(corrige
                ? {
                      replacesPaymentId: corrige.id,
                      correctionReason: datos.correctionReason.trim(),
                  }
                : {}),
            idempotencyKey,
        }));
        post(store(fineId).url, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <FormDialog
            title={corrige ? 'Corregir el pago informado' : 'Pago por banco'}
            description={
                corrige
                    ? 'El informado se descarta con el motivo y queda este en su lugar, con la misma presentación.'
                    : 'El pago queda informado hasta verificarlo contra el extracto: todavía no reduce el saldo.'
            }
            onClose={onClose}
            onSubmit={enviar}
            processing={processing}
            submitLabel={corrige ? 'Corregir' : 'Informar'}
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <div>
                    <Label htmlFor="banco-medio">Medio</Label>
                    <Select
                        value={data.medium}
                        onValueChange={(v) => setData('medium', v as Medio)}
                    >
                        <SelectTrigger id="banco-medio" className="mt-1">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {(Object.keys(MEDIOS) as Medio[]).map((m) => (
                                <SelectItem key={m} value={m}>
                                    {MEDIOS[m]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <FieldError message={errors.medium} />
                </div>
                <div>
                    <Label htmlFor="banco-importe">Importe</Label>
                    <div className="mt-1">
                        <AmountInput
                            id="banco-importe"
                            value={data.amount}
                            onChange={(v) => setData('amount', v)}
                            placeholder="0,00"
                            aria-invalid={Boolean(errors.amount)}
                        />
                    </div>
                    <FieldError message={errors.amount} />
                </div>
            </div>

            {!corrige && (
                <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                        <Label htmlFor="banco-presentado">Presentado el</Label>
                        <DateInput
                            id="banco-presentado"
                            className="mt-1"
                            max={hoy}
                            value={data.presentedOn}
                            onChange={(e) =>
                                setData('presentedOn', e.target.value)
                            }
                            aria-invalid={Boolean(errors.presentedOn)}
                            aria-describedby="banco-presentado-ayuda"
                        />
                        <p
                            id="banco-presentado-ayuda"
                            className="mt-1 text-xs text-muted-foreground"
                        >
                            Día en que se avisó el pago a la Secretaría. Define
                            si está en término.
                        </p>
                        <FieldError message={errors.presentedOn} />
                    </div>
                </div>
            )}
            {atrasado && (
                <div>
                    <Label htmlFor="banco-respaldo">
                        Respaldo de la presentación
                    </Label>
                    <Input
                        id="banco-respaldo"
                        className="mt-1"
                        maxLength={300}
                        value={data.presentationEvidence}
                        onChange={(e) =>
                            setData('presentationEvidence', e.target.value)
                        }
                        placeholder="Sello de Mesa de Entradas, nota de la empresa…"
                        aria-invalid={Boolean(errors.presentationEvidence)}
                    />
                    <FieldError message={errors.presentationEvidence} />
                </div>
            )}

            {transferencia && (
                <div className="grid gap-2">
                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="banco-judicial"
                            checked={data.judicial}
                            onCheckedChange={(v) =>
                                setData('judicial', v === true)
                            }
                        />
                        <Label htmlFor="banco-judicial">
                            Es una orden de pago judicial
                        </Label>
                    </div>
                    {data.judicial && (
                        <div>
                            <Label htmlFor="banco-judicial-ref">
                                Expediente judicial
                            </Label>
                            <Input
                                id="banco-judicial-ref"
                                className="mt-1 font-mono"
                                maxLength={120}
                                value={data.judicialReference}
                                onChange={(e) =>
                                    setData('judicialReference', e.target.value)
                                }
                                aria-invalid={Boolean(errors.judicialReference)}
                            />
                            <FieldError message={errors.judicialReference} />
                        </div>
                    )}
                </div>
            )}
            {corrige && (
                <div>
                    <Label htmlFor="banco-correccion">Qué se corrige</Label>
                    <Textarea
                        id="banco-correccion"
                        className="mt-1"
                        rows={2}
                        maxLength={400}
                        value={data.correctionReason}
                        onChange={(e) =>
                            setData('correctionReason', e.target.value)
                        }
                        placeholder="El importe estaba mal tipeado, no era una transferencia…"
                        aria-invalid={Boolean(errores.correctionReason)}
                    />
                    <FieldError
                        message={
                            errores.correctionReason ??
                            errores.replacesPaymentId ??
                            errores.reason
                        }
                    />
                </div>
            )}
        </FormDialog>
    );
}
