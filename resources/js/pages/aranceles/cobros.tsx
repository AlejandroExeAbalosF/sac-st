import { Head, Link, router, useForm } from '@inertiajs/react';
import { Plus, ReceiptText, Search } from 'lucide-react';
import { useState } from 'react';
import DocumentInput from '@/components/document-input';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import FeeReceiptDialog from '@/features/aranceles/components/fee-receipt-dialog';
import SignerSelect, {
    SIN_FIRMANTE,
} from '@/features/aranceles/components/signer-select';
import type { Firmante } from '@/features/aranceles/components/signer-select';
import { date, money } from '@/lib/format';
import { anular, create, index, reemplazar } from '@/routes/aranceles/cobros';
import { edit as corregir } from '@/routes/aranceles/cobros/corregir';

type Cobro = App.Modules.Aranceles.Data.FeeChargeListItemData;
type Acto = App.Modules.Aranceles.Enums.FeeReasonAct;
type Codigo = App.Modules.Aranceles.Enums.FeeReasonCode;

type Props = {
    charges: PaginationData & { data: Cobro[] };
    filters: { q: string; cobro: number | null };
    openReceipt: number | null;
    firmantes: Firmante[];
    can: {
        collect: boolean;
        void: boolean;
        replace: boolean;
        correct: boolean;
    };
};

