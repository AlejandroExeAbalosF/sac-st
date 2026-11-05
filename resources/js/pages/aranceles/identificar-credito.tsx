import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Plus } from 'lucide-react';
import { useState } from 'react';
import DocumentInput from '@/components/document-input';
import InputError from '@/components/input-error';
import ListViewTab from '@/components/list-view-tab';
import PageHeader from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import FeeCreditPicker from '@/features/aranceles/components/fee-credit-picker';
import type { Credito } from '@/features/aranceles/components/fee-credit-picker';
import FeeLineEditor, {
    importeDeLinea,
} from '@/features/aranceles/components/fee-line-editor';
import SignerSelect, {
    SIN_FIRMANTE,
} from '@/features/aranceles/components/signer-select';
import type { Firmante } from '@/features/aranceles/components/signer-select';
import { itemsDeLineas, lineaVacia } from '@/features/aranceles/lib/fee-form';
import { compareAmounts, date, money, sumAmounts } from '@/lib/format';
import { registrar } from '@/routes/aranceles/creditos';
import { identificar } from '@/routes/aranceles/pendientes';

type Cobro = App.Modules.Aranceles.Data.FeeChargeListItemData;
type Tipo = App.Modules.Aranceles.Data.FeeTypeData;
type Camino = 'pendiente' | 'nuevo';

const firmanteDe = (valor: string) =>
    valor === SIN_FIRMANTE ? null : Number(valor);

/**
 * Un crédito del extracto identificado como arancel, desde Banco › Movimientos.
 *
 * Puede pagar un cobro registrado en ventanilla o llegar sin ese paso, y entonces
 * el cobro nace acá. La cuenta es compartida: lo que no sea de Aranceles queda sin
 * identificar. De un crédito mayor se toma solo lo necesario.
 */
