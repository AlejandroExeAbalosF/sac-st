import { useForm } from '@inertiajs/react';
import AmountInput from '@/components/amount-input';
import DateInput from '@/components/date-input';
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
import type { Firmante } from '@/features/multas/components/collect-payment-dialog';
import FormDialog, {
    FieldError,
} from '@/features/multas/components/form-dialog';
import ReceiptFields, {
    receiptPayload,
    SIN_FIRMANTE,
} from '@/features/multas/components/receipt-fields';
import { APPEAL_KIND } from '@/features/multas/labels';
import {
    businessToday,
    compareAmounts,
    money,
    parseAmount,
} from '@/lib/format';
import { store } from '@/routes/multas/recursos';

type Kind = App.Modules.Multas.Enums.AppealKind;
type Medio = 'cash' | 'direct_deposit';
type Pago = App.Modules.Multas.Data.FinePaymentData;

/**
 * El pago del 20 % para recurrir la multa (M07).
 *
 * Se cobra lo que dice el recibo externo que trae el sancionado: la
 * liquidación la hace otra oficina. El sistema muestra el 20 % que calcula
 * sobre el importe vigente, sin centavos, como referencia; si difieren, se
 * cobra igual y la diferencia queda a la vista. Una multa admite un solo
 * recurso.
 *
 * En efectivo se cobra en el acto; por depósito directo queda informado, y su
 * presentación —con respaldo, si fue un día anterior— decide el término (M08).
 * Un depósito informado que se cargó mal se corrige desde acá.
 */
