import { Head, Link } from '@inertiajs/react';
import {
    Banknote,
    FilePlus2,
    Gavel,
    History,
    Landmark,
    Paperclip,
    Pencil,
    Receipt,
    Scale,
    Unlink,
} from 'lucide-react';
import { useState } from 'react';
import DataField, { EmptyValue } from '@/components/data-field';
import Money from '@/components/money';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import AdjustDebtDialog from '@/features/multas/components/adjust-debt-dialog';
import AppealDialog from '@/features/multas/components/appeal-dialog';
import {
    AttachFileDialog,
    AttachmentList,
} from '@/features/multas/components/attachments';
import BankPaymentDialog from '@/features/multas/components/bank-payment-dialog';
import { ReviseDocumentDialog } from '@/features/multas/components/case-dialogs';
import CollectPaymentDialog from '@/features/multas/components/collect-payment-dialog';
import type { Firmante } from '@/features/multas/components/collect-payment-dialog';
import CollectionDeposit from '@/features/multas/components/collection-deposit';
import FineReceiptDialog from '@/features/multas/components/fine-receipt-dialog';
import HistoricalMovementDialog from '@/features/multas/components/historical-movement-dialog';
import LinkDocumentDialog from '@/features/multas/components/link-document-dialog';
import ReasonDialog from '@/features/multas/components/reason-dialog';
import ReviewHistoricalDialog from '@/features/multas/components/review-historical-dialog';
import ReviseDateDialog from '@/features/multas/components/revise-date-dialog';
import ReviseFineDialog from '@/features/multas/components/revise-fine-dialog';
import VerifyPaymentDialog from '@/features/multas/components/verify-payment-dialog';
import {
    DEBT_KIND,
    DEBT_STATUS,
    DOCUMENT_ROLE,
    HISTORICAL_KIND,
    APPEAL_KIND,
    HISTORICAL_STATUS,
    PAYMENT_MEDIUM,
    PAYMENT_STATUS,
    TIMELINESS,
    partialDate,
} from '@/features/multas/labels';
import { date, dateTime, isNonZero, sumAmounts } from '@/lib/format';
import { store as attachToFine } from '@/routes/multas/adjuntos';
import { reverse } from '@/routes/multas/ajustes';
import { store as attachToMovement } from '@/routes/multas/antecedentes/adjuntos';
import { store as reviseMovementDate } from '@/routes/multas/antecedentes/revisiones';
import { store as attachToDocument } from '@/routes/multas/documentos/adjuntos';
import { store as revokeLink } from '@/routes/multas/documentos/anulacion';
import { show as showCase } from '@/routes/multas/expedientes';
import { store as reviseEntryDate } from '@/routes/multas/movimientos/revisiones';
import { discard } from '@/routes/multas/pagos';
import { store as attachToPayment } from '@/routes/multas/pagos/adjuntos';

type Multa = App.Modules.Multas.Data.FineDetailData;
type Documento = App.Modules.Multas.Data.FineDocumentData;
type Papel = App.Modules.Multas.Enums.FineDocumentRole;
type Movimiento = App.Modules.Multas.Data.FineDebtEntryData;
type Antecedente = App.Modules.Multas.Data.FineHistoricalMovementData;
type Adjunto = App.Modules.Multas.Data.FineAttachmentData;
type Credito = App.Modules.Multas.Data.FineBankCreditData;
type Pago = App.Modules.Multas.Data.FinePaymentData;

type Props = {
    multa: Multa;
    documentosDelExpediente: Documento[];
    adjuntosDelExpediente: Adjunto[];
    /** La foto de cada resolución y de cada acta de la multa, por id del documento. */
    adjuntosDeDocumentos: Record<number, Adjunto[] | undefined>;
    /** Llegan solo al buscar un crédito, con una recarga parcial. */
    creditosDelBanco?: Credito[];
    /** Los créditos libres posteriores a la apertura; llegan al buscar. */
    creditosDisponibles?: Credito[];
    /** El 20 % del importe vigente, sin centavos (M07). */
    /** El 20 % calculado como referencia, y si la multa ya tiene su recurso. */
    recurso: { base: string; required: string; registered: boolean };
    firmantes: Firmante[];
    /** La clave del cobro: un doble clic no cobra dos veces. */
    cobroKey: string;
    can: {
        register: boolean;
        collect: boolean;
        reportBank: boolean;
        verifyBank: boolean;
        adjust: boolean;
        viewAttachments: boolean;
        linkBankCredit: boolean;
        deposit: boolean;
        confirmDeposit: boolean;
        manageRejections: boolean;
        replaceCheque: boolean;
    };
};

