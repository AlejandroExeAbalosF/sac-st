import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    BadgeCheck,
    CheckCircle2,
    FileQuestion,
    Link2,
    ListFilter,
    Undo2,
} from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
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
import { cuit as formatCuit, date, money } from '@/lib/format';
import { cn } from '@/lib/utils';
import { preview } from '@/routes/adjuntos';
import { create as importarExtracto } from '@/routes/banco/extractos';
import { discard, link, search, unlink } from '@/routes/depositos';

type Ticket = App.Modules.Haberes.Data.DepositTicketData;
type Candidato = App.Modules.Haberes.Data.TicketCandidateData;

type Movimiento = App.Modules.Banking.Data.BankTransactionListItemData;

type Props = {
    ticket: Ticket;
    candidates: Candidato[];
    periodImported: boolean;
    emptyReason: string | null;
    warnings: string[];
    /** Los créditos disponibles, solo cuando el operador los pide. */
    available: { data: Movimiento[]; total: number } | null;
    availableOrder: string;
    showingAvailable: boolean;
    canLink: boolean;
    canDiscard: boolean;
    canEditTicket: boolean;
};

/**
 * Reconocer el movimiento que trajo el comprobante.
 *
 * El comprobante queda a la vista mientras se comparan los candidatos: es
 * lo mismo que hoy se hace con el papel en una mano y el homebanking en la
 * pantalla, con la diferencia de que los candidatos ya vienen filtrados
 * por cuenta, importe y fecha.
 *
 * **Nunca vincula solo.** El sistema propone y una persona reconoce, que
 * es lo que después queda registrado en `matched_by`.
 */
export default function DepositoSearch({
    ticket,
    candidates,
    periodImported,
    emptyReason,
    warnings,
    available,
    availableOrder,
    showingAvailable,
    canLink,
    canDiscard,
}: Props) {
    const [descartando, setDescartando] = useState(false);
    const [desvinculando, setDesvinculando] = useState(false);

    const vincular = (transactionId: number) => {
        router.post(link(ticket.id).url, { bankTransactionId: transactionId });
    };

    return (
        <>
            <Head title={`Comprobante · ${ticket.expedienteNumber}`} />

            <div className="space-y-6 p-4 sm:p-6">
                <PageHeader
                    title={`Depósito de ${money(ticket.amount)}`}
                    description={`Expediente ${ticket.expedienteNumber}${
                        ticket.employerName ? ` · ${ticket.employerName}` : ''
                    } · depositado el ${date(ticket.depositedAt)}`}
                    actions={
                        ticket.status === 'matched' ? (
                            <Button
                                variant="outline"
                                onClick={() => setDesvinculando(true)}
                            >
                                <Undo2 className="size-4" />
                                Desvincular
                            </Button>
                        ) : canDiscard ? (
                            <Button
                                variant="ghost"
                                onClick={() => setDescartando(true)}
                            >
                                Descartar
                            </Button>
                        ) : null
                    }
                />

                {warnings.map((aviso) => (
                    <div
                        key={aviso}
                        className="flex items-start gap-2 rounded-md border bg-warning-soft/50 p-3 text-sm text-warning-strong"
                    >
                        <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                        <span>{aviso}</span>
                    </div>
                ))}

                {ticket.status === 'matched' && (
                    <div className="rounded-lg border bg-success-soft/40 p-4">
                        <p className="flex items-center gap-2 font-medium text-success-strong">
                            <CheckCircle2 className="size-5" />
                            El comprobante quedó vinculado a su movimiento
                        </p>
                        {ticket.matchSignals && (
                            <ul className="mt-2 space-y-0.5 text-sm text-muted-foreground">
                                {Object.entries(ticket.matchSignals).map(
                                    ([clave, valor]) => (
                                        <li key={clave}>
                                            <span className="text-field-label">
                                                {clave}:
                                            </span>{' '}
                                            {valor}
                                        </li>
                                    ),
                                )}
                            </ul>
                        )}
                        <p className="mt-3 text-sm text-muted-foreground">
                            Todavía no financia la cuota: eso es la recepción,
                            que llega en la etapa siguiente.
                        </p>
                    </div>
                )}

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]">
                    <div className="space-y-3">
                        {candidates.length === 0 ? (
                            <SinCandidatos
                                razon={emptyReason}
                                periodoImportado={periodImported}
                            />
                        ) : (
                            <>
                                <p className="text-sm text-muted-foreground">
                                    {candidates.length === 1
                                        ? 'Un movimiento coincide con el importe y la fecha.'
                                        : `${candidates.length} movimientos coinciden con el importe. El más probable va primero.`}
                                </p>
                                {candidates.map((candidato) => (
                                    <TarjetaCandidato
                                        key={candidato.transactionId}
                                        candidato={candidato}
                                        puedeVincular={
                                            canLink &&
                                            ticket.status !== 'matched'
                                        }
                                        yaVinculado={
                                            ticket.bankTransactionId ===
                                            candidato.transactionId
                                        }
                                        onVincular={() =>
                                            vincular(candidato.transactionId)
                                        }
                                    />
                                ))}
                            </>
                        )}

                        {ticket.status !== 'matched' && (
                            <Disponibles
                                ticketId={ticket.id}
                                movimientos={available}
                                orden={availableOrder}
                                mostrando={showingAvailable}
                                hayCandidatos={candidates.length > 0}
                                puedeVincular={canLink}
                                onVincular={vincular}
                            />
                        )}
                    </div>

                    <ComprobanteAdjunto ticket={ticket} />
                </div>
            </div>

            <DialogoMotivo
                abierto={descartando}
                titulo="Descartar el comprobante"
                descripcion="Es afirmar que ese depósito no va a aparecer nunca: el ticket estaba mal, se anuló, o se cargó por error. El comprobante se conserva con su motivo."
                accion={discard(ticket.id).url}
                metodo="patch"
                onClose={() => setDescartando(false)}
            />

            <DialogoMotivo
                abierto={desvinculando}
                titulo="Desvincular del movimiento"
                descripcion="El comprobante vuelve a la cola de espera y el movimiento queda disponible para otro."
                accion={unlink(ticket.id).url}
                metodo="patch"
                onClose={() => setDesvinculando(false)}
            />
        </>
    );
}

