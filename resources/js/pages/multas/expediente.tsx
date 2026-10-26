import { Head, Link } from '@inertiajs/react';
import {
    FilePlus2,
    Paperclip,
    Pencil,
    Plus,
    TriangleAlert,
} from 'lucide-react';
import { useState } from 'react';
import Money from '@/components/money';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    AttachFileDialog,
    AttachmentList,
} from '@/features/multas/components/attachments';
import {
    AddDocumentDialog,
    ReviseCaseDialog,
    ReviseDocumentDialog,
} from '@/features/multas/components/case-dialogs';
import {
    DEBT_STATUS,
    DOCUMENT_KIND,
    partialDate,
} from '@/features/multas/labels';
import { store as attachToDocument } from '@/routes/multas/documentos/adjuntos';
import { show as showCase } from '@/routes/multas/expedientes';
import { store as attachToCase } from '@/routes/multas/expedientes/adjuntos';
import { create as addFine } from '@/routes/multas/expedientes/multas';

type Expediente = App.Modules.Multas.Data.FineCaseData;
type Documento = App.Modules.Multas.Data.FineDocumentData;
type Multa = App.Modules.Multas.Data.FineListItemData;
type Adjunto = App.Modules.Multas.Data.FineAttachmentData;

type Props = {
    expediente: Expediente;
    multas: Multa[];
    adjuntos: Adjunto[];
    /** La foto de cada resolución y de cada acta, por id del documento. */
    adjuntosDeDocumentos: Record<number, Adjunto[] | undefined>;
    can: { register: boolean; adjust: boolean; viewAttachments: boolean };
};

type Dialogo =
    | { tipo: 'documento' }
    | { tipo: 'expediente' }
    | { tipo: 'adjuntar' }
    | { tipo: 'adjuntar-documento'; documento: Documento }
    | { tipo: 'corregir-documento'; documento: Documento };

/**
 * El expediente de Multas: sus documentos y las multas que originó.
 *
 * Un expediente puede tener varias multas —dos sancionados por la misma
 * acta— y sus documentos se cargan una vez y se comparten.
 */
