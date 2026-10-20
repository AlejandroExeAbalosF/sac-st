import { Head, Link } from '@inertiajs/react';
import { FilePlus2, History, Paperclip, Pencil, Scale } from 'lucide-react';
import { useState } from 'react';
import DataField, { EmptyValue } from '@/components/data-field';
import Money from '@/components/money';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import AdjustDebtDialog from '@/features/multas/components/adjust-debt-dialog';
import {
    AttachFileDialog,
    AttachmentList,
} from '@/features/multas/components/attachments';
import HistoricalMovementDialog from '@/features/multas/components/historical-movement-dialog';
import LinkDocumentDialog from '@/features/multas/components/link-document-dialog';
import ReasonDialog from '@/features/multas/components/reason-dialog';
import ReviewHistoricalDialog from '@/features/multas/components/review-historical-dialog';
import ReviseDateDialog from '@/features/multas/components/revise-date-dialog';
import ReviseFineDialog from '@/features/multas/components/revise-fine-dialog';
import {
    DEBT_KIND,
    DEBT_STATUS,
    DOCUMENT_ROLE,
    HISTORICAL_KIND,
    HISTORICAL_STATUS,
    partialDate,
} from '@/features/multas/labels';
import { dateTime, isNonZero, sumAmounts } from '@/lib/format';
import { store as attachToFine } from '@/routes/multas/adjuntos';
import { reverse } from '@/routes/multas/ajustes';
import { store as attachToMovement } from '@/routes/multas/antecedentes/adjuntos';
import { store as reviseMovementDate } from '@/routes/multas/antecedentes/revisiones';
import { show as showCase } from '@/routes/multas/expedientes';
import { store as reviseEntryDate } from '@/routes/multas/movimientos/revisiones';

type Multa = App.Modules.Multas.Data.FineDetailData;
type Documento = App.Modules.Multas.Data.FineDocumentData;
type Movimiento = App.Modules.Multas.Data.FineDebtEntryData;
type Antecedente = App.Modules.Multas.Data.FineHistoricalMovementData;
type Adjunto = App.Modules.Multas.Data.FineAttachmentData;

type Props = {
    multa: Multa;
    documentosDelExpediente: Documento[];
    adjuntosDelExpediente: Adjunto[];
    can: { register: boolean; adjust: boolean; viewAttachments: boolean };
};

type Dialogo =
    | { tipo: 'ajustar' }
    | { tipo: 'revertir'; movimiento: Movimiento }
    | { tipo: 'vincular' }
    | { tipo: 'antecedente' }
    | {
          tipo: 'revisar';
          antecedente: Antecedente;
          resultado: 'validated' | 'rejected';
      }
    | { tipo: 'corregir' }
    | { tipo: 'adjuntar' }
    | { tipo: 'adjuntar-antecedente'; antecedente: Antecedente }
    | { tipo: 'fecha-movimiento'; movimiento: Movimiento }
    | { tipo: 'fecha-antecedente'; antecedente: Antecedente };

/**
 * La ficha de la multa: qué se debe, por qué papel y cuánto queda.
 *
 * El importe original no se edita nunca. Lo que lo cambia es un ajuste con
 * su resolución, y lo que se cargó mal se revierte entero: la historia de
 * la obligación queda completa a la vista.
 */
