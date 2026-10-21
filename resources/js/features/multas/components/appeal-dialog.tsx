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

/**
 * El pago del 20 % para recurrir la multa (M07).
 *
 * El importe no se escribe: es el 20 % del importe vigente, sin centavos, y
 * lo calcula el servidor. Lo que se carga es lo que dice el recibo externo
 * que trae el sancionado. Si coincide, en efectivo se cobra en el acto y por
 * depósito directo queda informado; si no, queda en revisión, sin cobro ni
 * recibo, hasta que el administrador lo resuelva. Lo mismo si la multa ya
 * tiene otro recurso sin descartar (P05, provisorio).
 *
 * El depósito directo puede haberse presentado antes de cargarlo: la
 * presentación, con su respaldo, decide el término (M08).
 */
export default function AppealDialog({
    fineId,
    base,
    required,
    repeated,
    allowCash,
    firmantes,
    idempotencyKey,
    onClose,
}: {
    fineId: number;
    base: string;
    required: string;
    /** La multa ya tiene otro recurso sin descartar: este va a revisión. */
    repeated: boolean;
    /** El efectivo se cobra en la caja: hace falta poder cobrar y la caja abierta. */
    allowCash: boolean;
    firmantes: Firmante[];
    idempotencyKey: string;
    onClose: () => void;
}) {
    const { data, setData, post, transform, processing, errors } = useForm({
        kind: 'hierarchical' as Kind,
        medium: (allowCash ? 'cash' : 'direct_deposit') as Medio,
        externalReference: '',
        externalAmount: money(required, { symbol: false }),
        declaredPaidOn: '',
        presentedOn: businessToday(),
        presentationEvidence: '',
        talonarioNumber: '',
        signedById: SIN_FIRMANTE,
    });
    const efectivo = data.medium === 'cash';
    const externo = parseAmount(data.externalAmount);
    const coincide = externo !== '' && compareAmounts(externo, required) === 0;
    const enRevision = !coincide || repeated;
    const hoy = businessToday();
    const atrasado =
        !efectivo && data.presentedOn !== '' && data.presentedOn < hoy;

    const enviar = () => {
        transform((datos) => ({
            kind: datos.kind,
            medium: datos.medium,
            externalReference: datos.externalReference.trim(),
            externalAmount: parseAmount(datos.externalAmount),
            declaredPaidOn: efectivo ? null : datos.declaredPaidOn || null,
            presentedOn: atrasado ? datos.presentedOn : null,
            presentationEvidence: atrasado
                ? datos.presentationEvidence.trim()
                : null,
            ...(efectivo
                ? receiptPayload(datos.talonarioNumber, datos.signedById)
                : {}),
            idempotencyKey,
        }));
        post(store(fineId).url, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <FormDialog
            title="Pago por recurso"
            description={
                <>
                    El 20 % de {money(base)}, sin centavos:{' '}
                    <strong>{money(required)}</strong>. Se cobra exactamente
                    eso.
                </>
            }
            onClose={onClose}
            onSubmit={enviar}
            processing={processing}
            submitLabel={
                enRevision
                    ? 'Registrar para revisión'
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
                </div>
                <div>
                    <Label htmlFor="recurso-medio">Medio</Label>
                    <Select
                        value={data.medium}
                        onValueChange={(v) => setData('medium', v as Medio)}
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
                    <Label htmlFor="recurso-externo">Recibo externo N.º</Label>
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
                            value={data.externalAmount}
                            onChange={(v) => setData('externalAmount', v)}
                            aria-invalid={Boolean(errors.externalAmount)}
                        />
                    </div>
                    <FieldError message={errors.externalAmount} />
                </div>
            </div>

            {repeated && (
                <p className="rounded-md border border-dashed p-3 text-xs text-muted-foreground">
                    La multa ya tiene otro recurso. Se registra en revisión: no
                    se cobra ni se emite recibo hasta que el administrador lo
                    resuelva.
                </p>
            )}

            {!coincide && data.externalAmount !== '' && (
                <p className="rounded-md border border-dashed p-3 text-xs text-muted-foreground">
                    No coincide con el 20 % calculado. Se registra en revisión:
                    no se cobra ni se emite recibo hasta que el administrador
                    revise la multa, sus ajustes y el redondeo.
                </p>
            )}

            {efectivo && !enRevision && (
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
                    <div>
                        <Label htmlFor="recurso-presentado">
                            Presentado el
                        </Label>
                        <Input
                            id="recurso-presentado"
                            type="date"
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
                    {atrasado && (
                        <div>
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

            {!efectivo && (
                <div>
                    <Label htmlFor="recurso-pagado">
                        Depositado el (según el comprobante)
                    </Label>
                    <Input
                        id="recurso-pagado"
                        type="date"
                        className="mt-1 max-w-48"
                        max={hoy}
                        value={data.declaredPaidOn}
                        onChange={(e) =>
                            setData('declaredPaidOn', e.target.value)
                        }
                        aria-invalid={Boolean(errors.declaredPaidOn)}
                    />
                    <FieldError message={errors.declaredPaidOn} />
                </div>
            )}
        </FormDialog>
    );
}