/** Los cobros de aranceles, del más nuevo al más viejo, y lo que se hace con cada uno. */
export default function Cobros({
    charges,
    filters,
    openReceipt,
    firmantes,
    can,
}: Props) {
    const [buscado, setBuscado] = useState(filters.q);
    const [recibo, setRecibo] = useState<number | null>(openReceipt);
    const [ultimoPedido, setUltimoPedido] = useState(openReceipt);

    // Reemplazar vuelve a esta misma pantalla: el recibo nuevo se abre igual.
    if (openReceipt !== ultimoPedido) {
        setUltimoPedido(openReceipt);
        setRecibo(openReceipt);
    }

    const [anulando, setAnulando] = useState<Cobro | null>(null);
    const [reemplazando, setReemplazando] = useState<Cobro | null>(null);

    const buscar = (e: React.FormEvent) => {
        e.preventDefault();
        router.get(
            index().url,
            buscado.trim() === '' ? {} : { q: buscado.trim() },
            { preserveState: true },
        );
    };

    return (
        <>
            <Head title="Cobros de aranceles" />

            <div className="space-y-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Aranceles"
                    title="Cobros"
                    description="Lo cobrado, con su recibo. Un cobro anulado o corregido queda a la vista."
                    actions={
                        can.collect ? (
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

                <form onSubmit={buscar} className="flex max-w-xl gap-2">
                    <Input
                        aria-label="Buscar por quién paga o por recibo"
                        placeholder="Quién paga, recibo o talonario"
                        value={buscado}
                        onChange={(e) => setBuscado(e.target.value)}
                    />
                    <Button type="submit" variant="outline">
                        <Search className="size-4" aria-hidden="true" />
                        Buscar
                    </Button>
                </form>

                {filters.cobro !== null && (
                    <p className="text-sm">
                        Mostrando un cobro.{' '}
                        <Link
                            href={index()}
                            className="text-primary hover:underline"
                        >
                            Ver todos
                        </Link>
                    </p>
                )}

                {charges.data.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-10 text-center">
                        <ReceiptText
                            className="mx-auto size-8 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <p className="mt-3 font-medium">
                            No hay cobros para mostrar.
                        </p>
                    </div>
                ) : (
                    <ul className="divide-y rounded-lg border bg-card">
                        {charges.data.map((cobro) => (
                            <FilaDeCobro
                                key={cobro.id}
                                cobro={cobro}
                                can={can}
                                onVerRecibo={setRecibo}
                                onAnular={setAnulando}
                                onReemplazar={setReemplazando}
                            />
                        ))}
                    </ul>
                )}

                {charges.total > 0 && (
                    <PaginationFooter
                        pagination={charges}
                        singular="cobro"
                        plural="cobros"
                    />
                )}
            </div>

            {recibo !== null && (
                <FeeReceiptDialog
                    receiptId={recibo}
                    onClose={() => setRecibo(null)}
                />
            )}
            {anulando && (
                <AnularDialog
                    cobro={anulando}
                    onClose={() => setAnulando(null)}
                />
            )}
            {reemplazando && (
                <ReemplazarDialog
                    cobro={reemplazando}
                    firmantes={firmantes}
                    onClose={() => setReemplazando(null)}
                />
            )}
        </>
    );
}

function FilaDeCobro({
    cobro,
    can,
    onVerRecibo,
    onAnular,
    onReemplazar,
}: {
    cobro: Cobro;
    can: Props['can'];
    onVerRecibo: (id: number) => void;
    onAnular: (cobro: Cobro) => void;
    onReemplazar: (cobro: Cobro) => void;
}) {
    const pagado = cobro.status === 'paid';
    const anulado = cobro.status === 'voided';

    return (
        <li className="grid gap-2 p-4 sm:grid-cols-[1fr_auto] sm:items-start">
            <div className="min-w-0 space-y-1">
                <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span className="font-medium">{cobro.payerName}</span>
                    <span
                        className={
                            anulado
                                ? 'tabular-nums line-through'
                                : 'font-medium tabular-nums'
                        }
                    >
                        {money(cobro.total)}
                    </span>
                    {anulado && <StatusBadge label="Anulado" voided />}
                    {cobro.deposited && (
                        <StatusBadge label="Depositado" tone="progress" />
                    )}
                </div>
                <ul className="text-sm text-muted-foreground">
                    {cobro.lines.map((linea) => (
                        <li key={`${cobro.id}-${linea.code}-${linea.amount}`}>
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
                    {cobro.paidOn && date(cobro.paidOn)}
                    {cobro.receiptNumber && ` · Recibo ${cobro.receiptNumber}`}
                    {cobro.talonarioNumber &&
                        ` · Talonario ${cobro.talonarioNumber}`}
                    {cobro.voidReason && ` · ${cobro.voidReason}`}
                </p>
            </div>
            <div className="flex flex-wrap gap-1 sm:justify-end">
                {cobro.receiptId !== null && (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => onVerRecibo(cobro.receiptId as number)}
                    >
                        Ver recibo
                    </Button>
                )}
                {pagado && can.replace && (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => onReemplazar(cobro)}
                    >
                        Reemplazar recibo
                    </Button>
                )}
                {pagado && can.correct && (
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={corregir(cobro.id)}>Corregir</Link>
                    </Button>
                )}
                {pagado && can.void && !cobro.deposited && (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => onAnular(cobro)}
                    >
                        Anular
                    </Button>
                )}
            </div>
        </li>
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
                        <DialogTitle>Anular el cobro</DialogTitle>
                        <DialogDescription>
                            Es para un cobro cuyo dinero nunca entró. El
                            efectivo sale de la caja y el recibo queda anulado
                            con su número. Si el dinero entró y hay un error,
                            corregí el cobro o reemplazá su recibo.
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

function ReemplazarDialog({
    cobro,
    firmantes,
    onClose,
}: {
    cobro: Cobro;
    firmantes: Firmante[];
    onClose: () => void;
}) {
    const form = useForm({
        payerName: cobro.payerName,
        payerCuit: cobro.payerCuit ?? '',
        wording: Object.fromEntries(
            cobro.lines.map((l, i) => [i + 1, l.name]),
        ) as Record<number, string>,
        signedById: SIN_FIRMANTE,
        reasonCode: '' as Codigo | '',
        reason: '',
    });

    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.transform((d) => ({
                            ...d,
                            signedById:
                                d.signedById === SIN_FIRMANTE
                                    ? null
                                    : Number(d.signedById),
                        }));
                        form.post(reemplazar(cobro.id).url, {
                            onSuccess: onClose,
                        });
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>Reemplazar el recibo</DialogTitle>
                        <DialogDescription>
                            Corrige el nombre, el CUIT o la redacción con un
                            recibo de número nuevo, sin mover dinero. Para
                            cambiar el tipo, la cantidad o el importe, corregí
                            el cobro.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-4 py-4">
                        <div className="grid gap-1.5">
                            <Label htmlFor="r-nombre">Recibí de</Label>
                            <Input
                                id="r-nombre"
                                maxLength={200}
                                value={form.data.payerName}
                                onChange={(e) =>
                                    form.setData('payerName', e.target.value)
                                }
                            />
                            <InputError message={form.errors.payerName} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="r-cuit">CUIT (opcional)</Label>
                            <DocumentInput
                                id="r-cuit"
                                maxDigits={11}
                                value={form.data.payerCuit}
                                onChange={(v) => form.setData('payerCuit', v)}
                            />
                            <InputError message={form.errors.payerCuit} />
                        </div>
                        {cobro.lines.map((linea, i) => (
                            <div key={i} className="grid gap-1.5">
                                <Label htmlFor={`r-linea-${i}`}>
                                    Concepto, código {linea.code}
                                </Label>
                                <Textarea
                                    id={`r-linea-${i}`}
                                    rows={2}
                                    maxLength={300}
                                    value={form.data.wording[i + 1] ?? ''}
                                    onChange={(e) =>
                                        form.setData('wording', {
                                            ...form.data.wording,
                                            [i + 1]: e.target.value,
                                        })
                                    }
                                />
                            </div>
                        ))}
                        <SignerSelect
                            id="r-firma"
                            firmantes={firmantes}
                            value={form.data.signedById}
                            onChange={(v) => form.setData('signedById', v)}
                        />
                        <ReasonFields<Acto, Codigo>
                            act="receipt_replacement"
                            idPrefix="reemplazo"
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
                        <Button type="submit" disabled={form.processing}>
                            Emitir recibo nuevo
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