export default function ExpedienteDeMultas({
    expediente,
    multas,
    adjuntos,
    adjuntosDeDocumentos,
    can,
}: Props) {
    const [dialogo, setDialogo] = useState<Dialogo | null>(null);
    const cerrar = () => setDialogo(null);

    return (
        <>
            <Head title={`Expte. ${expediente.label}`} />

            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title={`Expediente ${expediente.label}`}
                    eyebrow="Multas"
                    description={
                        <>
                            En el papel:{' '}
                            <span className="font-mono">
                                {expediente.originalText}
                            </span>
                            {expediente.provenance === 'import' &&
                                ' · importado del Excel histórico'}
                        </>
                    }
                    actions={
                        <>
                            {/* Completar es de quien carga; corregir, de quien ajusta. */}
                            {(can.register || can.adjust) && (
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        setDialogo({ tipo: 'expediente' })
                                    }
                                >
                                    <Pencil
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    Corregir número
                                </Button>
                            )}
                            {can.register && (
                                <Button asChild>
                                    <Link href={addFine(expediente.routeKey)}>
                                        <Plus
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                        Agregar multa
                                    </Link>
                                </Button>
                            )}
                        </>
                    }
                />

                {expediente.similar.length > 0 && (
                    <p className="flex gap-2 rounded-md border border-warning-strong/30 bg-warning-soft p-3 text-sm text-warning-strong">
                        <TriangleAlert
                            className="size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span>
                            Hay otro expediente con el mismo número y año y
                            distinto sufijo:{' '}
                            {expediente.similar.map((otro, i) => (
                                <span key={otro.id}>
                                    {i > 0 && ', '}
                                    <Link
                                        href={showCase(otro.routeKey)}
                                        className="font-mono underline"
                                    >
                                        {otro.label}
                                    </Link>
                                </span>
                            ))}
                            . No se fusionan solos: revisá si son el mismo
                            trámite.
                        </span>
                    </p>
                )}

                {expediente.notes && (
                    <p className="rounded-lg border bg-card p-4 text-sm shadow-raised">
                        {expediente.notes}
                    </p>
                )}

                <section className="rounded-lg border bg-card shadow-raised">
                    <div className="flex items-center justify-between gap-4 border-b px-5 py-3">
                        <h2 className="text-sm font-semibold">
                            Archivos del expediente
                        </h2>
                        {can.register && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setDialogo({ tipo: 'adjuntar' })}
                            >
                                <Paperclip aria-hidden="true" />
                                Adjuntar al expediente
                            </Button>
                        )}
                    </div>
                    <div className="px-5 py-3">
                        {adjuntos.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Sin archivos. Acá va la carátula u otro papel
                                del expediente.
                            </p>
                        ) : (
                            <AttachmentList
                                adjuntos={adjuntos}
                                canView={can.viewAttachments}
                            />
                        )}
                    </div>
                </section>

                <section className="rounded-lg border bg-card shadow-raised">
                    <div className="flex items-center justify-between gap-4 border-b px-5 py-3">
                        <h2 className="text-sm font-semibold">Documentos</h2>
                        {can.register && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    setDialogo({ tipo: 'documento' })
                                }
                            >
                                <FilePlus2 aria-hidden="true" />
                                Agregar documento
                            </Button>
                        )}
                    </div>
                    {expediente.documents.length === 0 ? (
                        <p className="px-5 py-4 text-sm text-muted-foreground">
                            Sin documentos cargados.
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {expediente.documents.map((documento) => (
                                <li
                                    key={documento.id}
                                    className="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 text-sm"
                                >
                                    <StatusBadge
                                        label={DOCUMENT_KIND[documento.kind]}
                                    />
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
                                            >
                                                Corregir
                                            </Button>
                                        )}
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
                    )}
                </section>

                <section className="rounded-lg border bg-card shadow-raised">
                    <h2 className="border-b px-5 py-3 text-sm font-semibold">
                        Multas
                    </h2>
                    {multas.length === 0 ? (
                        <p className="px-5 py-4 text-sm text-muted-foreground">
                            Todavía no tiene multas.
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {multas.map((multa) => {
                                const estado =
                                    DEBT_STATUS[multa.balance.status];

                                return (
                                    <li
                                        key={multa.id}
                                        className="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 text-sm"
                                    >
                                        <Link
                                            href={multa.href}
                                            className="font-medium hover:underline"
                                        >
                                            {multa.caption}
                                        </Link>
                                        <span className="text-xs text-muted-foreground">
                                            {multa.originDocuments.join(' · ')}
                                        </span>
                                        <span className="ml-auto flex items-center gap-3">
                                            <span className="text-xs text-muted-foreground">
                                                Saldo{' '}
                                                <Money
                                                    value={multa.balance.debt}
                                                />
                                            </span>
                                            <StatusBadge
                                                label={estado.label}
                                                tone={estado.tone}
                                            />
                                        </span>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </section>
            </div>

            {dialogo?.tipo === 'documento' && (
                <AddDocumentDialog caseId={expediente.id} onClose={cerrar} />
            )}
            {dialogo?.tipo === 'adjuntar' && (
                <AttachFileDialog
                    url={attachToCase(expediente.id).url}
                    title="Adjuntar un archivo al expediente"
                    description="La foto o el PDF de un papel del expediente que no es la resolución ni un acta: esos van en su documento. Lo ven todas sus multas."
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
            {dialogo?.tipo === 'expediente' && (
                <ReviseCaseDialog expediente={expediente} onClose={cerrar} />
            )}
            {dialogo?.tipo === 'corregir-documento' && (
                <ReviseDocumentDialog
                    documento={dialogo.documento}
                    onClose={cerrar}
                />
            )}
        </>
    );
}