type Dialogo =
    | { tipo: 'ajustar' }
    | { tipo: 'cobrar' }
    | { tipo: 'banco' }
    | { tipo: 'recurso' }
    | { tipo: 'verificar'; pago: Pago }
    | { tipo: 'descartar-pago'; pago: Pago }
    | { tipo: 'corregir-pago'; pago: Pago }
    | { tipo: 'adjuntar-pago'; pago: Pago }
    | { tipo: 'recibo'; id: number; numero: string }
    | { tipo: 'revertir'; movimiento: Movimiento }
    | { tipo: 'vincular' }
    | { tipo: 'adjuntar-documento'; documento: Documento }
    | { tipo: 'corregir-documento'; documento: Documento }
    | { tipo: 'desvincular'; documento: Documento; papel: Papel }
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
    adjuntosDeDocumentos,
    creditosDelBanco,
    creditosDisponibles,
    recurso,
    firmantes,
    cobroKey,
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
                            href={showCase(multa.caseRouteKey)}
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
                            {isNonZero(multa.balance.pendingRegularization) && (
                                <StatusBadge
                                    label="Pago a regularizar"
                                    tone="blocked"
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
                        {/*
                         * El rechazo no vuelve a deber la multa: el pago y su
                         * recibo siguen (M03). Lo que falta se muestra aparte,
                         * y con saldo cero se dice que no es una cancelación
                         * sin pendientes.
                         */}
                        {isNonZero(multa.balance.pendingRegularization) && (
                            <p className="mt-1 text-xs text-destructive-strong">
                                {isNonZero(multa.balance.debt)
                                    ? 'Pendiente de regularización '
                                    : 'Saldo cero, con pago pendiente de regularización: '}
                                <Money
                                    value={multa.balance.pendingRegularization}
                                />
                                , por un cheque rechazado sin reemplazo.
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
                                {/* El documento es del expediente: adjuntar o corregir lo ve cada multa que lo cita. */}
                                <span className="flex basis-full flex-wrap justify-end gap-1">
                                    {can.register && (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                setDialogo({
                                                    tipo: 'adjuntar-documento',
                                                    documento,
                                                })
                                            }
                                            aria-label={`Adjuntar archivo a ${documento.label}`}
                                        >
                                            <Paperclip aria-hidden="true" />
                                            Adjuntar
                                        </Button>
                                    )}
                                    {(can.register || can.adjust) && (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                setDialogo({
                                                    tipo: 'corregir-documento',
                                                    documento,
                                                })
                                            }
                                            aria-label={`Corregir ${documento.label}`}
                                        >
                                            <Pencil aria-hidden="true" />
                                            Corregir
                                        </Button>
                                    )}
                                    {/* El papel que exige un movimiento vigente no se desvincula: no se ofrece. */}
                                    {can.adjust &&
                                        documento.roles
                                            .filter(
                                                (papel) =>
                                                    !documento.requiredRoles.includes(
                                                        papel,
                                                    ),
                                            )
                                            .map((papel) => (
                                                <Button
                                                    key={papel}
                                                    size="sm"
                                                    variant="ghost"
                                                    className="text-destructive hover:text-destructive"
                                                    onClick={() =>
                                                        setDialogo({
                                                            tipo: 'desvincular',
                                                            documento,
                                                            papel,
                                                        })
                                                    }
                                                    aria-label={`Desvincular ${documento.label} como ${DOCUMENT_ROLE[papel].toLowerCase()}`}
                                                >
                                                    <Unlink aria-hidden="true" />
                                                    {documento.roles.length > 1
                                                        ? `Desvincular (${DOCUMENT_ROLE[papel].toLowerCase()})`
                                                        : 'Desvincular'}
                                                </Button>
                                            ))}
                                </span>
                                {(adjuntosDeDocumentos[documento.id] ?? [])
                                    .length > 0 && (
                                    <div className="basis-full pt-1">
                                        <AttachmentList
                                            adjuntos={
                                                adjuntosDeDocumentos[
                                                    documento.id
                                                ] ?? []
                                            }
                                            canView={can.viewAttachments}
                                        />
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                    {multa.revokedDocuments.length > 0 && (
                        <div className="border-t px-5 py-3">
                            <p className="text-xs tracking-wide text-field-label uppercase">
                                Desvinculados
                            </p>
                            <ul className="mt-1.5 grid gap-1.5">
                                {multa.revokedDocuments.map((anulado) => (
                                    <li
                                        key={`${anulado.documentId}-${anulado.role}-${anulado.revokedAt}`}
                                        className="text-sm text-muted-foreground"
                                    >
                                        <span className="line-through">
                                            {anulado.label}
                                        </span>{' '}
                                        · {DOCUMENT_ROLE[anulado.role]}.
                                        Desvinculado el{' '}
                                        {dateTime(anulado.revokedAt)}
                                        {anulado.revokedByName !== null &&
                                            ` por ${anulado.revokedByName}`}
                                        : {anulado.reason}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </section>

                <section className="rounded-lg border bg-card shadow-raised">
                    <Encabezado titulo="Archivos de la multa">
                        {can.register && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setDialogo({ tipo: 'adjuntar' })}
                            >
                                <Paperclip aria-hidden="true" />
                                Adjuntar a la multa
                            </Button>
                        )}
                    </Encabezado>
                    <div className="grid gap-4 px-5 py-3">
                        {multa.attachments.length === 0 &&
                        adjuntosDelExpediente.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Sin archivos de la multa ni del expediente.
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
                    <Encabezado titulo="Pagos">
                        {isNonZero(multa.balance.debt) && (
                            <div className="flex flex-wrap justify-end gap-2">
                                {can.collect && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            setDialogo({ tipo: 'cobrar' })
                                        }
                                    >
                                        <Banknote aria-hidden="true" />
                                        Cobrar en caja
                                    </Button>
                                )}
                                {can.reportBank && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            setDialogo({ tipo: 'banco' })
                                        }
                                    >
                                        <Landmark aria-hidden="true" />
                                        Pago por banco
                                    </Button>
                                )}
                                {(can.collect || can.reportBank) &&
                                    !recurso.registered && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                setDialogo({ tipo: 'recurso' })
                                            }
                                        >
                                            <Gavel aria-hidden="true" />
                                            Recurso (20 %)
                                        </Button>
                                    )}
                            </div>
                        )}
                    </Encabezado>
                    {multa.payments.length === 0 ? (
                        <p className="px-5 py-4 text-sm text-muted-foreground">
                            Sin pagos en el sistema.
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {multa.payments.map((pago) => {
                                const estadoPago = PAYMENT_STATUS[pago.status];
                                const termino =
                                    pago.timeliness === null
                                        ? null
                                        : TIMELINESS[pago.timeliness];

                                return (
                                    <li
                                        key={pago.id}
                                        className="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 text-sm"
                                    >
                                        <span>
                                            {PAYMENT_MEDIUM[pago.medium]} ·{' '}
                                            <Money value={pago.amount} />
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {pago.receiptDate
                                                ? date(pago.receiptDate)
                                                : dateTime(pago.createdAt)}
                                            {pago.chequeNumber &&
                                                ` · Cheque ${pago.chequeNumber}`}
                                            {pago.chequeBank &&
                                                ` (${pago.chequeBank})`}
                                            {pago.judicialReference &&
                                                ` · Orden judicial ${pago.judicialReference}`}
                                        </span>
                                        <span className="ml-auto flex flex-wrap items-center gap-2">
                                            {pago.isCancellation && (
                                                <StatusBadge
                                                    label="Cancelatorio"
                                                    tone="done"
                                                />
                                            )}
                                            {termino && (
                                                <StatusBadge
                                                    label={termino.label}
                                                    tone={termino.tone}
                                                />
                                            )}
                                            <StatusBadge
                                                label={estadoPago.label}
                                                tone={estadoPago.tone}
                                            />
                                            {pago.status === 'reported' &&
                                                can.verifyBank && (
                                                    <>
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() =>
                                                                setDialogo({
                                                                    tipo: 'verificar',
                                                                    pago,
                                                                })
                                                            }
                                                        >
                                                            Verificar
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            variant="ghost"
                                                            onClick={() =>
                                                                setDialogo({
                                                                    tipo: 'descartar-pago',
                                                                    pago,
                                                                })
                                                            }
                                                        >
                                                            Descartar
                                                        </Button>
                                                    </>
                                                )}
                                            {pago.status === 'reported' &&
                                                can.reportBank && (
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            setDialogo({
                                                                tipo: 'corregir-pago',
                                                                pago,
                                                            })
                                                        }
                                                    >
                                                        Corregir
                                                    </Button>
                                                )}
                                            {can.reportBank &&
                                                pago.status !== 'discarded' && (
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            setDialogo({
                                                                tipo: 'adjuntar-pago',
                                                                pago,
                                                            })
                                                        }
                                                    >
                                                        Comprobante
                                                    </Button>
                                                )}
                                            {pago.receiptId !== null &&
                                                pago.receiptNumber !== null && (
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            setDialogo({
                                                                tipo: 'recibo',
                                                                id: pago.receiptId as number,
                                                                numero: pago.receiptNumber as string,
                                                            })
                                                        }
                                                    >
                                                        <Receipt aria-hidden="true" />
                                                        <span className="font-mono">
                                                            {pago.talonarioNumber ??
                                                                pago.receiptNumber}
                                                        </span>
                                                    </Button>
                                                )}
                                        </span>
                                        {pago.appealKind !== null && (
                                            <p className="w-full text-xs text-muted-foreground">
                                                {APPEAL_KIND[pago.appealKind]}:
                                                20 % de{' '}
                                                <Money
                                                    value={
                                                        pago.appealBaseAmount ??
                                                        '0.00'
                                                    }
                                                />{' '}
                                                calculado en{' '}
                                                <Money
                                                    value={
                                                        pago.appealRequiredAmount ??
                                                        '0.00'
                                                    }
                                                />
                                                ; recibo externo{' '}
                                                {pago.externalReceiptReference}.
                                                {pago.appealDiffers &&
                                                    ' El importe cobrado es el del recibo externo y difiere del 20 % calculado.'}
                                            </p>
                                        )}
                                        {pago.replacesPaymentId !== null && (
                                            <p className="w-full text-xs text-muted-foreground">
                                                Corrige un pago informado que se
                                                descartó.
                                            </p>
                                        )}
                                        {pago.discardReason && (
                                            <p className="w-full text-xs text-muted-foreground">
                                                Descartado: {pago.discardReason}
                                            </p>
                                        )}
                                        {pago.deposit && (
                                            <div className="w-full">
                                                <CollectionDeposit
                                                    paymentId={pago.id}
                                                    amount={pago.amount}
                                                    deposito={pago.deposit}
                                                    desde="multa"
                                                    canDeposit={can.deposit}
                                                    canConfirm={
                                                        can.confirmDeposit
                                                    }
                                                    canViewAttachments={
                                                        can.viewAttachments
                                                    }
                                                    isCheque={
                                                        pago.medium === 'cheque'
                                                    }
                                                    canManageRejections={
                                                        can.manageRejections
                                                    }
                                                    canReplaceCheque={
                                                        can.replaceCheque
                                                    }
                                                />
                                            </div>
                                        )}
                                        {pago.attachments.length > 0 && (
                                            <div className="w-full">
                                                <AttachmentList
                                                    adjuntos={pago.attachments}
                                                    canView={
                                                        can.viewAttachments
                                                    }
                                                />
                                            </div>
                                        )}
                                        {pago.excessAmount !== null &&
                                            isNonZero(pago.excessAmount) && (
                                                <p className="w-full text-xs text-muted-foreground">
                                                    Aplicado{' '}
                                                    <Money
                                                        value={
                                                            pago.appliedAmount ??
                                                            '0.00'
                                                        }
                                                    />
                                                    ; excedente{' '}
                                                    <Money
                                                        value={
                                                            pago.excessAmount
                                                        }
                                                    />
                                                    .
                                                </p>
                                            )}
                                    </li>
                                );
                            })}
                        </ul>
                    )}
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
                                            {antecedente.bankCredit && (
                                                <>
                                                    {' · '}crédito del banco
                                                    {antecedente.bankCredit
                                                        .operationId &&
                                                        ` · Op. ${antecedente.bankCredit.operationId}`}
                                                </>
                                            )}
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
                                            {antecedente.bankCredit === null &&
                                                puedeRevisarFecha(
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
            {dialogo?.tipo === 'cobrar' && (
                <CollectPaymentDialog
                    fineId={multa.id}
                    caption={multa.caption}
                    debt={multa.balance.debt}
                    idempotencyKey={cobroKey}
                    firmantes={firmantes}
                    onClose={cerrar}
                />
            )}
            {dialogo?.tipo === 'banco' && (
                <BankPaymentDialog
                    fineId={multa.id}
                    canVerify={can.verifyBank}
                    creditos={creditosDisponibles}
                    firmantes={firmantes}
                    idempotencyKey={cobroKey}
                    onClose={cerrar}
                />
            )}
            {dialogo?.tipo === 'recurso' && (
                <AppealDialog
                    fineId={multa.id}
                    base={recurso.base}
                    required={recurso.required}
                    allowCash={can.collect}
                    firmantes={firmantes}
                    idempotencyKey={cobroKey}
                    onClose={cerrar}
                />
            )}
            {dialogo?.tipo === 'verificar' && (
                <VerifyPaymentDialog
                    pago={dialogo.pago}
                    creditos={creditosDisponibles}
                    firmantes={firmantes}
                    onClose={cerrar}
                />
            )}
            {dialogo?.tipo === 'descartar-pago' && (
                <ReasonDialog
                    act="payment_discard"
                    title="Descartar el pago informado"
                    description="Nunca redujo el saldo ni tuvo recibo: queda a la vista como descartado, con el motivo."
                    url={discard(dialogo.pago.id).url}
                    submitLabel="Descartar pago"
                    onClose={cerrar}
                />
            )}
            {dialogo?.tipo === 'corregir-pago' &&
                (dialogo.pago.appealKind !== null ? (
                    <AppealDialog
                        fineId={multa.id}
                        base={dialogo.pago.appealBaseAmount ?? recurso.base}
                        required={
                            dialogo.pago.appealRequiredAmount ??
                            recurso.required
                        }
                        allowCash={false}
                        firmantes={firmantes}
                        idempotencyKey={cobroKey}
                        corrige={dialogo.pago}
                        onClose={cerrar}
                    />
                ) : (
                    <BankPaymentDialog
                        fineId={multa.id}
                        canVerify={false}
                        firmantes={firmantes}
                        idempotencyKey={cobroKey}
                        corrige={dialogo.pago}
                        onClose={cerrar}
                    />
                ))}
            {dialogo?.tipo === 'adjuntar-pago' && (
                <AttachFileDialog
                    url={attachToPayment(dialogo.pago.id).url}
                    title="Comprobante del pago"
                    description="La transferencia, la boleta del depósito o el recibo externo del recurso."
                    onClose={cerrar}
                />
            )}
            {dialogo?.tipo === 'recibo' && (
                <FineReceiptDialog
                    receiptId={dialogo.id}
                    numero={dialogo.numero}
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
                    act="reversal"
                    title={`Revertir el ${DEBT_KIND[dialogo.movimiento.kind].toLowerCase()}`}
                    description="Deshace el ajuste entero, por el mismo importe. El ajuste queda a la vista marcado como revertido. Si solo una parte estaba mal, registrá otro ajuste."
                    url={reverse(dialogo.movimiento.id).url}
                    submitLabel="Revertir ajuste"
                    onClose={cerrar}
                />
            )}
            {dialogo?.tipo === 'adjuntar-documento' && (
                <AttachFileDialog
                    url={attachToDocument(dialogo.documento.id).url}
                    title={`Adjuntar el archivo de ${dialogo.documento.label}`}
                    description="La foto o el PDF de este papel. Queda en el documento, y lo ve cada multa y cada expediente que lo usa."
                    onClose={cerrar}
                />
            )}
            {dialogo?.tipo === 'corregir-documento' && (
                <ReviseDocumentDialog
                    documento={dialogo.documento}
                    onClose={cerrar}
                />
            )}
            {dialogo?.tipo === 'desvincular' && (
                <ReasonDialog
                    act="link_revocation"
                    title={`Desvincular ${dialogo.documento.label}`}
                    description={`Anula el vínculo como ${DOCUMENT_ROLE[dialogo.papel].toLowerCase()}. El documento sigue en el expediente y el vínculo queda a la vista como desvinculado, con el motivo.`}
                    url={
                        revokeLink({
                            fine: multa.id,
                            fineDocument: dialogo.documento.id,
                            role: dialogo.papel,
                        }).url
                    }
                    submitLabel="Desvincular"
                    onClose={cerrar}
                />
            )}
            {dialogo?.tipo === 'vincular' && (
                <LinkDocumentDialog
                    fineId={multa.id}
                    documentos={documentosDelExpediente}
                    vinculados={multa.documents}
                    onClose={cerrar}
                />
            )}
            {dialogo?.tipo === 'antecedente' && (
                <HistoricalMovementDialog
                    fineId={multa.id}
                    onClose={cerrar}
                    allowBankCredit={can.linkBankCredit}
                    creditos={creditosDelBanco}
                />
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
            <h2 className="flex-1 text-sm font-semibold">{titulo}</h2>
            {children}
        </div>
    );
}
