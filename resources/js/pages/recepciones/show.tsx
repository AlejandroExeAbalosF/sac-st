import { Form, Head, router, useForm, usePage } from '@inertiajs/react';
import { CheckCircle2, Search, Undo2 } from 'lucide-react';
import { useState } from 'react';
import PageHeader from '@/components/page-header';
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
import { date, money } from '@/lib/format';
import { cn } from '@/lib/utils';
import { allocate, reverse, show } from '@/routes/recepciones';

type Receipt = App.Modules.Haberes.Data.FundReceiptDetailData;
type Allocation = App.Modules.Haberes.Data.FundingAllocationListItemData;
type Candidate = App.Modules.Haberes.Data.InstallmentCandidateData;

type Props = {
    receipt: Receipt;
    allocations: Allocation[];
    candidates: Candidate[];
    searchedExpediente: string | null;
    idempotencyKey: string;
    canAllocate: boolean;
    canReverse: boolean;
};

const MEDIO: Record<string, string> = {
    cash: 'Efectivo',
    cheque: 'Cheque',
    bank: 'Depósito en cuenta',
};

/**
 * La recepción y las cuotas que financia.
 *
 * Si el comprobante llegó a vincularse, las cuotas del expediente se
 * ofrecen sin que nadie busque nada: el ticket llegó dentro de ese
 * expediente, así que el sistema ya sabe cuál es. El buscador queda para
 * el resto de los casos.
 */
