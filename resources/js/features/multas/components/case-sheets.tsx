import { Link } from '@inertiajs/react';
import { ArrowUpRight, Paperclip, Plus } from 'lucide-react';
import type { ReactNode } from 'react';
import Money from '@/components/money';
import StatusBadge from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { caseSheets } from '@/features/multas/case-sheets';
import {
    DEBT_STATUS,
    DOCUMENT_KIND,
    DOCUMENT_ROLE,
    partialDate,
} from '@/features/multas/labels';
import { AttachmentList } from './attachments';

type Documento = App.Modules.Multas.Data.FineDocumentData;
type Multa = App.Modules.Multas.Data.FineCaseFineData;
type Adjunto = App.Modules.Multas.Data.FineAttachmentData;

type DocumentControls = {
    attachments: Record<number, Adjunto[] | undefined>;
    can: { register: boolean; adjust: boolean; viewAttachments: boolean };
    onAttach: (document: Documento) => void;
    onRevise: (document: Documento) => void;
};

/**
 * «Agregar multa» con esos documentos marcados. Ausente si quien mira no
 * puede cargar multas: no se ofrece un botón que termine en un 403.
 */
type AddFine = (resolutionId: number | null, actIds: number[]) => string;

/**
 * Una multa con estos papeles: el caso de dos sancionados por la misma
 * inspección, o de un papel que llegó antes que la multa. Va junto a
 * Adjuntar y Corregir, como otra acción sobre el documento. Los documentos
 * llegan marcados, pero el formulario deja cambiarlos —una segunda multa
 * suele compartir el acta y traer su propia resolución—.
 */
type NewFine = { href: string; tooltip: string };

function AddFineButton({ href, tooltip }: NewFine) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Button asChild size="sm" variant="ghost" aria-label={tooltip}>
                    <Link href={href}>
                        <Plus aria-hidden="true" />
                    </Link>
                </Button>
            </TooltipTrigger>
            <TooltipContent>{tooltip}</TooltipContent>
        </Tooltip>
    );
}

/** El pliegue es decorativo; el contenido conserva todo el ancho disponible. */
function Paper({ children, label }: { children: ReactNode; label: string }) {
    return (
        <section
            aria-label={label}
            className="relative min-w-0 rounded-sm border bg-card shadow-raised"
        >
            <span
                aria-hidden="true"
                className="pointer-events-none absolute top-0 right-0 size-5 border-b border-l bg-muted shadow-sm [clip-path:polygon(0_0,100%_100%,0_100%)]"
            />
            {children}
        </section>
    );
}

function DocumentHeader({
    document,
    newFine,
    attachments,
    can,
    onAttach,
    onRevise,
}: DocumentControls & { document: Documento; newFine?: NewFine }) {
    const files = attachments[document.id] ?? [];

    return (
        <div className="min-w-0 space-y-3 p-4 sm:p-5">
            <div className="flex flex-wrap items-start justify-between gap-3 pr-3">
                <div className="min-w-0 space-y-1">
                    <p className="text-xs text-muted-foreground">
                        {DOCUMENT_KIND[document.kind]} ·{' '}
                        {partialDate(
                            document.issuedOn,
                            document.issuedPrecision,
                        )}
                    </p>
                    <h3 className="text-base font-semibold wrap-anywhere">
                        {document.label}
                    </h3>
                    {document.originalText !== document.label && (
                        <p className="text-xs wrap-anywhere text-muted-foreground">
                            En el papel: {document.originalText}
                        </p>
                    )}
                </div>
                <div className="flex flex-wrap gap-1">
                    {can.register && (
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => onAttach(document)}
                            aria-label={`Adjuntar archivo a ${document.label}`}
                        >
                            <Paperclip aria-hidden="true" /> Adjuntar
                        </Button>
                    )}
                    {(can.register || can.adjust) && (
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => onRevise(document)}
                            aria-label={`Corregir ${document.label}`}
                        >
                            Corregir
                        </Button>
                    )}
                    {newFine && <AddFineButton {...newFine} />}
                </div>
            </div>
            {files.length > 0 ? (
                <AttachmentList
                    adjuntos={files}
                    canView={can.viewAttachments}
                />
            ) : (
                <p className="text-xs text-muted-foreground">
                    Sin archivos adjuntos.
                </p>
            )}
        </div>
    );
}

function FineCard({ entry, ...controls }: DocumentControls & { entry: Multa }) {
    const { fine, number, documents } = entry;
    const status = DEBT_STATUS[fine.balance.status];
    // Los papeles de origen ya están en las cabeceras. Un mismo documento
    // puede además ratificar o modificar esta multa: ese papel se conserva.
    const additional = documents.filter(
        (document) =>
            document.kind === 'other' ||
            document.roles.some((role) => role !== 'origin'),
    );

    return (
        <article className="min-w-0 rounded-md border bg-background p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-xs font-medium text-muted-foreground">
                    Multa {number}
                </p>
                <StatusBadge label={status.label} tone={status.tone} />
            </div>
            <Link
                href={fine.href}
                className="mt-2 inline-flex max-w-full items-start gap-2 text-base font-semibold hover:underline"
            >
                <span className="wrap-anywhere">{fine.caption}</span>
                <ArrowUpRight
                    className="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                <span className="sr-only"> — Ver multa {number}</span>
            </Link>
            <dl className="mt-4 flex flex-wrap justify-between gap-x-6 gap-y-3 border-t pt-3 text-sm">
                <div>
                    <dt className="text-xs text-muted-foreground">
                        Obligación
                    </dt>
                    <dd className="mt-1">
                        <Money value={fine.balance.obligation} />
                    </dd>
                </div>
                <div>
                    <dt className="text-xs text-muted-foreground">
                        Saldo pendiente
                    </dt>
                    <dd className="mt-1 font-semibold">
                        <Money value={fine.balance.debt} />
                    </dd>
                </div>
            </dl>
            {additional.length > 0 && (
                <div className="mt-4 border-t pt-3">
                    <p className="text-xs font-medium text-muted-foreground">
                        Otros vínculos documentales de esta multa
                    </p>
                    {additional.map((document) => (
                        <div
                            key={document.id}
                            className="mt-3 border-t first:border-t-0"
                        >
                            <p className="pt-3 text-xs font-medium">
                                {document.roles
                                    .map((role) => DOCUMENT_ROLE[role])
                                    .join(' · ')}
                            </p>
                            <DocumentHeader document={document} {...controls} />
                        </div>
                    ))}
                </div>
            )}
        </article>
    );
}

