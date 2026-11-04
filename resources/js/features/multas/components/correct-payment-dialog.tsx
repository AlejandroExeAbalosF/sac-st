import { router, useForm } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import Money from '@/components/money';
import ReasonFields from '@/components/reason-fields';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Firmante } from '@/features/multas/components/collect-payment-dialog';
import CreditPicker from '@/features/multas/components/credit-picker';
import FormDialog, {
    FieldError,
} from '@/features/multas/components/form-dialog';
import ReceiptFields, {
    receiptPayload,
    SIN_FIRMANTE,
} from '@/features/multas/components/receipt-fields';
import { date, money } from '@/lib/format';
import { cn } from '@/lib/utils';
import { correct, voidVerified } from '@/routes/multas/pagos';

type Pago = App.Modules.Multas.Data.FinePaymentData;
type Credito = App.Modules.Multas.Data.FineBankCreditData;
type Destino = App.Modules.Multas.Data.FineCorrectionTargetData;
type Codigo = App.Modules.Multas.Enums.FineReasonCode;
type Accion = 'corregir' | 'anular';

/**
 * Corregir un pago bancario ya verificado: otra multa, otro crédito o los dos.
 *
 * Nada se edita: el pago queda anulado, con su recibo, y nace otro. El importe no
 * se escribe porque un pago es por el crédito entero (P14, provisorio). Si el
 * crédito no era de Multas, se anula sin pago nuevo y el crédito queda libre.
 */