export default function RecepcionShow({
    receipt,
    allocations,
    candidates,
    searchedExpediente,
    idempotencyKey,
    canAllocate,
    canReverse,
}: Props) {
    const [busqueda, setBusqueda] = useState(searchedExpediente ?? '');
    const [revirtiendo, setRevirtiendo] = useState(false);
    const sinAsignar = Number(receipt.unallocated);
    const completa = sinAsignar <= 0;
    const revertida = receipt.reversedAt !== null;

    const buscar = (e: React.FormEvent) => {
        e.preventDefault();
        router.get(
            show(receipt.id).url,
            busqueda.trim() === '' ? {} : { expediente: busqueda.trim() },
            { preserveState: true, preserveScroll: true },
        );
    };

    return (
        <>
            <Head title={`Recepción del ${date(receipt.receivedDate)}`} />

            <div className="space-y-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Haberes"
                    title={`Recepción del ${date(receipt.receivedDate)}`}
                    description={
                        revertida
                            ? 'Esta recepción se revirtió: ese dinero no está en los libros.'
                            : completa
                              ? 'Todo el dinero de esta recepción ya tiene dueño.'
                              : 'Falta decir de quién es parte de este dinero.'
                    }
                    actions={
                        /*
                         * Solo mientras nada de esta plata tenga dueño: con
                         * una parte repartida, deshacerla es desasignar
                         * primero, que es otra decisión con su permiso.
                         */
                        canReverse &&
                        !revertida &&
                        receipt.allocated === '0.00' && (
                            <Button
                                variant="outline"
                                onClick={() => setRevirtiendo(true)}
                            >
                                <Undo2 className="size-4" aria-hidden="true" />
                                Revertir
                            </Button>
                        )
                    }
                />

                {/*
                 * Por qué no está el botón.
                 *
                 * Sin esto la pantalla no distingue «no se puede» de «no
                 * existe la función», y el operador se queda mirando una
                 * ausencia. Es un hecho, no una invitación: desasignar lo
                 * que ya financia una cuota es otra decisión.
                 */}
                {canReverse && !revertida && receipt.allocated !== '0.00' && (
                    <p className="text-sm text-muted-foreground">
                        Esta recepción no se puede revertir:{' '}
                        {money(receipt.allocated)} ya financian cuotas.
                    </p>
                )}

                {revertida && (
                    <p className="flex items-start gap-2 rounded-lg border border-destructive bg-destructive-soft px-3 py-2 text-sm text-destructive-strong">
                        <Undo2
                            className="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span>
                            Revertida el {date(receipt.reversedAt)}
                            {receipt.reversedByName !== null && (
                                <> por {receipt.reversedByName}</>
                            )}
                            . El asiento inverso sacó ese dinero de los libros y
                            el movimiento del extracto volvió a quedar sin
                            imputar.
                            {receipt.reversalReason !== null && (
                                <span className="mt-1 block italic">
                                    «{receipt.reversalReason}»
                                </span>
                            )}
                        </span>
                    </p>
                )}

                <div className="grid gap-4 lg:grid-cols-3">
                    <Cifra
                        etiqueta="Importe recibido"
                        valor={money(receipt.amount)}
                    />
                    <Cifra
                        etiqueta="Ya asignado"
                        valor={money(receipt.allocated)}
                    />
                    <Cifra
                        etiqueta="Sin asignar"
                        valor={money(receipt.unallocated)}
                        destacado={!completa}
                        completo={completa}
                    />
                </div>

                <div className="rounded-lg border p-4">
                    <h2 className="text-sm font-medium text-field-label">
                        De dónde vino
                    </h2>
                    <dl className="mt-3 grid gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
                        <Dato
                            etiqueta="Medio"
                            valor={MEDIO[receipt.medium] ?? receipt.medium}
                        />
                        <Dato
                            etiqueta="Caja"
                            valor={receipt.cashBoxName ?? '—'}
                        />
                        <Dato
                            etiqueta="Registrada por"
                            valor={receipt.receivedByName ?? '—'}
                        />
                        {receipt.bankAccountLabel && (
                            <Dato
                                etiqueta="Cuenta"
                                valor={receipt.bankAccountLabel}
                            />
                        )}
                        {receipt.expedienteNumber && (
                            <Dato
                                etiqueta="Expediente del comprobante"
                                valor={receipt.expedienteNumber}
                            />
                        )}
                        {receipt.employerName && (
                            <Dato
                                etiqueta="Empleador"
                                valor={receipt.employerName}
                            />
                        )}
                        {receipt.bankTransactionDescription && (
                            <div className="sm:col-span-2 lg:col-span-3">
                                <Dato
                                    etiqueta="Concepto del extracto"
                                    valor={receipt.bankTransactionDescription}
                                />
                            </div>
                        )}
                    </dl>
                </div>

                {allocations.length > 0 && (
                    <section className="space-y-3">
                        <h2 className="font-medium">Ya asignado a</h2>
                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-3 font-medium text-field-label">
                                            Expediente
                                        </th>
                                        <th className="p-3 font-medium text-field-label">
                                            Beneficiario
                                        </th>
                                        <th className="p-3 font-medium text-field-label">
                                            Cuota
                                        </th>
                                        <th className="p-3 text-right font-medium text-field-label">
                                            Importe
                                        </th>
                                        <th className="p-3 font-medium text-field-label">
                                            Asignado
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {allocations.map((a) => (
                                        <tr key={a.id} className="border-t">
                                            <td className="p-3">
                                                {a.expedienteNumber}
                                            </td>
                                            <td className="p-3">
                                                {a.beneficiaryName}
                                            </td>
                                            <td className="p-3">
                                                N.º {a.installmentNumber}
                                            </td>
                                            <td className="p-3 text-right font-mono tabular-nums">
                                                {money(a.amount)}
                                            </td>
                                            <td className="p-3 text-xs text-muted-foreground">
                                                {a.allocatedAt}
                                                {a.allocatedByName
                                                    ? ` · ${a.allocatedByName}`
                                                    : ''}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                )}

                {!completa && canAllocate && (
                    <section className="space-y-3">
                        <h2 className="font-medium">Asignar a una cuota</h2>

                        <form onSubmit={buscar} className="flex gap-2">
                            <Input
                                value={busqueda}
                                onChange={(e) => setBusqueda(e.target.value)}
                                placeholder="Buscar por número de expediente"
                                className="max-w-sm"
                            />
                            <Button type="submit" variant="outline">
                                <Search className="size-4" />
                                Buscar
                            </Button>
                        </form>

                        {candidates.length === 0 ? (
                            <div className="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">
                                {searchedExpediente
                                    ? 'Ningún expediente coincide con esa búsqueda.'
                                    : 'Buscá el expediente al que corresponde este dinero.'}
                            </div>
                        ) : (
                            <div className="space-y-3">
                                {candidates.map((cuota) => (
                                    <CuotaCandidata
                                        key={cuota.id}
                                        cuota={cuota}
                                        receiptId={receipt.id}
                                        medio={receipt.medium}
                                        sinAsignar={receipt.unallocated}
                                        idempotencyKey={idempotencyKey}
                                    />
                                ))}
                            </div>
                        )}
                    </section>
                )}
            </div>

            {revirtiendo && (
                <DialogoDeReversion
                    receipt={receipt}
                    idempotencyKey={idempotencyKey}
                    onClose={() => setRevirtiendo(false)}
                />
            )}
        </>
    );
}

/**
 * Una cuota con su formulario de asignación.
 *
 * El importe viene propuesto: el menor entre lo que falta y lo que queda
 * sin asignar. Es el que acierta casi siempre, porque la regla del área es
 * que cada cuota se deposita por su importe completo.
 */
function CuotaCandidata({
    cuota,
    receiptId,
    medio,
    sinAsignar,
    idempotencyKey,
}: {
    cuota: Candidate;
    receiptId: number;
    medio: string;
    sinAsignar: string;
    idempotencyKey: string;
}) {
    const propuesto = Math.min(
        Number(cuota.remaining),
        Number(sinAsignar),
    ).toFixed(2);

    const medioIncompatible =
        cuota.fixedMedium !== null && cuota.fixedMedium !== medio;
    const bloqueada = cuota.isFullyFunded || medioIncompatible;

    return (
        <div
            className={cn(
                'rounded-lg border p-4',
                bloqueada && 'bg-muted/30 opacity-75',
            )}
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="font-medium">
                        {cuota.beneficiaryName}
                        <span className="ml-2 text-sm font-normal text-muted-foreground">
                            cuota N.º {cuota.installmentNumber}
                        </span>
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {cuota.expedienteNumber}
                        {cuota.beneficiaryDocument
                            ? ` · DNI ${cuota.beneficiaryDocument}`
                            : ''}
                        {cuota.concept ? ` · ${cuota.concept}` : ''}
                    </p>
                </div>

                <div className="text-right text-sm">
                    <div className="font-mono tabular-nums">
                        {money(cuota.expectedAmount)}
                    </div>
                    {cuota.isFullyFunded ? (
                        <StatusBadge label="Financiada" tone="done" />
                    ) : (
                        <div className="text-xs text-muted-foreground">
                            faltan {money(cuota.remaining)}
                        </div>
                    )}
                </div>
            </div>

            {medioIncompatible && (
                <p className="mt-3 text-sm text-warning-strong">
                    Esta cuota ya se financia por otro medio. Una cuota se
                    financia con un solo medio, porque el recibo lo imprime en
                    singular.
                </p>
            )}

            {cuota.isFullyFunded && (
                <p className="mt-3 flex items-center gap-1.5 text-sm text-muted-foreground">
                    <CheckCircle2 className="size-4" />
                    Ya tiene todo su importe asignado.
                </p>
            )}

            {!bloqueada && (
                <Form
                    {...allocate.form(receiptId)}
                    className="mt-4 flex flex-wrap items-end gap-3"
                >
                    {({ errors, processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="installmentId"
                                value={cuota.id}
                            />
                            <input
                                type="hidden"
                                name="idempotencyKey"
                                value={`${idempotencyKey}:${cuota.id}`}
                            />

                            <div className="space-y-1.5">
                                <Label htmlFor={`amount-${cuota.id}`}>
                                    Importe a asignar
                                </Label>
                                <Input
                                    id={`amount-${cuota.id}`}
                                    name="amount"
                                    defaultValue={propuesto}
                                    inputMode="decimal"
                                    className="w-44 font-mono tabular-nums"
                                />
                            </div>

                            <Button type="submit" disabled={processing}>
                                Asignar
                            </Button>

                            {(errors.amount || errors.fund_receipt_id) && (
                                <p className="w-full text-sm text-destructive">
                                    {errors.amount ?? errors.fund_receipt_id}
                                </p>
                            )}
                        </>
                    )}
                </Form>
            )}
        </div>
    );
}

function Cifra({
    etiqueta,
    valor,
    destacado = false,
    completo = false,
}: {
    etiqueta: string;
    valor: string;
    destacado?: boolean;
    completo?: boolean;
}) {
    return (
        <div
            className={cn(
                'rounded-lg border p-4',
                destacado && 'border-warning/40 bg-warning/5',
                completo && 'border-success/40 bg-success-soft/40',
            )}
        >
            <p className="text-xs text-field-label">{etiqueta}</p>
            <p className="mt-1 font-mono text-xl tabular-nums">{valor}</p>
        </div>
    );
}

function Dato({ etiqueta, valor }: { etiqueta: string; valor: string }) {
    return (
        <div>
            <dt className="text-xs text-field-label">{etiqueta}</dt>
            <dd>{valor}</dd>
        </div>
    );
}

/**
 * Deshacer la recepción, con el motivo obligatorio.
 *
 * No es prolijidad: el CHECK `financial_events_reversal_reason_check` ata
 * el motivo a toda reversión. Pedirlo acá es hacer legible una regla que
 * la base ya impone.
 */
function DialogoDeReversion({
    receipt,
    idempotencyKey,
    onClose,
}: {
    receipt: Receipt;
    idempotencyKey: string;
    onClose: () => void;
}) {
    const form = useForm({ reason: '', idempotencyKey });

    /*
     * El rechazo por tener plata ya repartida no habla de ningún campo del
     * formulario, así que llega por fuera de él. Mismo camino que el
     * diálogo de cancelación del traslado.
     */
    const { errors } = usePage().props as unknown as {
        errors?: Record<string, string>;
    };

    const enviar = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(reverse(receipt.id).url, { preserveScroll: true });
    };

    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        Revertir la recepción de {money(receipt.amount)}
                    </DialogTitle>
                    <DialogDescription>
                        El asiento inverso saca ese dinero de los libros y el
                        movimiento del extracto vuelve a quedar sin imputar,
                        listo para imputarlo bien. La recepción no se borra:
                        queda con su motivo.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={enviar} className="grid gap-4">
                    <div>
                        <Label htmlFor="reason">Motivo</Label>
                        <Textarea
                            id="reason"
                            value={form.data.reason}
                            onChange={(e) =>
                                form.setData('reason', e.target.value)
                            }
                            className="mt-1"
                            rows={3}
                            maxLength={500}
                            autoFocus
                            aria-invalid={Boolean(form.errors.reason)}
                            placeholder="Qué pasó. Por ejemplo: era la acreditación de nuestro propio depósito, no plata nueva."
                        />
                        {form.errors.reason && (
                            <p className="mt-1 text-xs text-destructive-strong">
                                {form.errors.reason}
                            </p>
                        )}
                        {errors?.receiptId !== undefined && (
                            <p className="mt-1 text-xs text-destructive-strong">
                                {errors.receiptId}
                            </p>
                        )}
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={onClose}
                            disabled={form.processing}
                        >
                            Cancelar
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={form.processing}
                        >
                            {form.processing
                                ? 'Revirtiendo…'
                                : 'Revertir la recepción'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
