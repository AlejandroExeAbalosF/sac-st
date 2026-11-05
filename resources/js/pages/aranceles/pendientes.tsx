import { Head, Link, useForm } from '@inertiajs/react';
import { Plus, ReceiptText } from 'lucide-react';
import { useState } from 'react';
import AmountInput from '@/components/amount-input';
import InputError from '@/components/input-error';
import PageHeader from '@/components/page-header';
import PaginationFooter from '@/components/pagination-footer';
import type { PaginationData } from '@/components/pagination-footer';
import ReasonFields from '@/components/reason-fields';
import StatusBadge from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import FeeCreditPicker from '@/features/aranceles/components/fee-credit-picker';
import type { Credito } from '@/features/aranceles/components/fee-credit-picker';
import SignerSelect, {
    SIN_FIRMANTE,
} from '@/features/aranceles/components/signer-select';
import type { Firmante } from '@/features/aranceles/components/signer-select';
import { compareAmounts, date, money, sumAmounts } from '@/lib/format';
import { anular, create } from '@/routes/aranceles/cobros';
import { ajustar, identificar } from '@/routes/aranceles/pendientes';

type Cobro = App.Modules.Aranceles.Data.FeeChargeListItemData;
type Acto = App.Modules.Aranceles.Enums.FeeReasonAct;
type Codigo = App.Modules.Aranceles.Enums.FeeReasonCode;

type Props = {
    charges: PaginationData & { data: Cobro[] };
    firmantes: Firmante[];
    can: {
        create: boolean;
        identify: boolean;
        adjust: boolean;
        void: boolean;
    };
};

const firmanteDe = (valor: string) =>
    valor === SIN_FIRMANTE ? null : Number(valor);

/**
 * Lo que espera un pago por el banco (reglas A50 a A63): lo registrado en
 * ventanilla y lo que entró en parte, con lo que falta a la vista. Un pendiente no
 * vence y conserva su precio. El recibo sale al completar el total.
 */
