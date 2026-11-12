import { router, useForm } from '@inertiajs/react';
import { BadgeCheck } from 'lucide-react';
import { useEffect } from 'react';
import Money from '@/components/money';
import type { Firmante } from '@/features/multas/components/collect-payment-dialog';
import CreditPicker from '@/features/multas/components/credit-picker';
import FormDialog, {
    FieldError,
} from '@/features/multas/components/form-dialog';
import ReceiptFields, {
    receiptPayload,
    SIN_FIRMANTE,
} from '@/features/multas/components/receipt-fields';
import { PAYMENT_MEDIUM } from '@/features/multas/labels';
import { date, money } from '@/lib/format';
import { cn } from '@/lib/utils';
import { verify } from '@/routes/multas/pagos';

type Pago = App.Modules.Multas.Data.FinePaymentData;
type Credito = App.Modules.Multas.Data.FineBankCreditData;
type Candidato = App.Modules.Multas.Data.FineCreditCandidateData;

/**
 * Verificar un pago informado contra el crédito del extracto que lo trajo.
 *
 * Al abrir propone los créditos más probables —el importe entero, el CUIT de
 * la persona de la multa, la cercanía a la fecha del comprobante—, como hace
 * Haberes con el ticket. Buscar a mano en todo el extracto queda solo para
 * super-admin (`dev.buscar-creditos-multas`). No se envía sin un crédito elegido.
 * Un crédito por pago, por su importe entero (P14, provisorio). Al
 * verificarlo, el pago reduce el saldo y sale su recibo.
 */
export default function VerifyPaymentDialog({
    pago,
    candidatos,
    puedeBuscar,
    creditos,
    firmantes,
    onClose,
}: {
    pago: Pago;
    /** Los propuestos para este pago; llegan al abrir. */
    candidatos?: Candidato[];
    /** Buscar a mano en el extracto, además de lo propuesto: solo super-admin. */
    puedeBuscar: boolean;
    creditos?: Credito[];
    firmantes: Firmante[];
    onClose: () => void;
}) {
    const { data, setData, post, transform, processing, errors } = useForm({
        bankTransactionId: null as number | null,
        talonarioNumber: '',
        signedById: SIN_FIRMANTE,
    });

    useEffect(() => {
        router.reload({
            only: ['candidatosDelPago'],
            data: { candidatos: pago.id },
        });
    }, [pago.id]);

    const enviar = () => {
        transform((datos) => ({
            bankTransactionId: datos.bankTransactionId,
            ...receiptPayload(datos.talonarioNumber, datos.signedById),
        }));
        post(verify(pago.id).url, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <FormDialog
            title="Verificar contra el extracto"
            description={`Pago informado: ${PAYMENT_MEDIUM[pago.medium].toLowerCase()} por ${money(pago.amount)}. Elegí el crédito de ese importe que lo trajo.`}
            onClose={onClose}
            onSubmit={enviar}
            processing={processing}
            submitLabel="Verificar y emitir recibo"
            submitDisabled={data.bankTransactionId === null}
        >
            <Propuestos
                candidatos={candidatos}
                puedeBuscar={puedeBuscar}
                selected={data.bankTransactionId}
                onSelect={(id) => setData('bankTransactionId', id)}
            />
            {puedeBuscar ? (
                <CreditPicker
                    prop="creditosDisponibles"
                    param="disponible"
                    creditos={creditos}
                    selected={data.bankTransactionId}
                    onSelect={(id) => setData('bankTransactionId', id)}
                    emptyText="No hay créditos posteriores a la apertura que coincidan y estén libres."
                    error={errors.bankTransactionId}
                />
            ) : (
                <FieldError message={errors.bankTransactionId} />
            )}
            <ReceiptFields
                idPrefix="verificar"
                talonarioNumber={data.talonarioNumber}
                onTalonarioChange={(v) => setData('talonarioNumber', v)}
                signedById={data.signedById}
                onSignedByChange={(v) => setData('signedById', v)}
                firmantes={firmantes}
                errors={errors}
            />
        </FormDialog>
    );
}

/** Los créditos que el sistema propone, con por qué. */
function Propuestos({
    candidatos,
    puedeBuscar,
    selected,
    onSelect,
}: {
    candidatos?: Candidato[];
    puedeBuscar: boolean;
    selected: number | null;
    onSelect: (id: number) => void;
}) {
    if (candidatos === undefined) {
        return (
            <p className="text-sm text-muted-foreground">
                Buscando los créditos que coinciden…
            </p>
        );
    }

    if (candidatos.length === 0) {
        return (
            <p className="rounded-md border border-dashed p-3 text-sm text-muted-foreground">
                Ningún crédito libre tiene este importe. Puede que falte
                importar el extracto, o que el importe informado no sea el del
                banco
                {puedeBuscar
                    ? ': buscalo debajo.'
                    : ': si es eso, corregí el pago informado.'}
            </p>
        );
    }

    return (
        <div>
            <p className="text-sm font-medium">
                {candidatos.length === 1
                    ? 'Crédito que coincide'
                    : 'Créditos que coinciden'}
            </p>
            <ul className="mt-1 divide-y rounded-md border" role="radiogroup">
                {candidatos.map(({ credit, signals, payerMatches }) => {
                    const elegido = selected === credit.id;

                    return (
                        <li key={credit.id}>
                            <button
                                type="button"
                                role="radio"
                                aria-checked={elegido}
                                onClick={() => onSelect(credit.id)}
                                className={cn(
                                    'w-full px-3 py-2 text-left text-sm hover:bg-muted/50',
                                    elegido &&
                                        'bg-primary/10 hover:bg-primary/10',
                                )}
                            >
                                <span className="flex flex-wrap items-baseline gap-x-3">
                                    <span className="font-medium">
                                        <Money value={credit.amount} />
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {date(credit.date)}
                                    </span>
                                    <span className="min-w-0 flex-1 truncate text-xs text-muted-foreground">
                                        {credit.counterparty ??
                                            credit.description ??
                                            ''}
                                        {credit.operationId &&
                                            ` · Op. ${credit.operationId}`}
                                    </span>
                                </span>
                                <span className="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-xs">
                                    {Object.entries(signals).map(
                                        ([clave, valor]) => (
                                            <span
                                                key={clave}
                                                className="inline-flex items-center gap-1"
                                            >
                                                {clave ===
                                                    'CUIT del concepto' &&
                                                    payerMatches && (
                                                        <BadgeCheck className="size-3.5 text-primary" />
                                                    )}
                                                <span className="text-field-label">
                                                    {clave}:
                                                </span>
                                                <span
                                                    className={cn(
                                                        valor.includes(
                                                            'no es el de la persona',
                                                        ) &&
                                                            'text-warning-strong',
                                                    )}
                                                >
                                                    {valor}
                                                </span>
                                            </span>
                                        ),
                                    )}
                                </span>
                            </button>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}
