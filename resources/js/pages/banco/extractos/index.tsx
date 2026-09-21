import { Head, Link, router } from '@inertiajs/react';
import { ChevronRight, FileSpreadsheet, Upload } from 'lucide-react';
import PageHeader from '@/components/page-header';
import PaginationFooter from '@/components/pagination-footer';
import type { PaginationData } from '@/components/pagination-footer';
import StatusBadge from '@/components/status-badge';
import type { StatusTone } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { date, dateTime, money } from '@/lib/format';
import { create, show } from '@/routes/banco/extractos';

type Importacion = App.Modules.Banking.Data.StatementImportListItemData;

type Paginado<T> = PaginationData & {
    data: T[];
};

type Props = {
    imports: Paginado<Importacion>;
    canImport: boolean;
};

const TONO: Record<string, StatusTone> = {
    uploaded: 'neutral',
    parsing: 'progress',
    completed: 'done',
    failed: 'blocked',
};

const ETIQUETA: Record<string, string> = {
    uploaded: 'Cargado',
    parsing: 'Procesando',
    completed: 'Importado',
    failed: 'Rechazado',
};

const FORMATO: Record<string, string> = {
    macro_online_csv: 'CSV',
    macro_excel: 'Excel',
};

/**
 * Historial de importaciones.
 *
 * Incluye las rechazadas, y eso es deliberado: que alguien haya intentado
 * subir el extracto de otra cuenta un martes a las nueve es información, y
 * esconderla dejaría la lista prolija y la trazabilidad incompleta.
 */
export default function ExtractosIndex({ imports, canImport }: Props) {
    return (
        <>
            <Head title="Extractos bancarios" />

            <div className="space-y-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Banco"
                    title="Extractos importados"
                    description="Cada archivo descargado del banco, con lo que trajo y lo que ya estaba."
                    actions={
                        canImport ? (
                            <Button asChild>
                                <Link href={create()}>
                                    <Upload className="size-4" />
                                    Importar extracto
                                </Link>
                            </Button>
                        ) : null
                    }
                />

                {imports.data.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-10 text-center">
                        <FileSpreadsheet className="mx-auto size-8 text-muted-foreground" />
                        <p className="mt-3 font-medium">
                            Todavía no se importó ningún extracto.
                        </p>
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3 font-medium text-field-label">
                                        Archivo
                                    </th>
                                    <th className="p-3 font-medium text-field-label">
                                        Cuenta
                                    </th>
                                    <th className="p-3 font-medium text-field-label">
                                        Período
                                    </th>
                                    <th className="p-3 text-right font-medium text-field-label">
                                        Nuevos
                                    </th>
                                    <th className="p-3 text-right font-medium text-field-label">
                                        Repetidos
                                    </th>
                                    <th className="p-3 text-right font-medium text-field-label">
                                        Saldo final
                                    </th>
                                    <th className="p-3 font-medium text-field-label">
                                        Estado
                                    </th>
                                    <th className="p-3">
                                        <span className="sr-only">
                                            Ver el detalle
                                        </span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {imports.data.map((item) => (
                                    <tr
                                        key={item.id}
                                        /*
                                         * La fila entera navega. El fondo al
                                         * pasar el mouse ya prometía que se
                                         * podía hacer clic, pero solo
                                         * funcionaba sobre el nombre del
                                         * archivo: una promesa a medias es
                                         * peor que ninguna. El enlace real
                                         * sigue estando —es lo que hace
                                         * accesible la fila con el teclado—.
                                         */
                                        onClick={() =>
                                            router.visit(show(item.id).url)
                                        }
                                        className="cursor-pointer border-t transition-colors hover:bg-muted/40"
                                    >
                                        <td className="p-3">
                                            <Link
                                                href={show(item.id)}
                                                className="font-medium hover:underline"
                                                onClick={(e) =>
                                                    e.stopPropagation()
                                                }
                                            >
                                                {item.originalFilename}
                                            </Link>
                                            <div className="text-xs text-muted-foreground">
                                                {FORMATO[item.sourceFormat] ??
                                                    item.sourceFormat}
                                                {' · '}
                                                {item.importedAt
                                                    ? dateTime(item.importedAt)
                                                    : 'sin importar'}
                                                {' · '}
                                                {item.importedBy}
                                            </div>
                                        </td>
                                        <td className="p-3">
                                            {item.accountLabel}
                                        </td>
                                        <td className="p-3 whitespace-nowrap">
                                            {item.periodFrom && item.periodTo
                                                ? `${date(item.periodFrom)} — ${date(item.periodTo)}`
                                                : '—'}
                                        </td>
                                        <td className="p-3 text-right tabular-nums">
                                            {item.rowsNew}
                                        </td>
                                        <td className="p-3 text-right text-muted-foreground tabular-nums">
                                            {item.rowsDuplicate}
                                        </td>
                                        <td className="p-3 text-right tabular-nums">
                                            {money(item.closingBalance)}
                                        </td>
                                        <td className="p-3">
                                            <StatusBadge
                                                label={
                                                    ETIQUETA[item.status] ??
                                                    item.status
                                                }
                                                tone={
                                                    TONO[item.status] ??
                                                    'neutral'
                                                }
                                            />
                                            {item.failureReason && (
                                                <p className="mt-1 max-w-xs text-xs text-muted-foreground">
                                                    {item.failureReason}
                                                </p>
                                            )}
                                        </td>
                                        <td className="p-3 text-right">
                                            <ChevronRight className="ml-auto size-4 text-muted-foreground" />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {imports.total > 0 && (
                    <PaginationFooter
                        pagination={imports}
                        singular="extracto"
                        plural="extractos"
                    />
                )}
            </div>
        </>
    );
}