export default function MultaShow({
    multa,
    documentosDelExpediente,
    adjuntosDelExpediente,
    can,
}: Props) {
    const [dialogo, setDialogo] = useState<Dialogo | null>(null);
    const cerrar = () => setDialogo(null);

    /*
     * Completar una fecha que falta o le falta el día es de quien carga;
     * cambiar una fecha completa es corregir, y eso es de quien ajusta.
     */
    const puedeRevisarFecha = (
        precision: App.Modules.Multas.Enums.DatePrecision,
    ): boolean => can.adjust || (can.register && precision !== 'day');
    const estado = DEBT_STATUS[multa.balance.status];
    const pagado = multa.balance.paid;

    return (
        <>
            <Head title={`Multa · ${multa.caption}`} />

            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title={multa.caption}
                    eyebrow={
                        <Link
                            href={showCase(multa.caseId)}
                            className="hover:underline"
                        >
                            Multa · Expte.{' '}
                            <span className="font-mono">{multa.caseLabel}</span>
                        </Link>
                    }
                    description={
                        multa.provenance === 'import'
                            ? 'Importada del Excel histórico.'
                            : 'Cargada a mano.'
                    }
                    actions={
                        // Completar es de quien carga; corregir, de quien ajusta.
                        (can.register || can.adjust) && (
                            <Button
                                variant="outline"
                                onClick={() => setDialogo({ tipo: 'corregir' })}
                            >
                                <Pencil className="size-4" aria-hidden="true" />
                                Corregir datos
                            </Button>
                        )
                    }
                />

                {/* El saldo, primero: es lo que viene a preguntar quien paga. */}
                <section
                    aria-label="Saldo"
                    className="grid gap-3 rounded-lg border bg-card p-5 shadow-raised sm:grid-cols-4"
                >
                    <Cifra
                        rotulo="Importe vigente"
                        valor={multa.balance.obligation}
                    />
                    <Cifra
                        rotulo="Pagado"
                        valor={pagado}
                        historico={multa.balance.historicalPaid}
                    />
                    <Cifra
                        rotulo="Saldo"
                        valor={multa.balance.debt}
                        destacado
                    />
                    <div>
                        <p className="text-xs tracking-wide text-field-label uppercase">
                            Estado
                        </p>
                        <div className="mt-1.5 flex flex-wrap gap-1">
                            <StatusBadge
                                label={estado.label}
                                tone={estado.tone}
                            />
                            {isNonZero(multa.balance.excess) && (
                                <StatusBadge
                                    label="Con excedente"
                                    tone="action"
                                />
                            )}
                        </div>
                        {isNonZero(multa.balance.excess) && (
                            <p className="mt-1 text-xs text-muted-foreground">
                                Excedente pendiente{' '}
                                <Money value={multa.balance.excess} />. No se
                                devuelve ni se acredita solo.
                            </p>
                        )}
                    </div>
                </section>

                <section className="rounded-lg border bg-card p-5 shadow-raised">
                    <h2 className="text-sm font-semibold">Datos</h2>
                    <dl className="mt-4 grid gap-x-8 gap-y-4 sm:grid-cols-3">
                        <DataField label="Carátula en el papel">
                            {multa.captionOriginal}
                        </DataField>
                        <DataField label="Persona">
                            {multa.personName ?? (
                                <EmptyValue>Sin vincular</EmptyValue>
                            )}
                        </DataField>
                        <DataField label="Fecha de registro">
                            {partialDate(
                                multa.registeredOn,
                                multa.registeredPrecision,
                            )}
                        </DataField>
                        <DataField label="Expediente en el papel">
                            <span className="font-mono">
                                {multa.caseOriginalText}
                            </span>
                        </DataField>
                        <DataField label="Cargada por">
                            {multa.createdByName ?? (
                                <EmptyValue>Sin dato</EmptyValue>
                            )}
                            {multa.createdAt && (
                                <span className="text-muted-foreground">
                                    {' '}
                                    · {dateTime(multa.createdAt)}
                                </span>
                            )}
                        </DataField>
                    </dl>
                </section>

                <section className="rounded-lg border bg-card shadow-raised">
                    <Encabezado titulo="Documentos">
                        {can.register && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setDialogo({ tipo: 'vincular' })}
                            >
                                <FilePlus2 aria-hidden="true" />
                                Vincular documento
                            </Button>
                        )}
                    </Encabezado>
                    <ul className="divide-y">
                        {multa.documents.map((documento) => (
                            <li
                                key={documento.id}
                                className="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 text-sm"
                            >
                                <span className="font-medium">
                                    {documento.label}
                                </span>
                                <span className="font-mono text-xs text-muted-foreground">
                                    {documento.originalText}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {partialDate(
                                        documento.issuedOn,
                                        documento.issuedPrecision,
                                    )}
                                </span>
                                <span className="ml-auto flex gap-1">
                                    {documento.roles.map((rol) => (
                                        <StatusBadge
                                            key={rol}
                                            label={DOCUMENT_ROLE[rol]}
                                        />
                                    ))}
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>

                <section className="rounded-lg border bg-card shadow-raised">
                    <Encabezado titulo="Archivos">
                        {can.register && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setDialogo({ tipo: 'adjuntar' })}
                            >
                                <Paperclip aria-hidden="true" />
                                Adjuntar archivo
                            </Button>
                        )}
                    </Encabezado>
                    <div className="grid gap-4 px-5 py-3">
                        {multa.attachments.length === 0 &&
                        adjuntosDelExpediente.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Sin archivos. Las fotos de la resolución y de
                                las actas van en el expediente.
                            </p>
                        ) : (
                            <>
                                {multa.attachments.length > 0 && (
                                    <AttachmentList
                                        adjuntos={multa.attachments}
                                        canView={can.viewAttachments}
                                    />
                                )}
                                {adjuntosDelExpediente.length > 0 && (
                                    <div className="grid gap-1.5">
                                        <p className="text-xs tracking-wide text-field-label uppercase">
                                            Del expediente
                                        </p>
                                        <AttachmentList
                                            adjuntos={adjuntosDelExpediente}
                                            canView={can.viewAttachments}
                                        />
                                    </div>
                                )}
                            </>
                        )}
                    </div>
                </section>

                <section className="rounded-lg border bg-card shadow-raised">
                    <Encabezado titulo="Obligación">
                        {can.adjust && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setDialogo({ tipo: 'ajustar' })}
                            >
                                <Scale aria-hidden="true" />
                                Ajustar
                            </Button>
                        )}
                    </Encabezado>
                    {/* `relative`: el rótulo `sr-only` del encabezado es absoluto y, sin un
                        contenedor posicionado, escapa del recorte y ensancha la página. */}
                    <div className="relative overflow-x-auto [contain:inline-size]">
                        <table className="w-full border-collapse text-left text-sm">
                            <thead>
                                <tr className="border-b bg-muted text-xs tracking-wide text-field-label uppercase">
                                    <th
                                        scope="col"
                                        className="px-5 py-2 font-medium"
                                    >
                                        Movimiento
                                    </th>
                                    <th
                                        scope="col"
                                        className="py-2 pr-5 font-medium"
                                    >
                                        Fecha
                                    </th>
                                    <th
                                        scope="col"
                                        className="py-2 pr-5 font-medium"
                                    >
                                        Respaldo
                                    </th>
                                    <th
                                        scope="col"
                                        className="py-2 pr-5 text-right font-medium"
                                    >
                                        Importe
                                    </th>
                                    <th
                                        scope="col"
                                        className="py-2 pr-5 font-medium"
                                    >
                                        Cargado
                                    </th>
                                    <th scope="col" className="py-2 pr-5">
                                        <span className="sr-only">
                                            Acciones
                                        </span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {multa.debtEntries.map((movimiento) => {
                                    const revertido =
                                        movimiento.reversedById !== null;
                                    const resta =
                                        movimiento.kind === 'reduction' ||
                                        (movimiento.kind === 'reversal' &&
                                            multa.debtEntries.find(
                                                (m) =>
                                                    m.id ===
                                                    movimiento.reversalOfId,
                                            )?.kind === 'increase');

                                    return (
                                        <tr
                                            key={movimiento.id}
                                            className="border-b align-top last:border-b-0"
                                        >
                                            <td className="px-5 py-2.5">
                                                <span
                                                    className={
                                                        revertido
                                                            ? 'line-through opacity-70'
                                                            : undefined
                                                    }
                                                >
                                                    {DEBT_KIND[movimiento.kind]}
                                                </span>
                                                {revertido && (
                                                    <span className="ml-2 text-xs text-muted-foreground">
                                                        revertido
                                                    </span>
                                                )}
                                                {movimiento.reason && (
                                                    <p className="text-xs text-muted-foreground">
                                                        {movimiento.reason}
                                                    </p>
                                                )}
                                            </td>
                                            <td className="py-2.5 pr-5 whitespace-nowrap">
                                                {partialDate(
                                                    movimiento.effectiveOn,
                                                    movimiento.effectivePrecision,
                                                )}
                                            </td>
                                            <td className="py-2.5 pr-5 text-xs">
                                                {movimiento.documentLabel ?? (
                                                    <EmptyValue>—</EmptyValue>
                                                )}
                                            </td>
                                            <td className="py-2.5 pr-5 text-right whitespace-nowrap">
                                                {resta && '− '}
                                                <Money
                                                    value={movimiento.amount}
                                                />
                                            </td>
                                            <td className="py-2.5 pr-5 text-xs text-muted-foreground">
                                                {movimiento.createdByName ??
                                                    '—'}
                                                {movimiento.createdAt && (
                                                    <>
                                                        {' '}
                                                        ·{' '}
                                                        {dateTime(
                                                            movimiento.createdAt,
                                                        )}
                                                    </>
                                                )}
                                            </td>
                                            <td className="py-2.5 pr-5 text-right whitespace-nowrap">
                                                {puedeRevisarFecha(
                                                    movimiento.effectivePrecision,
                                                ) && (
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            setDialogo({
                                                                tipo: 'fecha-movimiento',
                                                                movimiento,
                                                            })
                                                        }
                                                    >
                                                        Fecha
                                                    </Button>
                                                )}
                                                {can.adjust &&
                                                    !revertido &&
                                                    (movimiento.kind ===
                                                        'increase' ||
                                                        movimiento.kind ===
                                                            'reduction') && (
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            onClick={() =>
                                                                setDialogo({
                                                                    tipo: 'revertir',
                                                                    movimiento,
                                                                })
                                                            }
                                                        >
                                                            Revertir
                                                        </Button>
                                                    )}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section className="rounded-lg border bg-card shadow-raised">
                    <Encabezado titulo="Pagos anteriores al sistema">
                        {can.register && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    setDialogo({ tipo: 'antecedente' })
                                }
                            >
                                <History aria-hidden="true" />
                                Registrar pago anterior
                            </Button>
                        )}
                    </Encabezado>
                    {multa.historicalMovements.length === 0 ? (
                        <p className="px-5 py-4 text-sm text-muted-foreground">
                            Sin pagos anteriores registrados.
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {multa.historicalMovements.map((antecedente) => {
                                const estadoAntecedente =
                                    HISTORICAL_STATUS[antecedente.status];

                                return (
                                    <li
                                        key={antecedente.id}
                                        className="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 text-sm"
                                    >
                                        <span>
                                            {HISTORICAL_KIND[antecedente.kind]}{' '}
                                            ·{' '}
                                            <Money value={antecedente.amount} />
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {partialDate(
                                                antecedente.occurredOn,
                                                antecedente.occurredPrecision,
                                            )}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {antecedente.evidence}
                                        </span>
                                        <span className="ml-auto flex items-center gap-2">
                                            <StatusBadge
                                                label={estadoAntecedente.label}
                                                tone={estadoAntecedente.tone}
                                            />
                                            {can.register && (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        setDialogo({
                                                            tipo: 'adjuntar-antecedente',
                                                            antecedente,
                                                        })
                                                    }
                                                >
                                                    Comprobante
                                                </Button>
                                            )}
                                            {puedeRevisarFecha(
                                                antecedente.occurredPrecision,
                                            ) && (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        setDialogo({
                                                            tipo: 'fecha-antecedente',
                                                            antecedente,
                                                        })
                                                    }
                                                >
                                                    Fecha
                                                </Button>
                                            )}
                                            {can.adjust &&
                                                antecedente.status ===
                                                    'pending_review' && (
                                                    <>
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() =>
                                                                setDialogo({
                                                                    tipo: 'revisar',
                                                                    antecedente,
                                                                    resultado:
                                                                        'validated',
                                                                })
                                                            }
                                                        >
                                                            Validar
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            onClick={() =>
                                                                setDialogo({
                                                                    tipo: 'revisar',
                                                                    antecedente,
                                                                    resultado:
                                                                        'rejected',
                                                                })
                                                            }
                                                        >
                                                            Descartar
                                                        </Button>
                                                    </>
                                                )}
                                        </span>
                                        {antecedente.attachments.length > 0 && (
                                            <div className="w-full">
                                                <AttachmentList
                                                    adjuntos={
                                                        antecedente.attachments
                                                    }
                                                    canView={
                                                        can.viewAttachments
                                                    }
                                                />
                                            </div>
                                        )}
                                        {antecedente.reviewNote && (
                                            <p className="w-full text-xs text-muted-foreground">
                                                {antecedente.reviewedByName ??
                                                    '—'}
                                                : {antecedente.reviewNote}
                                            </p>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </section>
            </div>

            {dialogo?.tipo === 'fecha-movimiento' && (
                <ReviseDateDialog
                    title={`Fecha del ${DEBT_KIND[dialogo.movimiento.kind].toLowerCase()}`}
                    url={reviseEntryDate(dialogo.movimiento.id).url}
                    value={dialogo.movimiento.effectiveOn}
                    onClose={cerrar}
                />
            )}
            {dialogo?.tipo === 'fecha-antecedente' && (
                <ReviseDateDialog
                    title="Fecha del pago anterior"
                    url={reviseMovementDate(dialogo.antecedente.id).url}
                    value={dialogo.antecedente.occurredOn}
                    onClose={cerrar}
                />
            )}
            {dialogo?.tipo === 'ajustar' && (
                <AdjustDebtDialog
                    fineId={multa.id}
                    resoluciones={documentosDelExpediente.filter(
                        (d) => d.kind === 'resolution',
                    )}
                    onClose={cerrar}
                />
            )}
            {dialogo?.tipo === 'revertir' && (
                <ReasonDialog
                    title={`Revertir el ${DEBT_KIND[dialogo.movimiento.kind].toLowerCase()}`}
                    description="Deshace el ajuste entero, por el mismo importe. El ajuste queda a la vista marcado como revertido. Si solo una parte estaba mal, registrá otro ajuste."
                    url={reverse(dialogo.movimiento.id).url}
                    submitLabel="Revertir ajuste"
                    onClose={cerrar}
                />
            )}
            {dialogo?.tipo === 'vincular' && (
                <LinkDocumentDialog
                    fineId={multa.id}
                    documentos={documentosDelExpediente}
                    onClose={cerrar}
                />
            )}
            {dialogo?.tipo === 'antecedente' && (
                <HistoricalMovementDialog fineId={multa.id} onClose={cerrar} />
            )}
            {dialogo?.tipo === 'revisar' && (
                <ReviewHistoricalDialog
                    antecedente={dialogo.antecedente}
                    resultado={dialogo.resultado}
                    onClose={cerrar}
                />
            )}
            {dialogo?.tipo === 'adjuntar' && (
                <AttachFileDialog
                    url={attachToFine(multa.id).url}
                    title="Adjuntar un archivo a la multa"
                    description="Lo que respalda esta multa en particular. La foto de la resolución o del acta va en el expediente, que es dueño de esos papeles."
                    onClose={cerrar}
                />
            )}
            {dialogo?.tipo === 'adjuntar-antecedente' && (
                <AttachFileDialog
                    url={attachToMovement(dialogo.antecedente.id).url}
                    title="Comprobante del pago anterior"
                    description="El recibo, la boleta o la planilla que muestra que el pago se hizo. Ayuda a validarlo."
                    onClose={cerrar}
                />
            )}
            {dialogo?.tipo === 'corregir' && (
                <ReviseFineDialog multa={multa} onClose={cerrar} />
            )}
        </>
    );
}

function Cifra({
    rotulo,
    valor,
    historico,
    destacado = false,
}: {
    rotulo: string;
    valor: string;
    /** Lo pagado antes del sistema, que no pasó por el libro. */
    historico?: string;
    destacado?: boolean;
}) {
    return (
        <div>
            <p className="text-xs tracking-wide text-field-label uppercase">
                {rotulo}
            </p>
            <p
                className={
                    destacado ? 'mt-1 text-xl font-semibold' : 'mt-1 text-lg'
                }
            >
                <Money
                    value={
                        historico !== undefined && isNonZero(historico)
                            ? sumAmounts([valor, historico])
                            : valor
                    }
                />
            </p>
            {historico !== undefined && isNonZero(historico) && (
                <p className="text-xs text-muted-foreground">
                    <Money value={historico} /> antes del sistema
                </p>
            )}
        </div>
    );
}

function Encabezado({
    titulo,
    children,
}: {
    titulo: string;
    children?: React.ReactNode;
}) {
    return (
        <div className="flex items-center justify-between gap-4 border-b px-5 py-3">
            <h2 className="text-sm font-semibold">{titulo}</h2>
            {children}
        </div>
    );
}
