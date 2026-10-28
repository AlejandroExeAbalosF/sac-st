import { Head, Link } from '@inertiajs/react';
import {
    FilePlus2,
    Paperclip,
    Pencil,
    Plus,
    TriangleAlert,
} from 'lucide-react';
import { useState } from 'react';
import PageHeader from '@/components/page-header';
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
import CaseSheets from '@/features/multas/components/case-sheets';
import { store as attachToDocument } from '@/routes/multas/documentos/adjuntos';
import { show as showCase } from '@/routes/multas/expedientes';
import { store as attachToCase } from '@/routes/multas/expedientes/adjuntos';
import { create as addFine } from '@/routes/multas/expedientes/multas';

type Expediente = App.Modules.Multas.Data.FineCaseData;
type Documento = App.Modules.Multas.Data.FineDocumentData;
type Multa = App.Modules.Multas.Data.FineCaseFineData;
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

                <section
                    className="space-y-4"
                    aria-labelledby="documentos-y-multas"
                >
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2
                            id="documentos-y-multas"
                            className="text-lg font-semibold"
                        >
                            Resoluciones, actas y multas
                        </h2>
                        {can.register && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    setDialogo({ tipo: 'documento' })
                                }
                            >
                                <FilePlus2 aria-hidden="true" /> Agregar
                                documento
                            </Button>
                        )}
                    </div>
                    <CaseSheets
                        fines={multas}
                        documents={expediente.documents}
                        addFine={
                            can.register
                                ? (resolucion, actas) =>
                                      addFine(expediente.routeKey, {
                                          query: {
                                              ...(resolucion !== null && {
                                                  resolucion,
                                              }),
                                              ...(actas.length > 0 && {
                                                  actas,
                                              }),
                                          },
                                      }).url
                                : undefined
                        }
                        attachments={adjuntosDeDocumentos}
                        can={can}
                        onAttach={(documento) =>
                            setDialogo({
                                tipo: 'adjuntar-documento',
                                documento,
                            })
                        }
                        onRevise={(documento) =>
                            setDialogo({
                                tipo: 'corregir-documento',
                                documento,
                            })
                        }
                    />
                </section>
            </div>

            {dialogo?.tipo === 'documento' && (
                <AddDocumentDialog
                    caseId={expediente.id}
                    documentos={expediente.documents}
                    onClose={cerrar}
                />
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
