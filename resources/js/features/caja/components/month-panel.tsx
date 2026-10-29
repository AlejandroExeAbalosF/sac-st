import { Link, router } from '@inertiajs/react';
import { Download, FileText } from 'lucide-react';
import { useState } from 'react';
import Money from '@/components/money';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import DocumentFrame, {
    HOJA_ALTO_A4,
} from '@/features/haberes/components/document-frame';
import { dateTime } from '@/lib/format';

type Panel = App.Modules.Ledger.Data.CashMonthPanelData;
type Documento = App.Modules.Ledger.Data.CashMonthDocumentData;

/**
 * Lo que el módulo de la caja agrega al mes: los papeles de su cierre
 * mensual, emitirlos y adónde seguir mirando el mes.
 *
 * El calendario lo dibuja sin saber qué es. Para Multas son las dos notas de
 * la rendición a la SAF, que se emiten con el mes cerrado: por eso están acá,
 * al lado del cierre, y no en una pantalla aparte.
 */
export default function MonthPanel({ panel }: { panel: Panel }) {
    const [viendo, setViendo] = useState<Documento | null>(null);
    const [emitiendo, setEmitiendo] = useState(false);
    const vigentes = panel.documents.filter((d) => d.current);
    const anteriores = panel.documents.filter((d) => !d.current);

    const emitir = (url: string) => {
        setEmitiendo(true);
        router.post(
            url,
            {},
            { preserveScroll: true, onFinish: () => setEmitiendo(false) },
        );
    };

    return (
        <section className="rounded-lg border bg-card">
            <header className="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3">
                <h2 className="text-sm font-semibold">{panel.title}</h2>
                {panel.emit && (
                    <Button
                        size="sm"
                        disabled={emitiendo}
                        onClick={() => emitir(panel.emit!.href)}
                    >
                        {emitiendo ? 'Generando…' : panel.emit.label}
                    </Button>
                )}
            </header>

            {vigentes.length === 0 ? (
                <p className="px-4 py-3 text-sm text-muted-foreground">
                    {panel.emptyText}
                </p>
            ) : (
                <ul className="divide-y">
                    {vigentes.map((d) => (
                        <FilaDeDocumento
                            key={`${d.label}-${d.version}`}
                            documento={d}
                            onVer={() => setViendo(d)}
                        />
                    ))}
                </ul>
            )}

            {anteriores.length > 0 && (
                <details className="border-t px-4 py-2 text-sm">
                    <summary className="cursor-pointer text-xs text-muted-foreground">
                        Versiones anteriores ({anteriores.length})
                    </summary>
                    <ul className="mt-1 divide-y">
                        {anteriores.map((d) => (
                            <FilaDeDocumento
                                key={`${d.label}-${d.version}`}
                                documento={d}
                                onVer={() => setViendo(d)}
                            />
                        ))}
                    </ul>
                </details>
            )}

            {panel.links.length > 0 && (
                <footer className="flex flex-wrap gap-x-4 gap-y-1 border-t px-4 py-2 text-sm">
                    {panel.links.map((l) => (
                        <Link
                            key={l.href}
                            href={l.href}
                            className="text-primary hover:underline"
                        >
                            {l.label}
                        </Link>
                    ))}
                </footer>
            )}

            {viendo && (
                <Dialog
                    open
                    onOpenChange={(abierto) => !abierto && setViendo(null)}
                >
                    <DialogContent className="sm:max-w-3xl">
                        <DialogHeader>
                            <DialogTitle>
                                {viendo.label} · versión {viendo.version}
                            </DialogTitle>
                        </DialogHeader>
                        <DocumentFrame
                            url={viendo.viewUrl}
                            titulo={`${panel.title}: ${viendo.label}`}
                            hojaAlto={HOJA_ALTO_A4}
                            className="w-full"
                            maxAlto="65vh"
                        />
                    </DialogContent>
                </Dialog>
            )}
        </section>
    );
}

function FilaDeDocumento({
    documento,
    onVer,
}: {
    documento: Documento;
    onVer: () => void;
}) {
    return (
        <li className="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-2.5 text-sm">
            <div className="min-w-0 flex-1">
                <p className="font-medium">
                    {documento.label} · <Money value={documento.total} />
                </p>
                <p className="text-xs text-muted-foreground">
                    {documento.detail} · versión {documento.version} · emitida
                    el {dateTime(documento.emittedAt)}
                    {documento.emittedBy && ` por ${documento.emittedBy}`}
                </p>
            </div>
            <span className="flex gap-2">
                <Button size="sm" variant="outline" onClick={onVer}>
                    <FileText className="size-4" />
                    Ver
                </Button>
                <Button size="sm" variant="outline" asChild>
                    <a href={documento.downloadUrl}>
                        <Download className="size-4" />
                        PDF
                    </a>
                </Button>
            </span>
        </li>
    );
}
