import { Head, Link, router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import DateInput from '@/components/date-input';
import DocumentInput from '@/components/document-input';
import InputError from '@/components/input-error';
import PageHeader from '@/components/page-header';
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
import { Label } from '@/components/ui/label';
import FeeLineEditor, {
    importeDeLinea,
} from '@/features/aranceles/components/fee-line-editor';
import type { LineaDeCobro } from '@/features/aranceles/components/fee-line-editor';
import SignerSelect, {
    SIN_FIRMANTE,
} from '@/features/aranceles/components/signer-select';
import type { Firmante } from '@/features/aranceles/components/signer-select';
import { itemsDeLineas, lineaVacia } from '@/features/aranceles/lib/fee-form';
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
    cobro.lines.map((l) => ({
        ...lineaVacia(),
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
        payerCuit: corrects?.payerCuit ?? '',
        receivedOn: hoy,
        talonarioNumber: '',
        signedById: SIN_FIRMANTE,
        reasonCode: '' as Codigo | '',
        reason: '',
        lineas: corrects === null ? [lineaVacia()] : lineasDe(corrects),
    });
    const [revision, setRevision] = useState<{
        url: string;
        numero: string;
    } | null>(null);
    const [revisando, setRevisando] = useState(false);
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

    const registrarPendiente = () =>
        router.post(
            guardarPendiente().url,
            {
                payerName: form.data.payerName,
                payerCuit: form.data.payerCuit || null,
                items: items(),
                idempotencyKey,
            },
            { onError: (e) => form.setError(e as never) },
        );

    const datos = () => ({
        payerName: form.data.payerName,
        payerCuit: form.data.payerCuit || null,
        receivedOn: form.data.receivedOn === hoy ? null : form.data.receivedOn,
        talonarioNumber: form.data.talonarioNumber.trim() || null,
        signedById:
            form.data.signedById === SIN_FIRMANTE
                ? null
                : Number(form.data.signedById),
        items: items(),
    });

    const enviarCorreccion = () =>
        corrects &&
        router.post(
            corregir(corrects.id).url,
            {
                payerName: form.data.payerName,
                payerCuit: form.data.payerCuit || null,
                signedById:
                    form.data.signedById === SIN_FIRMANTE
                        ? null
                        : Number(form.data.signedById),
                items: items(),
                reasonCode: form.data.reasonCode,
                reason: form.data.reason,
            },
            {
                onError: (e) => form.setError(e as never),
            },
        );

    // Antes de cambiar de paso, el servidor comprueba con las reglas del cobro.
    const revisar = () => {
        const url = `${preview().url}?${consultaDeCobro(datos())}`;

        form.clearErrors();
        setRevisando(true);
        apiGet<{ numero: string }>(url)
            .then(({ numero }) => setRevision({ url, numero }))
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

    const cobrar = () =>
        router.post(
            store().url,
            { ...datos(), idempotencyKey },
            {
                onError: (e) => {
                    form.setError(e as never);
                    setRevision(null);
                },
            },
        );

    return (
        <>
            <Head title={corrects ? 'Corregir cobro' : 'Nuevo cobro'} />

            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 sm:p-6">
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
                              ? 'Queda pendiente, con el precio de hoy. El recibo sale cuando se identifique el depósito en el extracto.'
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
                    className="flex flex-col gap-5"
                    onSubmit={(e) => {
                        e.preventDefault();

                        if (corrects) {
                            enviarCorreccion();
                        } else if (porDeposito) {
                            registrarPendiente();
                        } else {
                            revisar();
                        }
                    }}
                >
                    {!corrects && (
                        <fieldset className="grid gap-1.5">
                            <legend className="text-sm font-medium">
                                Cómo paga
                            </legend>
                            <div className="flex flex-wrap gap-4 text-sm">
                                <label className="flex items-center gap-2">
                                    <input
                                        type="radio"
                                        name="medio"
                                        checked={medio === 'cash'}
                                        onChange={() => setMedio('cash')}
                                    />
                                    Efectivo
                                </label>
                                <label className="flex items-center gap-2">
                                    <input
                                        type="radio"
                                        name="medio"
                                        checked={medio === 'bank'}
                                        onChange={() => setMedio('bank')}
                                    />
                                    Depósito o transferencia
                                </label>
                            </div>
                        </fieldset>
                    )}

                    <div className="grid gap-4 sm:grid-cols-[2fr_1fr]">
                        <div className="grid gap-1.5">
                            <Label htmlFor="payerName">Recibí de</Label>
                            <Input
                                id="payerName"
                                maxLength={200}
                                value={form.data.payerName}
                                onChange={(e) =>
                                    form.setData('payerName', e.target.value)
                                }
                            />
                            <InputError message={form.errors.payerName} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="payerCuit">CUIT (opcional)</Label>
                            <DocumentInput
                                id="payerCuit"
                                maxDigits={11}
                                value={form.data.payerCuit}
                                onChange={(valor) =>
                                    form.setData('payerCuit', valor)
                                }
                            />
                            <InputError message={form.errors.payerCuit} />
                        </div>
                    </div>

                    <div className="flex flex-col gap-3">
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
                                    form.setData('lineas', [
                                        ...form.data.lineas,
                                        lineaVacia(),
                                    ])
                                }
                            >
                                <Plus className="size-4" aria-hidden="true" />
                                Agregar otro arancel
                            </Button>
                            <p className="text-lg font-semibold tabular-nums">
                                Total: {money(total)}
                            </p>
                        </div>
                    </div>

                    {!corrects && !porDeposito && (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-1.5">
                                <Label htmlFor="receivedOn">
                                    Fecha del cobro
                                </Label>
                                <DateInput
                                    id="receivedOn"
                                    max={hoy}
                                    value={form.data.receivedOn}
                                    onChange={(e) =>
                                        form.setData(
                                            'receivedOn',
                                            e.target.value,
                                        )
                                    }
                                />
                                <InputError message={form.errors.receivedOn} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="talonarioNumber">
                                    N.º de talonario
                                    {!atrasado && ' (opcional)'}
                                </Label>
                                <Input
                                    id="talonarioNumber"
                                    maxLength={20}
                                    value={form.data.talonarioNumber}
                                    onChange={(e) =>
                                        form.setData(
                                            'talonarioNumber',
                                            e.target.value,
                                        )
                                    }
                                />
                                {atrasado && (
                                    <p className="text-xs text-muted-foreground">
                                        Un cobro de un día anterior se carga con
                                        la hoja que se entregó ese día.
                                    </p>
                                )}
                                <InputError
                                    message={form.errors.talonarioNumber}
                                />
                            </div>
                        </div>
                    )}

                    {!porDeposito && (
                        <SignerSelect
                            id="signedById"
                            firmantes={firmantes}
                            value={form.data.signedById}
                            onChange={(valor) =>
                                form.setData('signedById', valor)
                            }
                        />
                    )}

                    {corrects && (
                        <ReasonFields<Acto, Codigo>
                            act="charge_correction"
                            idPrefix="correccion"
                            code={form.data.reasonCode}
                            detail={form.data.reason}
                            maxLength={300}
                            onCodeChange={(c) => form.setData('reasonCode', c)}
                            onDetailChange={(d) => form.setData('reason', d)}
                            codeError={errores['reasonCode']}
                            detailError={errores['reason']}
                        />
                    )}

                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="ghost" asChild>
                            <Link href={cobros()}>Cancelar</Link>
                        </Button>
                        <Button
                            type="submit"
                            disabled={revisando || (!isOpen && !corrects)}
                        >
                            {corrects
                                ? 'Corregir y emitir recibo'
                                : porDeposito
                                  ? 'Registrar pendiente'
                                  : revisando
                                    ? 'Comprobando…'
                                    : 'Revisar recibo'}
                        </Button>
                    </div>
                </form>
            </div>

            {revision && (
                <Dialog
                    open
                    onOpenChange={(abierto) => !abierto && setRevision(null)}
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
                            >
                                Volver
                            </Button>
                            <Button onClick={cobrar}>
                                Cobrar y emitir recibo
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            )}
        </>
    );
}