export default function CaseSheets({
    fines,
    documents,
    addFine,
    ...controls
}: DocumentControls & {
    fines: Multa[];
    documents: Documento[];
    addFine?: AddFine;
}) {
    const { resolutions, unlinked } = caseSheets(fines, documents);

    return (
        <div className="space-y-6">
            {fines.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    Todavía no tiene multas.
                </p>
            )}
            <div className="grid items-start gap-6 2xl:grid-cols-2">
                {resolutions.map((resolution) => (
                    <Paper
                        key={resolution.key}
                        label={
                            resolution.documents
                                .map(({ label }) => label)
                                .join(' · ') ||
                            'Multas sin resolución de origen vinculada'
                        }
                    >
                        <div className="divide-y">
                            {resolution.documents.length > 1 && (
                                <p className="px-5 pt-5 text-sm font-medium">
                                    Resoluciones de origen
                                </p>
                            )}
                            {resolution.documents.map((document) => (
                                <DocumentHeader
                                    key={document.id}
                                    document={document}
                                    newFine={
                                        addFine &&
                                        resolution.documents.length === 1
                                            ? {
                                                  href: addFine(
                                                      document.id,
                                                      [],
                                                  ),
                                                  tooltip:
                                                      'Agregar una multa con esta resolución',
                                              }
                                            : undefined
                                    }
                                    {...controls}
                                />
                            ))}
                            {resolution.documents.length === 0 && (
                                <h3 className="p-5 font-semibold">
                                    Sin resolución de origen vinculada
                                </h3>
                            )}
                        </div>
                        <div className="space-y-4 border-t bg-muted/30 p-3 sm:p-5">
                            {resolution.acts.map((act) => {
                                const cards = (
                                    <div className="space-y-3">
                                        {act.fines.map((entry) => (
                                            <FineCard
                                                key={entry.fine.id}
                                                entry={entry}
                                                {...controls}
                                            />
                                        ))}
                                    </div>
                                );

                                return act.documents.length > 0 ? (
                                    <Paper
                                        key={act.key}
                                        label={act.documents
                                            .map(({ label }) => label)
                                            .join(' · ')}
                                    >
                                        <div className="divide-y">
                                            {act.documents.length > 1 && (
                                                <p className="px-5 pt-5 text-sm font-medium">
                                                    Actas de origen
                                                </p>
                                            )}
                                            {act.documents.map(
                                                (document, index) => (
                                                    <DocumentHeader
                                                        key={document.id}
                                                        document={document}
                                                        newFine={
                                                            // Una vez por grupo; con varias resoluciones de origen no se elige una por el operador.
                                                            addFine &&
                                                            index === 0
                                                                ? {
                                                                      href: addFine(
                                                                          resolution
                                                                              .documents
                                                                              .length ===
                                                                              1
                                                                              ? resolution
                                                                                    .documents[0]
                                                                                    .id
                                                                              : null,
                                                                          act.documents.map(
                                                                              ({
                                                                                  id,
                                                                              }) =>
                                                                                  id,
                                                                          ),
                                                                      ),
                                                                      tooltip:
                                                                          act
                                                                              .documents
                                                                              .length >
                                                                          1
                                                                              ? 'Agregar una multa con esta resolución y estas actas'
                                                                              : 'Agregar una multa con esta resolución y esta acta',
                                                                  }
                                                                : undefined
                                                        }
                                                        {...controls}
                                                    />
                                                ),
                                            )}
                                        </div>
                                        <div className="border-t bg-muted/20 p-3 sm:p-4">
                                            {cards}
                                        </div>
                                    </Paper>
                                ) : (
                                    <div key={act.key} className="space-y-3">
                                        <p className="text-xs text-muted-foreground">
                                            Sin actas de origen vinculadas
                                        </p>
                                        {cards}
                                    </div>
                                );
                            })}
                        </div>
                    </Paper>
                ))}
            </div>
            {unlinked.length > 0 && (
                <section className="space-y-3">
                    <h2 className="text-sm font-semibold">
                        Documentos sin multas vinculadas
                    </h2>
                    <div className="grid items-start gap-4 2xl:grid-cols-2">
                        {unlinked.map((document) => (
                            <Paper key={document.id} label={document.label}>
                                <DocumentHeader
                                    document={document}
                                    newFine={
                                        addFine && document.kind !== 'other'
                                            ? {
                                                  href:
                                                      document.kind ===
                                                      'resolution'
                                                          ? addFine(
                                                                document.id,
                                                                [],
                                                            )
                                                          : addFine(null, [
                                                                document.id,
                                                            ]),
                                                  tooltip:
                                                      'Agregar una multa con este documento',
                                              }
                                            : undefined
                                    }
                                    {...controls}
                                />
                            </Paper>
                        ))}
                    </div>
                </section>
            )}
        </div>
    );
}
