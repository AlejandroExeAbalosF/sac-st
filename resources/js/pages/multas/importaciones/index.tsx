import { Head, Link, useForm } from '@inertiajs/react';
import { ChevronRight, FileSpreadsheet, Upload } from 'lucide-react';
import InputError from '@/components/input-error';
import PageHeader from '@/components/page-header';
import PaginationFooter from '@/components/pagination-footer';
import type { PaginationData } from '@/components/pagination-footer';
import StatusBadge from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { IMPORT_STATUS } from '@/features/multas/labels';
import { date, dateTime } from '@/lib/format';
import { show, store } from '@/routes/multas/importaciones';

type Lote = App.Modules.Multas.Data.FineImportBatchData;

type Props = {
    lotes: PaginationData & { data: Lote[] };
    aperturaHecha: boolean;
};

/**
 * La planilla histórica de Multas: los lotes subidos y la subida.
 *
 * Subir solo lee: las multas recién se crean al consolidar, después de revisar.
 */
export default function ImportacionesIndex({ lotes, aperturaHecha }: Props) {
    const { setData, post, processing, errors, progress } = useForm({
        file: null as File | null,
    });

    return (
        <>
            <Head title="Importar planilla de Multas" />

            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Multas · Expedientes"
                    title="Importar la planilla histórica"
                    description="La cuenta corriente de Multas en Excel. Se lee hoja por hoja; las multas se crean recién al consolidar, después de revisar."
                />

                <section className="rounded-lg border p-4 sm:p-5">
                    {aperturaHecha ? (
                        <form
                            className="flex flex-col gap-3 sm:flex-row sm:items-end"
                            onSubmit={(e) => {
                                e.preventDefault();
                                post(store().url, { forceFormData: true });
                            }}
                        >
                            <div className="min-w-0 flex-1">
                                <Label htmlFor="planilla">Planilla</Label>
                                <Input
                                    id="planilla"
                                    type="file"
                                    accept=".xls,.xlsx"
                                    className="mt-1"
                                    onChange={(e) =>
                                        setData(
                                            'file',
                                            e.target.files?.[0] ?? null,
                                        )
                                    }
                                    aria-invalid={Boolean(errors.file)}
                                />
                                <InputError
                                    className="mt-1"
                                    message={errors.file}
                                />
                            </div>
                            <Button type="submit" disabled={processing}>
                                <Upload className="size-4" aria-hidden="true" />
                                {progress
                                    ? `Subiendo ${progress.percentage ?? 0} %`
                                    : 'Subir y leer'}
                            </Button>
                        </form>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Primero hay que abrir la caja de Multas: su apertura
                            es el corte de la importación.
                        </p>
                    )}
                </section>

                {lotes.data.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-10 text-center">
                        <FileSpreadsheet
                            className="mx-auto size-8 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <p className="mt-3 font-medium">
                            Todavía no se subió ninguna planilla.
                        </p>
                    </div>
                ) : (
                    <ul className="divide-y rounded-lg border">
                        {lotes.data.map((lote) => {
                            const estado = IMPORT_STATUS[lote.status];

                            return (
                                <li key={lote.id}>
                                    <Link
                                        href={show(lote.id)}
                                        className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 text-sm hover:bg-muted/50"
                                    >
                                        <span className="font-medium">
                                            N.º {lote.id}
                                        </span>
                                        <span className="min-w-0 flex-1 truncate">
                                            {lote.filename}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            Corte {date(lote.cutoffMonth)}
                                            {lote.createdAt &&
                                                ` · ${dateTime(lote.createdAt)}`}
                                            {lote.uploadedByName &&
                                                ` · ${lote.uploadedByName}`}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {lote.counts.rows} filas ·{' '}
                                            {lote.counts.consolidated}{' '}
                                            consolidadas · {lote.counts.blocked}{' '}
                                            para revisar
                                        </span>
                                        <StatusBadge
                                            label={estado.label}
                                            tone={estado.tone}
                                        />
                                        <ChevronRight
                                            className="size-4 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                )}

                <PaginationFooter
                    pagination={lotes}
                    singular="lote"
                    plural="lotes"
                />
            </div>
        </>
    );
}
