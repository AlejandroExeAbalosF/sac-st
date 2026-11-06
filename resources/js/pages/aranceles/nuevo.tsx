import { Head, Link, useForm } from '@inertiajs/react';
import { Banknote, Landmark, Plus } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import DateInput from '@/components/date-input';
import DocumentInput from '@/components/document-input';
import FormRowField from '@/components/form-row-field';
import InputError from '@/components/input-error';
import PageHeader from '@/components/page-header';
import PaperPhotoDropzone from '@/components/paper-photo-dropzone';
import ReasonFields from '@/components/reason-fields';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import FeeLineEditor, {
    importeDeLinea,
} from '@/features/aranceles/components/fee-line-editor';
import type { LineaDeCobro } from '@/features/aranceles/components/fee-line-editor';
import SignerSelect, {
    SIN_FIRMANTE,
} from '@/features/aranceles/components/signer-select';
import type { Firmante } from '@/features/aranceles/components/signer-select';
import {
    conLineaNueva,
    itemsDeLineas,
    lineaVacia,
} from '@/features/aranceles/lib/fee-form';
import { consultaDeCobro } from '@/features/aranceles/lib/fee-query';
import DocumentFrame, {
    HOJA_ALTO_A5,
} from '@/features/haberes/components/document-frame';
import { ApiError, apiGet } from '@/lib/api';
import { businessToday, money, sumAmounts } from '@/lib/format';
import { index as apertura } from '@/routes/aranceles/caja/apertura';
import {
    corregir,
    index as cobros,
    preview,
    store,
} from '@/routes/aranceles/cobros';
import { store as guardarPendiente } from '@/routes/aranceles/pendientes';

type Tipo = App.Modules.Aranceles.Data.FeeTypeData;
type Cobro = App.Modules.Aranceles.Data.FeeChargeListItemData;
type Acto = App.Modules.Aranceles.Enums.FeeReasonAct;
type Codigo = App.Modules.Aranceles.Enums.FeeReasonCode;

const mediosDePago = [
    ['cash', 'Efectivo', Banknote],
    ['bank', 'Depósito o transferencia', Landmark],
] as const;

type Props = {
    feeTypes: Tipo[];
    /** El cobro que se corrige, si es una corrección. */
    corrects: Cobro | null;
    isOpen: boolean;
    canChangeAmount: boolean;
    firmantes: Firmante[];
    idempotencyKey: string;
};

const lineasDe = (cobro: Cobro): LineaDeCobro[] =>
    cobro.lines.map((l, i) => ({
        ...lineaVacia(i + 1),
        feeTypeId: l.feeTypeId,
        quantity: l.quantity === null ? '1' : String(l.quantity),
        baseAmount: l.baseAmount ?? '',
        amount: l.amount === l.calculatedAmount ? '' : l.amount,
        reasonCode: (l.reasonCode ?? '') as Codigo | '',
        reason: l.reason ?? '',
    }));

/**
 * Cobrar un arancel, registrar uno que se va a pagar por depósito, o corregir uno
 * ya cobrado.
 *
 * En efectivo va en dos pasos: el servidor comprueba los datos y muestra el recibo
 * como va a salir, y recién después se cobra. Por depósito queda pendiente, y el
 * recibo sale al identificar el crédito. El total y los importes de esta pantalla
 * son un anticipo: los calcula el servidor.
 */