export default function Pendientes({ charges, firmantes, can }: Props) {
    const [identificando, setIdentificando] = useState<Cobro | null>(null);
    const [ajustando, setAjustando] = useState<Cobro | null>(null);
    const [anulando, setAnulando] = useState<Cobro | null>(null);

    return (
        <>
            <Head title="Aranceles pendientes" />

            <div className="space-y-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Aranceles"
                    title="Pendientes"
                    description="Lo que se va a pagar por depósito o transferencia. El recibo sale cuando el extracto cubre el total."
                    actions={
                        can.create ? (
                            <Button asChild>
                                <Link href={create()}>
                                    <Plus
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    Nuevo cobro
                                </Link>
                            </Button>
                        ) : null
                    }
                />

                {charges.data.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-10 text-center">
                        <ReceiptText
                            className="mx-auto size-8 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <p className="mt-3 font-medium">
                            No hay cobros pendientes.
                        </p>
                    </div>
                ) : (
                    <ul className="divide-y rounded-lg border bg-card">
                        {charges.data.map((cobro) => {
                            const parcial =
                                compareAmounts(cobro.funded, '0') > 0;

                            return (
                                <li
                                    key={cobro.id}
                                    className="grid gap-2 p-4 sm:grid-cols-[1fr_auto] sm:items-start"
                                >
                                    <div className="min-w-0 space-y-1">
                                        <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                                            <span className="font-medium">
                                                {cobro.payerName}
                                            </span>
                                            <span className="font-medium tabular-nums">
                                                {money(cobro.total)}
                                            </span>
                                            {parcial && (
                                                <StatusBadge
                                                    label={`Faltan ${money(cobro.missing)}`}
                                                    tone="progress"
                                                />
                                            )}
                                        </div>
                                        <ul className="text-sm text-muted-foreground">
                                            {cobro.lines.map((linea) => (
                                                <li key={linea.itemId}>
                                                    {linea.code} · {linea.name}
                                                    {linea.quantity !== null &&
                                                        linea.quantity > 1 &&
                                                        ` × ${linea.quantity}`}
                                                    {' — '}
                                                    {money(linea.amount)}
                                                </li>
                                            ))}
                                        </ul>
                                        <p className="text-xs text-muted-foreground">
                                            Registrado el{' '}
                                            {date(cobro.requestedOn)}
                                            {cobro.fundings.map(
                                                (vinculo, i) => (
                                                    <span key={i}>
                                                        {' '}
                                                        · Entraron{' '}
                                                        {money(
                                                            vinculo.amount,
                                                        )}{' '}
                                                        el{' '}
                                                        {date(
                                                            vinculo.receivedOn,
                                                        )}
                                                    </span>
                                                ),
                                            )}
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap gap-1 sm:justify-end">
                                        {can.identify && (
                                            <Button
                                                size="sm"
                                                onClick={() =>
                                                    setIdentificando(cobro)
                                                }
                                            >
                                                Identificar crédito
                                            </Button>
                                        )}
                                        {can.adjust && (
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    setAjustando(cobro)
                                                }
                                            >
                                                Ajustar importe
                                            </Button>
                                        )}
                                        {can.void && (
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    setAnulando(cobro)
                                                }
                                            >
                                                Anular
                                            </Button>
                                        )}
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}

                {charges.total > 0 && (
                    <PaginationFooter
                        pagination={charges}
                        singular="cobro pendiente"
                        plural="cobros pendientes"
                    />
                )}
            </div>

            {identificando && (
                <IdentificarDialog
                    cobro={identificando}
                    firmantes={firmantes}
                    onClose={() => setIdentificando(null)}
                />
            )}
            {ajustando && (
                <AjustarDialog
                    cobro={ajustando}
                    firmantes={firmantes}
                    onClose={() => setAjustando(null)}
                />
            )}
            {anulando && (
                <AnularDialog
                    cobro={anulando}
                    onClose={() => setAnulando(null)}
                />
            )}
        </>
    );
}

function IdentificarDialog({
    cobro,
    firmantes,
    onClose,
}: {
    cobro: Cobro;
    firmantes: Firmante[];
    onClose: () => void;
}) {
    const [clave] = useState(
        () => `arancel-credito:${cobro.id}:${crypto.randomUUID()}`,
    );
    const [elegidos, setElegidos] = useState<Credito[]>([]);
    const form = useForm({ signedById: SIN_FIRMANTE });
    const errores = form.errors as Record<string, string | undefined>;
    const elegido = sumAmounts(elegidos.map((c) => c.free));
    const completa = compareAmounts(elegido, cobro.missing) >= 0;

    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.transform((d) => ({
                            credits: elegidos.map((c) => c.id),
                            signedById: firmanteDe(d.signedById),
                            idempotencyKey: clave,
                        }));
                        form.post(identificar(cobro.id).url, {
                            onSuccess: onClose,
                        });
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            Identificar el pago de {cobro.payerName}
                        </DialogTitle>
                        <DialogDescription>
                            Faltan {money(cobro.missing)}. De cada crédito se
                            toma solo lo necesario: lo que sobra queda libre.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 py-4">
                        <FeeCreditPicker
                            seleccionados={elegidos}
                            onChange={setElegidos}
                        />
                        <InputError message={errores['credits']} />
                        {elegidos.length > 0 && (
                            <p className="text-sm">
                                {completa
                                    ? 'Completa el cobro: se emite el recibo.'
                                    : `Queda un ingreso parcial: los créditos elegidos suman ${money(elegido)}.`}
                            </p>
                        )}
                        {completa && (
                            <SignerSelect
                                id="identificar-firma"
                                firmantes={firmantes}
                                value={form.data.signedById}
                                onChange={(v) => form.setData('signedById', v)}
                            />
                        )}
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Volver
                        </Button>
                        <Button
                            type="submit"
                            disabled={form.processing || elegidos.length === 0}
                        >
                            {completa
                                ? 'Identificar y emitir recibo'
                                : 'Registrar ingreso parcial'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function AjustarDialog({
    cobro,
    firmantes,
    onClose,
}: {
    cobro: Cobro;
    firmantes: Firmante[];
    onClose: () => void;
}) {
    const form = useForm({
        importes: Object.fromEntries(
            cobro.lines.map((l) => [l.itemId, l.amount]),
        ) as Record<number, string>,
        reasonCode: '' as Codigo | '',
        reason: '',
        signedById: SIN_FIRMANTE,
    });
    const errores = form.errors as Record<string, string | undefined>;
    const total = sumAmounts(
        Object.values(form.data.importes).map((i) => i || '0'),
    );
    const completa =
        compareAmounts(cobro.funded, '0') > 0 &&
        compareAmounts(total, cobro.funded) === 0;
    // Solo lo editado, con lo que se tenía a la vista: así no se pisa el ajuste de otro.
    const editadas = cobro.lines
        .filter(
            (l) =>
                compareAmounts(
                    form.data.importes[l.itemId] || '0',
                    l.amount,
                ) !== 0,
        )
        .map((l) => ({
            itemId: l.itemId,
            amount: form.data.importes[l.itemId],
            previousAmount: l.amount,
        }));

    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.transform((d) => ({
                            lines: editadas,
                            reasonCode: d.reasonCode,
                            reason: d.reason,
                            signedById: firmanteDe(d.signedById),
                        }));
                        form.post(ajustar(cobro.id).url, {
                            onSuccess: onClose,
                        });
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>Ajustar el importe</DialogTitle>
                        <DialogDescription>
                            Para aceptar más o menos que lo calculado. El
                            importe original se conserva, y el motivo queda
                            registrado.
                            {compareAmounts(cobro.funded, '0') > 0 &&
                                ` Ya entraron ${money(cobro.funded)}.`}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 py-4">
                        {cobro.lines.map((linea) => (
                            <div key={linea.itemId} className="grid gap-1.5">
                                <Label htmlFor={`ajuste-${linea.itemId}`}>
                                    {linea.code} · {linea.name}
                                </Label>
                                <AmountInput
                                    id={`ajuste-${linea.itemId}`}
                                    value={form.data.importes[linea.itemId]}
                                    onChange={(valor) =>
                                        form.setData('importes', {
                                            ...form.data.importes,
                                            [linea.itemId]: valor,
                                        })
                                    }
                                />
                            </div>
                        ))}
                        <InputError message={errores['lines']} />
                        <p className="text-sm font-medium tabular-nums">
                            Total: {money(total)}
                            {completa && ' · Se emite el recibo.'}
                        </p>
                        <ReasonFields<Acto, Codigo>
                            act="amount_change"
                            idPrefix="ajuste"
                            code={form.data.reasonCode}
                            detail={form.data.reason}
                            maxLength={300}
                            onCodeChange={(c) => form.setData('reasonCode', c)}
                            onDetailChange={(d) => form.setData('reason', d)}
                            codeError={errores['reasonCode']}
                            detailError={errores['reason']}
                        />
                        {completa && (
                            <SignerSelect
                                id="ajuste-firma"
                                firmantes={firmantes}
                                value={form.data.signedById}
                                onChange={(v) => form.setData('signedById', v)}
                            />
                        )}
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Volver
                        </Button>
                        <Button
                            type="submit"
                            disabled={form.processing || editadas.length === 0}
                        >
                            Guardar ajuste
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function AnularDialog({
    cobro,
    onClose,
}: {
    cobro: Cobro;
    onClose: () => void;
}) {
    const form = useForm({ reasonCode: '' as Codigo | '', reason: '' });
    const parcial = compareAmounts(cobro.funded, '0') > 0;

    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(anular(cobro.id).url, {
                            preserveScroll: true,
                            onSuccess: onClose,
                        });
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>Anular el cobro pendiente</DialogTitle>
                        <DialogDescription>
                            {parcial
                                ? `Se revierte el ingreso de ${money(cobro.funded)} y el crédito vuelve a quedar libre en el extracto.`
                                : 'Todavía no entró dinero: el cobro queda anulado y a la vista.'}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="py-4">
                        <ReasonFields<Acto, Codigo>
                            act="collection_void"
                            code={form.data.reasonCode}
                            detail={form.data.reason}
                            maxLength={300}
                            onCodeChange={(c) => form.setData('reasonCode', c)}
                            onDetailChange={(d) => form.setData('reason', d)}
                            codeError={form.errors.reasonCode}
                            detailError={form.errors.reason}
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Volver
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={form.processing}
                        >
                            Anular cobro
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