export default function CorrectPaymentDialog({
    pago,
    fineId,
    multasDestino,
    creditoActual,
    creditos,
    firmantes,
    onClose,
}: {
    pago: Pago;
    fineId: number;
    multasDestino?: Destino[];
    creditoActual?: Credito;
    creditos?: Credito[];
    firmantes: Firmante[];
    onClose: () => void;
}) {
    const esRecurso = pago.appealKind !== null;
    const [accion, setAccion] = useState<Accion>('corregir');
    const [otraMulta, setOtraMulta] = useState(false);
    const [otroCredito, setOtroCredito] = useState(esRecurso);
    const [destinoElegido, setDestinoElegido] = useState<Destino | null>(null);
    const { data, setData, post, transform, processing, errors } = useForm({
        fineId,
        bankTransactionId: null as number | null,
        reasonCode: '' as Codigo | '',
        reason: '',
        talonarioNumber: '',
        signedById: SIN_FIRMANTE,
    });

    // El crédito que lo respalda no viaja con la página: se pide al abrir.
    useEffect(() => {
        router.reload({ only: ['creditosActuales'] });
    }, []);

    const enviar = () => {
        if (accion === 'anular') {
            transform((datos) => ({
                reasonCode: datos.reasonCode,
                reason: datos.reason,
            }));
            post(voidVerified(pago.id).url, {
                preserveScroll: true,
                onSuccess: onClose,
            });

            return;
        }

        transform((datos) => ({
            fineId: otraMulta ? destinoElegido?.id : fineId,
            bankTransactionId: otroCredito
                ? datos.bankTransactionId
                : creditoActual?.id,
            reasonCode: datos.reasonCode,
            reason: datos.reason,
            ...receiptPayload(datos.talonarioNumber, datos.signedById),
        }));
        post(correct(pago.id).url, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    const creditoNuevo = otroCredito
        ? creditos?.find((c) => c.id === data.bankTransactionId)
        : creditoActual;

    return (
        <FormDialog
            title="Corregir el pago verificado"
            description={`${money(pago.amount)}, recibo ${pago.talonarioNumber ?? pago.receiptNumber ?? ''}. El pago y su recibo quedan anulados, a la vista, con su número. Lo ya rendido no cambia.`}
            onClose={onClose}
            onSubmit={enviar}
            processing={processing}
            submitLabel={
                accion === 'anular'
                    ? 'Anular y liberar el crédito'
                    : 'Corregir y emitir recibo'
            }
            destructive
        >
            <Opciones
                label="Qué hay que hacer"
                value={accion}
                onChange={setAccion}
                options={[
                    { value: 'corregir', label: 'Corregirlo' },
                    { value: 'anular', label: 'Solo anularlo' },
                ]}
            />

            {accion === 'anular' ? (
                <p className="text-sm text-muted-foreground">
                    La deuda vuelve y el crédito queda sin identificar, sin pago
                    nuevo.
                    {esRecurso &&
                        ' Es el pago del recurso en trámite: el recurso cae.'}
                </p>
            ) : (
                <>
                    {esRecurso ? (
                        <p className="text-sm text-muted-foreground">
                            Es el pago del recurso en trámite: se corrige dentro
                            de esta multa, eligiendo otro crédito.
                        </p>
                    ) : (
                        <Opciones
                            label="Multa"
                            value={otraMulta ? 'otra' : 'esta'}
                            onChange={(v) => setOtraMulta(v === 'otra')}
                            options={[
                                { value: 'esta', label: 'Esta multa' },
                                { value: 'otra', label: 'Otra multa' },
                            ]}
                        />
                    )}

                    {otraMulta && (
                        <TargetPicker
                            multas={multasDestino}
                            selected={destinoElegido?.id ?? null}
                            onSelect={setDestinoElegido}
                            error={errors.fineId}
                        />
                    )}

                    {!esRecurso && (
                        <Opciones
                            label="Crédito"
                            value={otroCredito ? 'otro' : 'mismo'}
                            onChange={(v) => setOtroCredito(v === 'otro')}
                            options={[
                                { value: 'mismo', label: 'El mismo crédito' },
                                { value: 'otro', label: 'Otro crédito' },
                            ]}
                        />
                    )}

                    {!otroCredito && creditoActual && (
                        <p className="text-sm text-muted-foreground">
                            Crédito del {date(creditoActual.date)} por{' '}
                            <Money value={creditoActual.amount} />
                            {creditoActual.operationId &&
                                ` · Op. ${creditoActual.operationId}`}
                        </p>
                    )}

                    {otroCredito && (
                        <CreditPicker
                            prop="creditosDisponibles"
                            param="disponible"
                            creditos={creditos}
                            selected={data.bankTransactionId}
                            onSelect={(id) => setData('bankTransactionId', id)}
                            emptyText="No hay créditos libres que coincidan."
                            error={errors.bankTransactionId}
                        />
                    )}
                    {!otroCredito && (
                        <FieldError message={errors.bankTransactionId} />
                    )}

                    {creditoNuevo && (
                        <p className="text-sm">
                            El pago nuevo es por el crédito entero:{' '}
                            <Money value={creditoNuevo.amount} />.
                        </p>
                    )}
                </>
            )}

            <ReasonFields
                act="payment_correction"
                code={data.reasonCode}
                detail={data.reason}
                onCodeChange={(codigo) => setData('reasonCode', codigo)}
                onDetailChange={(detalle) => setData('reason', detalle)}
                codeError={errors.reasonCode}
                detailError={errors.reason}
                detailPlaceholder="Qué pasó."
            />

            {accion === 'corregir' && (
                <ReceiptFields
                    idPrefix="corregir"
                    talonarioNumber={data.talonarioNumber}
                    onTalonarioChange={(v) => setData('talonarioNumber', v)}
                    signedById={data.signedById}
                    onSignedByChange={(v) => setData('signedById', v)}
                    firmantes={firmantes}
                    errors={errors}
                />
            )}
        </FormDialog>
    );
}

function Opciones<T extends string>({
    label,
    value,
    onChange,
    options,
}: {
    label: string;
    value: T;
    onChange: (value: T) => void;
    options: { value: T; label: string }[];
}) {
    return (
        <div>
            <p className="mb-1 text-sm font-medium">{label}</p>
            <div className="flex flex-wrap gap-2" role="radiogroup">
                {options.map((opcion) => (
                    <Button
                        key={opcion.value}
                        type="button"
                        size="sm"
                        role="radio"
                        aria-checked={value === opcion.value}
                        variant={value === opcion.value ? 'default' : 'outline'}
                        onClick={() => onChange(opcion.value)}
                    >
                        {opcion.label}
                    </Button>
                ))}
            </div>
        </div>
    );
}

/** Buscar la multa de destino. Viaja con una recarga parcial, como los créditos. */
function TargetPicker({
    multas,
    selected,
    onSelect,
    error,
}: {
    multas?: Destino[];
    selected: number | null;
    onSelect: (multa: Destino) => void;
    error?: string;
}) {
    const [termino, setTermino] = useState('');
    const [buscando, setBuscando] = useState(false);
    const [encontradas, setEncontradas] = useState(multas);

    if (multas !== undefined && multas !== encontradas) {
        setEncontradas(multas);
    }

    const buscar = () => {
        setBuscando(true);
        router.reload({
            only: ['multasDestino'],
            data: { multaDestino: termino },
            onFinish: () => setBuscando(false),
        });
    };

    return (
        <>
            <div>
                <Label htmlFor="multa-destino">Buscar la multa</Label>
                <div className="mt-1 flex gap-2">
                    <Input
                        id="multa-destino"
                        value={termino}
                        onChange={(e) => setTermino(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                buscar();
                            }
                        }}
                        placeholder="Expediente o carátula"
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

            {encontradas !== undefined && (
                <div className="max-h-48 overflow-y-auto rounded-md border">
                    {encontradas.length === 0 ? (
                        <p className="p-3 text-sm text-muted-foreground">
                            No hay otras multas con deuda que coincidan.
                        </p>
                    ) : (
                        <ul className="divide-y" role="radiogroup">
                            {encontradas.map((multa) => (
                                <li key={multa.id}>
                                    <button
                                        type="button"
                                        role="radio"
                                        aria-checked={selected === multa.id}
                                        onClick={() => onSelect(multa)}
                                        className={cn(
                                            'flex w-full flex-wrap items-baseline gap-x-3 gap-y-0.5 px-3 py-2 text-left text-sm hover:bg-muted/50',
                                            selected === multa.id &&
                                                'bg-primary/10 hover:bg-primary/10',
                                        )}
                                    >
                                        <span className="min-w-0 flex-1 font-medium">
                                            {multa.caption}
                                        </span>
                                        <span className="font-mono text-xs text-muted-foreground">
                                            {multa.caseLabel}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            Debe <Money value={multa.debt} />
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
            <FieldError message={error} />
        </>
    );
}