export default function IdentificarCredito({
    credit,
    charges,
    feeTypes,
    canChangeAmount,
    firmantes,
    backHref,
}: {
    credit: Credito;
    charges: Cobro[];
    feeTypes: Tipo[];
    canChangeAmount: boolean;
    firmantes: Firmante[];
    backHref: string;
}) {
    const [camino, setCamino] = useState<Camino>(
        charges.length > 0 ? 'pendiente' : 'nuevo',
    );
    const [firmante, setFirmante] = useState(SIN_FIRMANTE);

    return (
        <>
            <Head title="Identificar como arancel" />

            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Banco · identificar como arancel"
                    title={`Crédito del ${date(credit.date)}`}
                    description={[
                        credit.accountLabel,
                        credit.counterparty,
                        credit.operationId && `operación ${credit.operationId}`,
                        credit.description,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                    actions={
                        <Button variant="outline" asChild>
                            <Link href={backHref}>
                                <ArrowLeft
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                Movimientos
                            </Link>
                        </Button>
                    }
                />

                <p className="text-2xl font-semibold tabular-nums">
                    {money(credit.free)}
                    {credit.free !== credit.amount && (
                        <span className="ml-2 text-sm font-normal text-muted-foreground">
                            libres de {money(credit.amount)}
                        </span>
                    )}
                </p>

                <nav
                    aria-label="Qué es el crédito"
                    className="-mb-2 flex overflow-x-auto overflow-y-hidden border-b"
                >
                    <ListViewTab
                        active={camino === 'pendiente'}
                        onClick={() => setCamino('pendiente')}
                    >
                        Un cobro pendiente
                        <span className="ml-1.5 text-xs text-muted-foreground tabular-nums">
                            {charges.length}
                        </span>
                    </ListViewTab>
                    <ListViewTab
                        active={camino === 'nuevo'}
                        onClick={() => setCamino('nuevo')}
                    >
                        Sin paso por ventanilla
                    </ListViewTab>
                </nav>

                <section className="rounded-lg border bg-card p-5 shadow-raised">
                    {camino === 'pendiente' ? (
                        <CobrosPendientes
                            credito={credit}
                            cobros={charges}
                            firmante={firmante}
                            onFirmante={setFirmante}
                            firmantes={firmantes}
                        />
                    ) : (
                        <CobroNuevo
                            credito={credit}
                            tipos={feeTypes}
                            canChangeAmount={canChangeAmount}
                            firmante={firmante}
                            onFirmante={setFirmante}
                            firmantes={firmantes}
                        />
                    )}
                </section>
            </div>
        </>
    );
}

function CobrosPendientes({
    credito,
    cobros,
    firmante,
    onFirmante,
    firmantes,
}: {
    credito: Credito;
    cobros: Cobro[];
    firmante: string;
    onFirmante: (valor: string) => void;
    firmantes: Firmante[];
}) {
    const [clave] = useState(
        () => `arancel-credito:${credito.id}:${crypto.randomUUID()}`,
    );
    const { post, transform, processing, errors } = useForm({});
    const errores = errors as Record<string, string | undefined>;

    if (cobros.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                No hay cobros pendientes. Si el pago llegó sin paso por
                ventanilla, registralo en la otra pestaña.
            </p>
        );
    }

    const aplicar = (cobro: Cobro) => {
        transform(() => ({
            credits: [credito.id],
            signedById: firmanteDe(firmante),
            idempotencyKey: clave,
        }));
        post(identificar(cobro.id).url);
    };

    // Primero lo que el crédito cubre justo: es lo más probable.
    const ordenados = [...cobros].sort(
        (a, b) =>
            Number(compareAmounts(b.missing, credito.free) === 0) -
            Number(compareAmounts(a.missing, credito.free) === 0),
    );

    return (
        <div className="grid gap-3">
            <InputError message={errores['credits']} />
            <ul className="divide-y rounded-md border">
                {ordenados.map((cobro) => {
                    const alcanza =
                        compareAmounts(credito.free, cobro.missing) >= 0;

                    return (
                        <li
                            key={cobro.id}
                            className="flex flex-wrap items-center justify-between gap-3 p-3 text-sm"
                        >
                            <span className="min-w-0">
                                <span className="font-medium">
                                    {cobro.payerName}
                                </span>
                                <span className="block text-xs text-muted-foreground">
                                    Faltan {money(cobro.missing)} de{' '}
                                    {money(cobro.total)} ·{' '}
                                    {date(cobro.requestedOn)}
                                </span>
                            </span>
                            <Button
                                size="sm"
                                disabled={processing}
                                onClick={() => aplicar(cobro)}
                            >
                                {alcanza
                                    ? 'Aplicar y emitir recibo'
                                    : 'Registrar ingreso parcial'}
                            </Button>
                        </li>
                    );
                })}
            </ul>
            <SignerSelect
                id="credito-firma"
                firmantes={firmantes}
                value={firmante}
                onChange={onFirmante}
            />
        </div>
    );
}

function CobroNuevo({
    credito,
    tipos,
    canChangeAmount,
    firmante,
    onFirmante,
    firmantes,
}: {
    credito: Credito;
    tipos: Tipo[];
    canChangeAmount: boolean;
    firmante: string;
    onFirmante: (valor: string) => void;
    firmantes: Firmante[];
}) {
    const [clave] = useState(
        () => `arancel-sin-ventanilla:${crypto.randomUUID()}`,
    );
    const [otros, setOtros] = useState<Credito[]>([]);
    const form = useForm({
        payerName: credito.counterparty ?? '',
        payerCuit: '',
        lineas: [lineaVacia()],
    });
    const errores = form.errors as unknown as Record<
        string,
        string | undefined
    >;
    // La tarifa es la del más antiguo de los créditos: así lo calcula el servidor.
    const fecha = [credito, ...otros]
        .map((c) => c.date)
        .reduce((a, b) => (a < b ? a : b));
    const importes = form.data.lineas.map((l) =>
        importeDeLinea(l, tipos, fecha),
    );
    const total = sumAmounts(importes.filter((i) => i !== null));
    const disponible = sumAmounts([credito, ...otros].map((c) => c.free));

    const enviar = (e: React.FormEvent) => {
        e.preventDefault();
        router.post(
            registrar().url,
            {
                payerName: form.data.payerName,
                payerCuit: form.data.payerCuit || null,
                items: itemsDeLineas(form.data.lineas),
                credits: [credito.id, ...otros.map((c) => c.id)],
                signedById: firmanteDe(firmante),
                idempotencyKey: clave,
            },
            { onError: (errs) => form.setError(errs as never) },
        );
    };

    return (
        <form onSubmit={enviar} className="flex flex-col gap-5">
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
                        onChange={(valor) => form.setData('payerCuit', valor)}
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
                        types={tipos}
                        fecha={fecha}
                        canChangeAmount={canChangeAmount}
                        removable={form.data.lineas.length > 1}
                        errors={errores}
                        onChange={(cambios) =>
                            form.setData(
                                'lineas',
                                form.data.lineas.map((l, i) =>
                                    i === indice ? { ...l, ...cambios } : l,
                                ),
                            )
                        }
                        onRemove={() =>
                            form.setData(
                                'lineas',
                                form.data.lineas.filter((_, i) => i !== indice),
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
                <p className="text-xs text-muted-foreground">
                    Con el precio del {date(fecha)}, la fecha del crédito más
                    antiguo.
                </p>
            </div>

            <div className="grid gap-2">
                <p className="text-sm font-medium">
                    ¿Se pagó con más de un crédito?
                </p>
                <FeeCreditPicker
                    seleccionados={otros}
                    onChange={setOtros}
                    excluir={[credito.id]}
                />
                <InputError message={errores['credits']} />
            </div>

            <p className="text-sm">
                {compareAmounts(disponible, total) >= 0
                    ? 'Los créditos cubren el total: se emite el recibo, y lo que sobre queda libre.'
                    : `Los créditos suman ${money(disponible)}: el cobro queda pendiente de lo que falta.`}
            </p>

            <SignerSelect
                id="nuevo-firma"
                firmantes={firmantes}
                value={firmante}
                onChange={onFirmante}
            />

            <div className="flex justify-end">
                <Button type="submit" disabled={form.processing}>
                    Registrar el pago
                </Button>
            </div>
        </form>
    );
}