function TarjetaCandidato({
    candidato,
    puedeVincular,
    yaVinculado,
    onVincular,
}: {
    candidato: Candidato;
    puedeVincular: boolean;
    yaVinculado: boolean;
    onVincular: () => void;
}) {
    const fuerte = candidato.operationMatches || candidato.employerMatches;

    return (
        <div
            className={cn(
                'rounded-lg border p-4',
                fuerte && 'border-primary/40 bg-primary/[0.03]',
                yaVinculado && 'border-success bg-success-soft/30',
            )}
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="font-mono text-lg font-semibold tabular-nums">
                        {money(candidato.amount)}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {date(candidato.transactionDate)}
                        {candidato.operationId
                            ? ` · ref. ${candidato.operationId}`
                            : ''}
                        {candidato.causalCode
                            ? ` · causal ${candidato.causalCode}`
                            : ''}
                    </p>
                    <p className="mt-1 max-w-xl truncate text-sm">
                        {candidato.description ?? '—'}
                    </p>
                    {candidato.counterpartyIdentifier && (
                        <p className="mt-0.5 inline-flex items-center gap-1 text-xs text-muted-foreground">
                            <Link2 className="size-3" />
                            {formatCuit(candidato.counterpartyIdentifier)}
                            {candidato.counterpartyName
                                ? ` · ${candidato.counterpartyName}`
                                : ''}
                        </p>
                    )}
                </div>

                {yaVinculado ? (
                    <StatusBadge label="Vinculado" tone="done" />
                ) : puedeVincular ? (
                    <Button onClick={onVincular}>Es este</Button>
                ) : null}
            </div>

            {/*
             * Por qué está acá. Las señales en palabras y no un puntaje: si
             * el orden está equivocado, quien mira tiene que poder darse
             * cuenta.
             */}
            <ul className="mt-3 flex flex-wrap gap-x-4 gap-y-1 border-t pt-3 text-xs">
                {Object.entries(candidato.signals).map(([clave, valor]) => (
                    <li key={clave} className="inline-flex items-center gap-1">
                        {(clave === 'operación' ||
                            (clave === 'CUIT del concepto' &&
                                candidato.employerMatches)) && (
                            <BadgeCheck className="size-3.5 text-primary" />
                        )}
                        <span className="text-field-label">{clave}:</span>
                        <span
                            className={cn(
                                valor.includes('NO es el del empleador') &&
                                    'text-warning-strong',
                            )}
                        >
                            {valor}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

/**
 * Los créditos disponibles, cuando el cruce exacto no alcanzó.
 *
 * «No hay candidatos» casi nunca significa que el depósito no exista:
 * significa que algo de lo que se transcribió del papel no coincide, y lo
 * más frecuente es un dígito del importe. Viendo los créditos reales el
 * operador encuentra el suyo —difiere en pocos pesos— y entiende que lo
 * que hay que corregir es el ticket.
 *
 * Por eso el orden por defecto es la cercanía y no la fecha: con un
 * dígito mal tipeado, el movimiento correcto queda primero.
 */
function Disponibles({
    ticketId,
    movimientos,
    orden,
    mostrando,
    hayCandidatos,
    puedeVincular,
    onVincular,
}: {
    ticketId: number;
    movimientos: { data: Movimiento[]; total: number } | null;
    orden: string;
    mostrando: boolean;
    hayCandidatos: boolean;
    puedeVincular: boolean;
    onVincular: (transactionId: number) => void;
}) {
    const pedir = (nuevoOrden: string) =>
        router.get(
            search(ticketId).url,
            { todos: 1, orden: nuevoOrden },
            { preserveState: true, preserveScroll: true },
        );

    if (!mostrando || movimientos === null) {
        return (
            <div className="rounded-lg border border-dashed p-4 text-center">
                <p className="text-sm text-muted-foreground">
                    {hayCandidatos
                        ? '¿Ninguno de estos es? Mirá todos los créditos de la cuenta.'
                        : 'El depósito puede estar cargado con otro importe o con otra fecha.'}
                </p>
                <Button
                    variant="outline"
                    size="sm"
                    className="mt-3"
                    onClick={() => pedir('cercania')}
                >
                    <ListFilter className="size-4" />
                    Ver los créditos disponibles
                </Button>
            </div>
        );
    }

    return (
        <div className="space-y-3 rounded-lg border p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-sm">
                    <span className="font-medium">
                        {movimientos.total} créditos disponibles
                    </span>{' '}
                    <span className="text-muted-foreground">
                        en esta cuenta
                    </span>
                </p>

                <div className="flex items-center gap-1 text-xs">
                    <span className="text-muted-foreground">Ordenar por</span>
                    {[
                        ['cercania', 'Cercanía'],
                        ['fecha', 'Fecha'],
                        ['importe', 'Importe'],
                    ].map(([valor, etiqueta]) => (
                        <button
                            key={valor}
                            type="button"
                            onClick={() => pedir(valor)}
                            className={cn(
                                'rounded px-2 py-1 transition-colors',
                                orden === valor
                                    ? 'bg-primary text-primary-foreground'
                                    : 'hover:bg-muted',
                            )}
                        >
                            {etiqueta}
                        </button>
                    ))}
                </div>
            </div>

            {movimientos.data.length === 0 ? (
                <p className="py-6 text-center text-sm text-muted-foreground">
                    Esta cuenta no tiene créditos disponibles. Puede que falte
                    importar el extracto del período.
                </p>
            ) : (
                <ul className="divide-y">
                    {movimientos.data.map((m) => (
                        <li
                            key={m.id}
                            className="flex flex-wrap items-center justify-between gap-3 py-2.5"
                        >
                            <div className="min-w-0">
                                <p className="text-sm">
                                    <span className="tabular-nums">
                                        {date(m.transactionDate)}
                                    </span>
                                    <span className="ml-2 font-mono font-medium tabular-nums">
                                        {money(m.amount)}
                                    </span>
                                </p>
                                <p className="truncate text-xs text-muted-foreground">
                                    {m.description ?? 'Sin concepto'}
                                    {m.counterpartyIdentifier
                                        ? ` · ${formatCuit(m.counterpartyIdentifier)}`
                                        : ''}
                                </p>
                            </div>

                            {puedeVincular && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="h-8 text-xs"
                                    onClick={() => onVincular(m.id)}
                                >
                                    Es este
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            <p className="text-xs text-muted-foreground">
                Vincular exige que el importe coincida con el del comprobante.
                Si el que corresponde tiene otro importe, primero hay que
                corregir el comprobante.
            </p>
        </div>
    );
}

/**
 * La lista vacía dice cuál de las dos cosas pasó.
 *
 * «Falta importar el período» y «el crédito no aparece» se ven iguales y
 * mandan a lugares distintos: una al archivo del banco, la otra a revisar
 * el papel.
 */
function SinCandidatos({
    razon,
    periodoImportado,
}: {
    razon: string | null;
    periodoImportado: boolean;
}) {
    return (
        <div className="rounded-lg border border-dashed p-8 text-center">
            <FileQuestion className="mx-auto size-8 text-muted-foreground" />
            <p className="mt-3 font-medium">
                No hay ningún movimiento que coincida exacto
            </p>
            <p className="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
                {razon}
            </p>
            {!periodoImportado && (
                <Button variant="outline" className="mt-4" asChild>
                    <Link href={importarExtracto()}>Importar un extracto</Link>
                </Button>
            )}
        </div>
    );
}

function ComprobanteAdjunto({ ticket }: { ticket: Ticket }) {
    return (
        <div className="lg:sticky lg:top-6 lg:self-start">
            <div className="rounded-lg border p-2">
                {ticket.attachmentId ? (
                    <a
                        href={preview(ticket.attachmentId).url}
                        target="_blank"
                        rel="noreferrer"
                        title="Abrir el comprobante en tamaño completo"
                    >
                        <img
                            src={preview(ticket.attachmentId).url}
                            alt="Comprobante del depósito"
                            className="w-full rounded"
                        />
                    </a>
                ) : (
                    <div className="flex flex-col items-center gap-2 px-6 py-12 text-center">
                        <AlertTriangle className="size-6 text-warning-strong" />
                        <p className="text-sm font-medium">
                            Este comprobante no tiene foto
                        </p>
                        <p className="text-xs text-muted-foreground">
                            Se cargó solo con los datos. Sin la imagen no hay
                            contra qué contrastar lo que dice el extracto.
                        </p>
                    </div>
                )}
            </div>

            <dl className="mt-3 space-y-2 rounded-lg border p-4 text-sm">
                <Dato titulo="Importe" valor={money(ticket.amount)} />
                <Dato titulo="Fecha" valor={date(ticket.depositedAt)} />
                {ticket.depositedTime && (
                    <Dato titulo="Hora" valor={ticket.depositedTime} />
                )}
                {ticket.operationNumber && (
                    <Dato
                        titulo="Nº de operación"
                        valor={ticket.operationNumber}
                    />
                )}
                <Dato titulo="Cuenta" valor={ticket.accountLabel} />
                {ticket.installmentLabel && (
                    <Dato titulo="Apunta a" valor={ticket.installmentLabel} />
                )}
                {ticket.notes && (
                    <Dato titulo="Observaciones" valor={ticket.notes} />
                )}
            </dl>
        </div>
    );
}

function Dato({ titulo, valor }: { titulo: string; valor: string }) {
    return (
        <div className="flex justify-between gap-3">
            <dt className="text-field-label">{titulo}</dt>
            <dd className="text-right tabular-nums">{valor}</dd>
        </div>
    );
}

function DialogoMotivo({
    abierto,
    titulo,
    descripcion,
    accion,
    metodo,
    onClose,
}: {
    abierto: boolean;
    titulo: string;
    descripcion: string;
    accion: string;
    metodo: 'patch' | 'post';
    onClose: () => void;
}) {
    const form = useForm({ reason: '' });

    const enviar = (event: React.FormEvent) => {
        event.preventDefault();
        form.submit(metodo, accion, {
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    return (
        <Dialog open={abierto} onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <form onSubmit={enviar}>
                    <DialogHeader>
                        <DialogTitle>{titulo}</DialogTitle>
                        <DialogDescription>{descripcion}</DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2 py-4">
                        <Label htmlFor="reason">Motivo</Label>
                        <Input
                            id="reason"
                            value={form.data.reason}
                            onChange={(e) =>
                                form.setData('reason', e.target.value)
                            }
                        />
                        <InputError message={form.errors.reason} />
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Confirmar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