export default function AppealDialog({
    fineId,
    base,
    required,
    allowCash,
    firmantes,
    idempotencyKey,
    corrige,
    onClose,
}: {
    fineId: number;
    base: string;
    required: string;
    /** El efectivo se cobra en la caja: hace falta poder cobrar y la caja abierta. */
    allowCash: boolean;
    firmantes: Firmante[];
    idempotencyKey: string;
    /** El depósito informado que se corrige, si es una corrección. */
    corrige?: Pago;
    onClose: () => void;
}) {
    const hoy = businessToday();
    const { data, setData, post, transform, processing, errors } = useForm({
        kind: (corrige?.appealKind ?? 'hierarchical') as Kind,
        medium: (corrige || !allowCash ? 'direct_deposit' : 'cash') as Medio,
        externalReference: corrige?.externalReceiptReference ?? '',
        amount: money(corrige?.amount ?? required, { symbol: false }),
        declaredPaidOn: corrige?.declaredPaidOn ?? '',
        presentedOn: hoy,
        presentationEvidence: '',
        talonarioNumber: '',
        signedById: SIN_FIRMANTE,
        correctionReason: '',
    });
    const efectivo = data.medium === 'cash';
    const importe = parseAmount(data.amount);
    const difiere = importe !== '' && compareAmounts(importe, required) !== 0;
    const atrasado =
        !efectivo && data.presentedOn !== '' && data.presentedOn < hoy;

    const enviar = () => {
        transform((datos) => ({
            kind: datos.kind,
            medium: datos.medium,
            externalReference: datos.externalReference.trim(),
            amount: parseAmount(datos.amount),
            declaredPaidOn: efectivo ? null : datos.declaredPaidOn || null,
            presentedOn: atrasado ? datos.presentedOn : null,
            presentationEvidence: atrasado
                ? datos.presentationEvidence.trim()
                : null,
            ...(efectivo
                ? receiptPayload(datos.talonarioNumber, datos.signedById)
                : {}),
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

    const errores = errors as Record<string, string | undefined>;

    return (
        <FormDialog
            title={
                corrige ? 'Corregir el recurso informado' : 'Pago por recurso'
            }
            description={
                <>
                    Se cobra el importe del recibo externo. Como referencia, el
                    20 % de {money(base)}, sin centavos, es{' '}
                    <strong>{money(required)}</strong>.
                </>
            }
            onClose={onClose}
            onSubmit={enviar}
            processing={processing}
            submitLabel={
                corrige
                    ? 'Corregir'
                    : efectivo
                      ? 'Cobrar y emitir recibo'
                      : 'Registrar'
            }
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <div>
                    <Label htmlFor="recurso-tipo">Recurso</Label>
                    <Select
                        value={data.kind}
                        onValueChange={(v) => setData('kind', v as Kind)}
                    >
                        <SelectTrigger id="recurso-tipo" className="mt-1">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {(Object.keys(APPEAL_KIND) as Kind[]).map((k) => (
                                <SelectItem key={k} value={k}>
                                    {APPEAL_KIND[k]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <FieldError message={errores.kind} />
                </div>
                <div>
                    <Label htmlFor="recurso-medio">Medio</Label>
                    <Select
                        value={data.medium}
                        onValueChange={(v) => setData('medium', v as Medio)}
                        disabled={Boolean(corrige)}
                    >
                        <SelectTrigger id="recurso-medio" className="mt-1">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {allowCash && (
                                <SelectItem value="cash">Efectivo</SelectItem>
                            )}
                            <SelectItem value="direct_deposit">
                                Depósito directo
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <FieldError message={errors.medium} />
                </div>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <div>
                    <Label htmlFor="recurso-externo">
                        Recibo externo N.º (opcional)
                    </Label>
                    <Input
                        id="recurso-externo"
                        className="mt-1 font-mono"
                        maxLength={120}
                        value={data.externalReference}
                        onChange={(e) =>
                            setData('externalReference', e.target.value)
                        }
                        aria-invalid={Boolean(errors.externalReference)}
                    />
                    <FieldError message={errors.externalReference} />
                </div>
                <div>
                    <Label htmlFor="recurso-importe">
                        Importe del recibo externo
                    </Label>
                    <div className="mt-1">
                        <AmountInput
                            id="recurso-importe"
                            value={data.amount}
                            onChange={(v) => setData('amount', v)}
                            aria-invalid={Boolean(errors.amount)}
                        />
                    </div>
                    <FieldError message={errors.amount} />
                </div>
            </div>

            {difiere && (
                <p className="rounded-md border border-dashed p-3 text-xs text-muted-foreground">
                    Difiere del 20 % calculado ({money(required)}). Se cobra el
                    importe del recibo externo, y la diferencia queda a la vista
                    en la multa.
                </p>
            )}

            {efectivo && (
                <ReceiptFields
                    idPrefix="recurso"
                    talonarioNumber={data.talonarioNumber}
                    onTalonarioChange={(v) => setData('talonarioNumber', v)}
                    signedById={data.signedById}
                    onSignedByChange={(v) => setData('signedById', v)}
                    firmantes={firmantes}
                    errors={errors}
                />
            )}

            {!efectivo && (
                <div className="grid gap-4 sm:grid-cols-2">
                    {!corrige && (
                        <div>
                            <Label htmlFor="recurso-presentado">
                                Presentado el
                            </Label>
                            <DateInput
                                id="recurso-presentado"
                                className="mt-1"
                                max={hoy}
                                value={data.presentedOn}
                                onChange={(e) =>
                                    setData('presentedOn', e.target.value)
                                }
                                aria-invalid={Boolean(errors.presentedOn)}
                            />
                            <FieldError message={errors.presentedOn} />
                        </div>
                    )}
                    <div>
                        <Label htmlFor="recurso-pagado">
                            Depositado el (según el comprobante)
                        </Label>
                        <DateInput
                            id="recurso-pagado"
                            className="mt-1"
                            max={hoy}
                            value={data.declaredPaidOn}
                            onChange={(e) =>
                                setData('declaredPaidOn', e.target.value)
                            }
                            aria-invalid={Boolean(errors.declaredPaidOn)}
                        />
                        <FieldError message={errors.declaredPaidOn} />
                    </div>
                    {atrasado && (
                        <div className="sm:col-span-2">
                            <Label htmlFor="recurso-respaldo">
                                Respaldo de la presentación
                            </Label>
                            <Input
                                id="recurso-respaldo"
                                className="mt-1"
                                maxLength={300}
                                value={data.presentationEvidence}
                                onChange={(e) =>
                                    setData(
                                        'presentationEvidence',
                                        e.target.value,
                                    )
                                }
                                placeholder="Sello de Mesa de Entradas…"
                                aria-invalid={Boolean(
                                    errors.presentationEvidence,
                                )}
                            />
                            <FieldError message={errors.presentationEvidence} />
                        </div>
                    )}
                </div>
            )}

            {corrige && (
                <div>
                    <Label htmlFor="recurso-correccion">Qué se corrige</Label>
                    <Textarea
                        id="recurso-correccion"
                        className="mt-1"
                        rows={2}
                        maxLength={400}
                        value={data.correctionReason}
                        onChange={(e) =>
                            setData('correctionReason', e.target.value)
                        }
                        placeholder="El recibo externo decía otro importe, el número estaba mal…"
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