export default function NuevoCobro({
    feeTypes,
    corrects,
    isOpen,
    canChangeAmount,
    firmantes,
    idempotencyKey,
}: Props) {
    const hoy = businessToday();
    const form = useForm({
        payerName: corrects?.payerName ?? '',
        payerDocument: corrects?.payerDocument ?? '',
        receivedOn: hoy,
        talonarioNumber: '',
        talonarioPhoto: null as File | null,
        signedById: SIN_FIRMANTE,
        reasonCode: '' as Codigo | '',
        reason: '',
        lineas: corrects === null ? [lineaVacia()] : lineasDe(corrects),
    });
    const [revision, setRevision] = useState<{
        url: string;
        numero: string;
        cargado: boolean;
    } | null>(null);
    const [revisando, setRevisando] = useState(false);
    const ocupado = revisando || form.processing;
    const formulario = useRef<HTMLFormElement>(null);
    useEffect(() => {
        if (!ocupado && Object.keys(form.errors).length > 0) {
            formulario.current
                ?.querySelector<HTMLElement>('[aria-invalid="true"]')
                ?.focus();
        }
    }, [form.errors, ocupado]);
    const [medio, setMedio] = useState<'cash' | 'bank'>('cash');
    const porDeposito = !corrects && medio === 'bank';

    const errores = form.errors as unknown as Record<
        string,
        string | undefined
    >;
    const atrasado = form.data.receivedOn < hoy;
    // La corrección conserva la tarifa del cobro original, como en el servidor.
    const fecha = corrects
        ? corrects.pricingDate
        : porDeposito
          ? hoy
          : form.data.receivedOn;
    const importes = form.data.lineas.map((l) =>
        importeDeLinea(l, feeTypes, fecha),
    );
    const total = sumAmounts(importes.filter((i) => i !== null));

    const cambiarLinea = (indice: number, cambios: Partial<LineaDeCobro>) =>
        form.setData(
            'lineas',
            form.data.lineas.map((l, i) =>
                i === indice ? { ...l, ...cambios } : l,
            ),
        );

    const items = () => itemsDeLineas(form.data.lineas);

    const registrarPendiente = () => {
        form.transform(() => ({
            payerName: form.data.payerName,
            payerDocument: form.data.payerDocument || null,
            items: items(),
            idempotencyKey,
        }));
        form.post(guardarPendiente().url);
    };

    const datos = () => ({
        payerName: form.data.payerName,
        payerDocument: form.data.payerDocument || null,
        receivedOn: form.data.receivedOn === hoy ? null : form.data.receivedOn,
        talonarioNumber: form.data.talonarioNumber.trim() || null,
        signedById:
            form.data.signedById === SIN_FIRMANTE
                ? null
                : Number(form.data.signedById),
        items: items(),
    });

    const enviarCorreccion = (id: number) => {
        form.transform(() => ({
            payerName: form.data.payerName,
            payerDocument: form.data.payerDocument || null,
            signedById:
                form.data.signedById === SIN_FIRMANTE
                    ? null
                    : Number(form.data.signedById),
            items: items(),
            reasonCode: form.data.reasonCode,
            reason: form.data.reason,
        }));
        form.post(corregir(id).url);
    };

    // Antes de cambiar de paso, el servidor comprueba con las reglas del cobro.
    const revisar = () => {
        const url = `${preview().url}?${consultaDeCobro(datos())}`;

        form.clearErrors();
        setRevisando(true);
        apiGet<{ numero: string }>(url)
            .then(({ numero }) => setRevision({ url, numero, cargado: false }))
            .catch((error: unknown) => {
                if (error instanceof ApiError && error.hasFieldErrors) {
                    form.setError(error.fieldErrors as never);

                    return;
                }

                form.setError(
                    'payerName',
                    error instanceof Error
                        ? error.message
                        : 'No pudimos armar el recibo.',
                );
            })
            .finally(() => setRevisando(false));
    };

    const cobrar = () => {
        form.transform(() => ({
            ...datos(),
            idempotencyKey,
            talonarioPhoto: form.data.talonarioPhoto,
        }));
        form.post(store().url, { onError: () => setRevision(null) });
    };

    return (
        <>
            <Head title={corrects ? 'Corregir cobro' : 'Nuevo cobro'} />

            <div className="mx-auto flex w-full max-w-4xl flex-col gap-5 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Aranceles"
                    title={
                        corrects
                            ? `Corregir el cobro de ${corrects.payerName}`
                            : 'Nuevo cobro'
                    }
                    description={
                        corrects
                            ? corrects.medium === 'bank'
                                ? 'El cobro anterior queda anulado y a la vista, y nace otro con los mismos créditos. Lo que sobre queda libre; si no alcanzan, queda pendiente de lo que falta.'
                                : 'El cobro anterior queda anulado y a la vista, y nace otro. Si el importe es el mismo, el dinero no se mueve.'
                            : porDeposito
                              ? 'Queda pendiente, con el precio de hoy. Al registrarlo se carga el comprobante, y el recibo sale cuando se identifique el depósito en el extracto.'
                              : 'Se cobra y se emite el recibo en el mismo acto.'
                    }
                />

                {!isOpen && (
                    <p className="rounded-lg border bg-card p-4 text-sm">
                        La caja de Aranceles todavía no se abrió.{' '}
                        <Link
                            href={apertura()}
                            className="text-primary hover:underline"
                        >
                            Abrirla
                        </Link>
                    </p>
                )}

                <form
                    ref={formulario}
                    className="standard-form min-w-0 rounded-xl border bg-card shadow-xs"
                    aria-busy={ocupado}
                    onSubmit={(e) => {
                        e.preventDefault();

                        if (ocupado) {
                            return;
                        }

                        if (corrects) {
                            enviarCorreccion(corrects.id);
                        } else if (porDeposito) {
                            registrarPendiente();
                        } else {
                            revisar();
                        }
                    }}
                >
                    <fieldset disabled={ocupado} className="min-w-0 divide-y">
                        <div className="grid gap-5 px-4 py-5 sm:px-6">
                            {!corrects && (
                                <fieldset className="min-w-0">
                                    <legend className="mb-2 text-sm font-semibold">
                                        Medio de pago
                                    </legend>
                                    <div className="grid gap-2 sm:flex sm:flex-wrap">
                                        {mediosDePago.map(
                                            ([valor, texto, Icono]) => (
                                                <label
                                                    key={valor}
                                                    className="flex min-h-11 cursor-pointer items-center gap-2 rounded-md border border-input bg-background px-3 text-sm font-medium has-checked:border-primary has-checked:bg-primary/10 has-checked:text-primary has-focus-visible:ring-2 has-focus-visible:ring-ring has-focus-visible:ring-offset-2 motion-safe:transition-colors"
                                                >
                                                    <input
                                                        type="radio"
                                                        name="medio"
                                                        className="size-3.5 accent-primary"
                                                        checked={
                                                            medio === valor
                                                        }
                                                        onChange={() => {
                                                            form.clearErrors();
                                                            setMedio(valor);
                                                        }}
                                                    />
                                                    <Icono
                                                        className="size-4"
                                                        aria-hidden="true"
                                                    />
                                                    {texto}
                                                </label>
                                            ),
                                        )}
                                    </div>
                                </fieldset>
                            )}

                            <div className="grid gap-4 sm:grid-cols-[2fr_1fr]">
                                <FormRowField
                                    htmlFor="payerName"
                                    label="Recibí de"
                                >
                                    <Input
                                        id="payerName"
                                        aria-invalid={Boolean(
                                            form.errors.payerName,
                                        )}
                                        aria-describedby={
                                            form.errors.payerName
                                                ? 'payerName-error'
                                                : undefined
                                        }
                                        maxLength={200}
                                        value={form.data.payerName}
                                        onChange={(e) =>
                                            form.setData(
                                                'payerName',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        id="payerName-error"
                                        message={form.errors.payerName}
                                    />
                                </FormRowField>
                                <FormRowField
                                    htmlFor="payerDocument"
                                    label="CUIT o DNI (opcional)"
                                >
                                    <DocumentInput
                                        id="payerDocument"
                                        aria-invalid={Boolean(
                                            form.errors.payerDocument,
                                        )}
                                        aria-describedby={
                                            form.errors.payerDocument
                                                ? 'payerDocument-error'
                                                : undefined
                                        }
                                        maxDigits={11}
                                        value={form.data.payerDocument}
                                        onChange={(valor) =>
                                            form.setData('payerDocument', valor)
                                        }
                                    />
                                    <InputError
                                        id="payerDocument-error"
                                        message={form.errors.payerDocument}
                                    />
                                </FormRowField>
                            </div>
                        </div>
                        <section className="grid min-w-0 gap-3 px-4 py-5 sm:px-6">
                            <h2 className="text-base font-semibold">
                                Aranceles a cobrar
                            </h2>
                            {form.data.lineas.map((linea, indice) => (
                                <FeeLineEditor
                                    key={linea.key}
                                    index={indice}
                                    linea={linea}
                                    types={feeTypes}
                                    fecha={fecha}
                                    canChangeAmount={canChangeAmount}
                                    removable={form.data.lineas.length > 1}
                                    errors={errores}
                                    onChange={(cambios) =>
                                        cambiarLinea(indice, cambios)
                                    }
                                    onRemove={() =>
                                        form.setData(
                                            'lineas',
                                            form.data.lineas.filter(
                                                (_, i) => i !== indice,
                                            ),
                                        )
                                    }
                                />
                            ))}
                            <InputError message={errores['items']} />
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() =>
                                        form.setData(
                                            'lineas',
                                            conLineaNueva(form.data.lineas),
                                        )
                                    }
                                >
                                    <Plus
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    Agregar otro arancel
                                </Button>
                            </div>
                        </section>

                        {!porDeposito && (
                            <section className="grid gap-4 px-4 py-5 sm:px-6">
                                <h2 className="text-base font-semibold">
                                    {corrects
                                        ? 'Datos de la corrección'
                                        : 'Datos del recibo'}
                                </h2>
                                {!corrects && (
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <FormRowField
                                            htmlFor="receivedOn"
                                            label="Fecha del cobro"
                                        >
                                            <DateInput
                                                id="receivedOn"
                                                aria-invalid={Boolean(
                                                    form.errors.receivedOn,
                                                )}
                                                max={hoy}
                                                value={form.data.receivedOn}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'receivedOn',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                            <InputError
                                                message={form.errors.receivedOn}
                                            />
                                        </FormRowField>
                                        <FormRowField
                                            htmlFor="talonarioNumber"
                                            label={
                                                atrasado
                                                    ? 'N.º de talonario'
                                                    : 'N.º de talonario (opcional)'
                                            }
                                        >
                                            <Input
                                                id="talonarioNumber"
                                                aria-invalid={Boolean(
                                                    form.errors.talonarioNumber,
                                                )}
                                                maxLength={20}
                                                value={
                                                    form.data.talonarioNumber
                                                }
                                                onChange={(e) => {
                                                    form.setData(
                                                        'talonarioNumber',
                                                        e.target.value,
                                                    );

                                                    if (
                                                        e.target.value.trim() ===
                                                        ''
                                                    ) {
                                                        form.setData(
                                                            'talonarioPhoto',
                                                            null,
                                                        );
                                                    }
                                                }}
                                            />
                                            {atrasado && (
                                                <p className="text-xs text-muted-foreground">
                                                    Un cobro de un día anterior
                                                    se carga con la hoja que se
                                                    entregó ese día.
                                                </p>
                                            )}
                                            <InputError
                                                message={
                                                    form.errors.talonarioNumber
                                                }
                                            />
                                        </FormRowField>
                                    </div>
                                )}

                                {!corrects &&
                                    form.data.talonarioNumber.trim() !== '' && (
                                        <PaperPhotoDropzone
                                            id="foto-talonario"
                                            foto={form.data.talonarioPhoto}
                                            onElegir={(archivo) =>
                                                form.setData(
                                                    'talonarioPhoto',
                                                    archivo,
                                                )
                                            }
                                            error={form.errors.talonarioPhoto}
                                            label="Foto del talonario"
                                        />
                                    )}

                                <SignerSelect
                                    id="signedById"
                                    firmantes={firmantes}
                                    value={form.data.signedById}
                                    error={form.errors.signedById}
                                    onChange={(valor) =>
                                        form.setData('signedById', valor)
                                    }
                                />

                                {corrects && (
                                    <ReasonFields<Acto, Codigo>
                                        act="charge_correction"
                                        idPrefix="correccion"
                                        code={form.data.reasonCode}
                                        detail={form.data.reason}
                                        maxLength={300}
                                        onCodeChange={(c) =>
                                            form.setData('reasonCode', c)
                                        }
                                        onDetailChange={(d) =>
                                            form.setData('reason', d)
                                        }
                                        codeError={errores['reasonCode']}
                                        detailError={errores['reason']}
                                    />
                                )}
                            </section>
                        )}
                    </fieldset>
                    <div className="flex flex-wrap items-center justify-between gap-x-6 gap-y-4 rounded-b-xl border-t bg-primary/5 px-4 py-4 sm:px-6">
                        <div>
                            <p className="text-sm font-medium text-muted-foreground">
                                Total
                            </p>
                            <p className="text-2xl font-semibold tracking-tight text-primary tabular-nums">
                                {money(total)}
                            </p>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <Button type="button" variant="ghost" asChild>
                                <Link href={cobros()}>Cancelar</Link>
                            </Button>
                            <Button
                                type="submit"
                                disabled={ocupado || (!isOpen && !corrects)}
                            >
                                {ocupado && (
                                    <Spinner
                                        aria-hidden="true"
                                        className="motion-reduce:animate-none"
                                    />
                                )}
                                {form.processing
                                    ? 'Guardando…'
                                    : corrects
                                      ? 'Corregir y emitir recibo'
                                      : porDeposito
                                        ? 'Registrar pendiente'
                                        : revisando
                                          ? 'Comprobando…'
                                          : 'Revisar recibo'}
                            </Button>
                        </div>
                    </div>
                </form>
            </div>

            {revision && (
                <Dialog
                    open
                    onOpenChange={(abierto) =>
                        !abierto && !form.processing && setRevision(null)
                    }
                >
                    <DialogContent className="sm:max-w-5xl">
                        <DialogHeader>
                            <DialogTitle>Revisar el recibo</DialogTitle>
                            <DialogDescription>
                                Así va a salir. Se emite con el número{' '}
                                <span className="font-mono">
                                    {revision.numero}
                                </span>{' '}
                                si no se emite otro antes.
                            </DialogDescription>
                        </DialogHeader>
                        <DocumentFrame
                            mostrarCarga
                            onCargado={() =>
                                setRevision(
                                    (actual) =>
                                        actual && { ...actual, cargado: true },
                                )
                            }
                            url={revision.url}
                            titulo={`Recibo ${revision.numero}, sin emitir`}
                            hojaAlto={HOJA_ALTO_A5}
                            className="w-full"
                            maxAlto="60vh"
                        />
                        <DialogFooter>
                            <Button
                                variant="outline"
                                className="sm:mr-auto"
                                onClick={() => setRevision(null)}
                                disabled={form.processing}
                            >
                                Volver
                            </Button>
                            <Button
                                onClick={cobrar}
                                disabled={form.processing || !revision.cargado}
                            >
                                {form.processing && (
                                    <Spinner
                                        aria-hidden="true"
                                        className="motion-reduce:animate-none"
                                    />
                                )}
                                {form.processing
                                    ? 'Emitiendo recibo…'
                                    : 'Cobrar y emitir recibo'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            )}
        </>
    );
}
