import { useForm } from '@inertiajs/react';
import { useState } from 'react';
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
import ReceiptReview from '@/features/multas/components/receipt-review';
import type { Revision } from '@/features/multas/components/receipt-review';
import { ApiError, apiGet } from '@/lib/api';
import { businessToday, money, parseAmount } from '@/lib/format';
import { preview, store } from '@/routes/multas/cobros';

export type Firmante = { id: number; name: string; position: string | null };

const SIN_FIRMANTE = 'ninguno';

/**
 * El cobro en la caja: efectivo o cheque, con su recibo en el acto (M02).
 *
 * El importe arranca vacío para que se cargue lo que se recibe y no se confirme
 * el saldo por inercia; el saldo se ofrece debajo para copiarlo con un clic. Un
 * pago parcial deja saldo, y uno que lo supera deja excedente, que no se
 * devuelve solo (M05). El cheque entra por su importe entero.
 *
 * La fecha cambia solo para cargar un cobro de un día anterior, y entonces
 * pide el número del recibo de talonario que se entregó ese día.
 *
 * Se cobra en dos pasos: el formulario y después el recibo como va a salir.
 * Un recibo emitido no se borra, solo se anula, así que mirarlo antes es
 * la confirmación del cobro.
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
    const {
        data,
        setData,
        post,
        transform,
        processing,
        errors,
        setError,
        clearErrors,
    } = useForm({
        medium: 'cash' as 'cash' | 'cheque',
        amount: '',
        receivedOn: hoy,
        talonarioNumber: '',
        signedById: SIN_FIRMANTE,
        judicial: false,
        judicialReference: '',
        cheque: { number: '', bank: '', issueDate: '' },
    });

    const [revision, setRevision] = useState<Revision | null>(null);
    const [revisando, setRevisando] = useState(false);

    const errores = errors as Record<string, string | undefined>;
    const conCheque = data.medium === 'cheque';
    const atrasado = data.receivedOn !== '' && data.receivedOn < hoy;

    // Lo mismo viaja a la vista previa y al cobro: lo que se mira es lo que se emite.
    const datosDelCobro = (datos: typeof data) => ({
        medium: datos.medium,
        amount: parseAmount(datos.amount),
        receivedOn: datos.receivedOn === hoy ? null : datos.receivedOn,
        talonarioNumber: datos.talonarioNumber.trim() || null,
        signedById:
            datos.signedById === SIN_FIRMANTE ? null : Number(datos.signedById),
        judicialReference:
            datos.medium === 'cheque' && datos.judicial
                ? datos.judicialReference.trim()
                : null,
        cheque:
            datos.medium === 'cheque'
                ? {
                      number: datos.cheque.number.trim(),
                      bank: datos.cheque.bank.trim() || null,
                      issueDate: datos.cheque.issueDate || null,
                  }
                : null,
        idempotencyKey,
    });

    /*
     * Antes de cambiar de paso, el servidor comprueba los datos con las
     * mismas reglas que el cobro: un error se marca en su campo y no
     * aparece después, con el recibo ya a la vista.
     */
    const revisar = () => {
        const url = `${preview(fineId).url}?${consulta(datosDelCobro(data))}`;

        clearErrors();
        setRevisando(true);
        apiGet<{ numero: string }>(url)
            .then(({ numero }) => setRevision({ url, numero }))
            .catch((error: unknown) => {
                if (error instanceof ApiError && error.hasFieldErrors) {
                    setError(
                        error.fieldErrors as Partial<
                            Record<keyof typeof data, string>
                        >,
                    );
                } else {
                    setError(
                        'amount',
                        error instanceof ApiError
                            ? error.message
                            : 'No se pudo armar el recibo. Probá de nuevo.',
                    );
                }
            })
            .finally(() => setRevisando(false));
    };

    const cobrar = () => {
        transform(datosDelCobro);
        post(store(fineId).url, {
            preserveScroll: true,
            onSuccess: onClose,
            // Lo que el cobro rechace se corrige en el formulario.
            onError: () => setRevision(null),
        });
    };

    if (revision !== null) {
        return (
            <ReceiptReview
                revision={revision}
                processing={processing}
                onBack={() => setRevision(null)}
                onConfirm={cobrar}
                onClose={onClose}
            />
        );
    }

    return (
        <FormDialog
            title="Cobrar en caja"
            description={
                <>
                    Recibí de <strong>{caption}</strong>. Antes de cobrar se
                    muestra el recibo, para revisarlo como va a salir.
                </>
            }
            onClose={onClose}
            onSubmit={revisar}
            processing={revisando}
            processingLabel="Armando el recibo…"
            submitLabel="Revisar recibo"
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
                    <button
                        type="button"
                        className="mt-1 text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                        onClick={() =>
                            setData('amount', money(debt, { symbol: false }))
                        }
                    >
                        Saldo:{' '}
                        <span className="tabular-nums">{money(debt)}</span>
                    </button>
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

/** Los datos del cobro como parámetros de la vista previa. */
function consulta(datos: Record<string, unknown>): string {
    const parametros = new URLSearchParams();

    const agregar = (clave: string, valor: unknown) => {
        if (valor === null || valor === undefined) {
            return;
        }

        if (typeof valor === 'object') {
            Object.entries(valor).forEach(([k, v]) =>
                agregar(`${clave}[${k}]`, v),
            );

            return;
        }

        parametros.set(clave, String(valor));
    };

    Object.entries(datos).forEach(([clave, valor]) => agregar(clave, valor));

    return parametros.toString();
}
