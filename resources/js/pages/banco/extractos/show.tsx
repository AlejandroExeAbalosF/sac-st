import { Head, router } from '@inertiajs/react';
import { CopyCheck, Download, Link2, TriangleAlert, Undo2 } from 'lucide-react';
import { useState } from 'react';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import type { StatusTone } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { date, dateTime, money } from '@/lib/format';
import { cn } from '@/lib/utils';
import { download } from '@/routes/adjuntos';
import { destroy } from '@/routes/banco/extractos';

type Importacion = App.Modules.Banking.Data.StatementImportListItemData;
type Fila = App.Modules.Banking.Data.StatementPreviewRowData;

type Props = {
    import: Importacion;
    rows: Fila[];
    canRollback: boolean;
};

const TONO_FILA: Record<string, StatusTone> = {
    valid: 'done',
    warning: 'action',
    rejected: 'blocked',
};

const ETIQUETA_FILA: Record<string, string> = {
    valid: 'Interpretada',
    warning: 'Sin saldo',
    rejected: 'Rechazada',
};

const FORMATO: Record<string, string> = {
    macro_online_csv: 'MacroOnline (CSV)',
    macro_excel: 'MacroOnline (Excel)',
};

function pesoLegible(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    const kb = bytes / 1024;

    return kb < 1024 ? `${Math.round(kb)} KB` : `${(kb / 1024).toFixed(1)} MB`;
}

/**
 * Detalle de una importación.
 *
 * Muestra las filas tal como se interpretaron, incluidas las rechazadas.
 * Es la pantalla a la que se vuelve cuando un saldo no cuadra y hay que
 * averiguar qué entró y qué no.
 */
export default function ExtractoShow({
    import: importacion,
    rows,
    canRollback,
}: Props) {
    const [confirmando, setConfirmando] = useState(false);

    const revertir = () => {
        router.delete(destroy(importacion.id).url, {
            onFinish: () => setConfirmando(false),
        });
    };

    return (
        <>
            <Head title={importacion.originalFilename} />

            <div className="space-y-6 p-4 sm:p-6">
                <PageHeader
                    title={importacion.originalFilename}
                    description={`${importacion.accountLabel} · importado por ${importacion.importedBy}${
                        importacion.importedAt
                            ? ` el ${dateTime(importacion.importedAt)}`
                            : ''
                    }`}
                    actions={
                        canRollback && importacion.canBeRolledBack ? (
                            <Button
                                variant="outline"
                                onClick={() => setConfirmando(true)}
                            >
                                <Undo2 className="size-4" />
                                Revertir
                            </Button>
                        ) : null
                    }
                />

                {importacion.failureReason && (
                    <div className="rounded-md border border-current bg-destructive-soft p-3 text-sm text-destructive-strong">
                        {importacion.failureReason}
                    </div>
                )}

                {/*
                 * El salto quedó registrado al importar. Si más adelante
                 * una cuota no aparece financiada, esto explica por dónde
                 * buscar.
                 */}
                {importacion.continuityWarning && (
                    <div className="flex items-start gap-2 rounded-md border bg-warning-soft/50 p-3 text-sm text-warning-strong">
                        <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                        <span>{importacion.continuityWarning}</span>
                    </div>
                )}

                {/* Qué trajo el archivo. */}
                <dl className="grid grid-cols-2 gap-4 rounded-lg border p-4 sm:grid-cols-4">
                    <Dato titulo="Filas" valor={importacion.rowsTotal} />
                    <Dato
                        titulo="Movimientos nuevos"
                        valor={importacion.rowsNew}
                    />
                    <Dato
                        titulo="Ya registrados"
                        valor={importacion.rowsDuplicate}
                        pista="Vinieron también en otro extracto."
                    />
                    <Dato
                        titulo="Rechazadas"
                        valor={importacion.rowsRejected}
                    />
                    <Dato
                        titulo="Período"
                        valor={
                            importacion.periodFrom && importacion.periodTo
                                ? `${date(importacion.periodFrom)} — ${date(importacion.periodTo)}`
                                : '—'
                        }
                    />
                    <Dato
                        titulo="Saldo previo"
                        valor={money(importacion.openingBalance)}
                        pista={
                            importacion.periodFrom
                                ? `antes del ${date(importacion.periodFrom)}`
                                : undefined
                        }
                    />
                    <Dato
                        titulo="Saldo en el banco"
                        valor={money(importacion.closingBalance)}
                        pista={
                            importacion.periodTo
                                ? `al ${date(importacion.periodTo)}`
                                : undefined
                        }
                    />
                    <div>
                        <dt className="text-xs font-medium text-field-label">
                            Cadena de saldos
                        </dt>
                        <dd className="mt-0.5">
                            {importacion.balanceChainOk === null ? (
                                <StatusBadge
                                    label="No verificable"
                                    tone="action"
                                />
                            ) : (
                                <StatusBadge
                                    label={
                                        importacion.balanceChainOk
                                            ? 'Cierra'
                                            : 'No cierra'
                                    }
                                    tone={
                                        importacion.balanceChainOk
                                            ? 'done'
                                            : 'blocked'
                                    }
                                />
                            )}
                        </dd>
                    </div>
                </dl>

                {/*
                 * De dónde salió el archivo.
                 *
                 * Estos datos se guardaban desde la primera importación y no
                 * se veían en ninguna pantalla. Son los que responden «¿este
                 * extracto era de esta cuenta?» cuando un saldo no cuadra, y
                 * los únicos que explican un rechazo por cuenta o moneda.
                 */}
                <details className="rounded-lg border">
                    <summary className="cursor-pointer p-4 text-sm font-medium">
                        Procedencia del archivo
                    </summary>
                    <dl className="grid grid-cols-2 gap-4 border-t p-4 sm:grid-cols-4">
                        <Dato
                            titulo="Formato"
                            valor={
                                FORMATO[importacion.sourceFormat] ??
                                importacion.sourceFormat
                            }
                        />
                        <Dato
                            titulo="Cuenta declarada"
                            valor={
                                importacion.accountNumberInFile ??
                                'No informada'
                            }
                            pista={
                                importacion.accountNumberInFile
                                    ? undefined
                                    : 'El CSV no la trae.'
                            }
                        />
                        <Dato
                            titulo="Moneda declarada"
                            valor={importacion.currencyInFile ?? 'No informada'}
                        />
                        <Dato
                            titulo="Descargado del banco"
                            valor={
                                importacion.downloadedAt
                                    ? dateTime(importacion.downloadedAt)
                                    : '—'
                            }
                            pista={importacion.operatorInFile ?? undefined}
                        />
                        <div>
                            <dt className="text-xs font-medium text-field-label">
                                Archivo original
                            </dt>
                            <dd className="mt-0.5">
                                {importacion.attachmentId ? (
                                    <a
                                        href={
                                            download(importacion.attachmentId)
                                                .url
                                        }
                                        className="inline-flex items-center gap-1.5 text-sm hover:underline"
                                    >
                                        <Download className="size-3.5" />
                                        {pesoLegible(importacion.fileSize)}
                                    </a>
                                ) : (
                                    <span className="text-sm text-muted-foreground">
                                        No disponible
                                    </span>
                                )}
                            </dd>
                        </div>
                        <div className="sm:col-span-3">
                            <dt className="text-xs font-medium text-field-label">
                                Huella del archivo
                            </dt>
                            <dd className="mt-0.5 font-mono text-xs break-all text-muted-foreground">
                                {importacion.fileSha256}
                            </dd>
                        </div>
                    </dl>
                </details>

                {rows.length > 0 && (
                    <div className="overflow-x-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-2 font-medium text-field-label">
                                        #
                                    </th>
                                    <th className="p-2 font-medium text-field-label">
                                        Fecha
                                    </th>
                                    <th className="p-2 font-medium text-field-label">
                                        Referencia
                                    </th>
                                    <th className="p-2 font-medium text-field-label">
                                        Causal
                                    </th>
                                    <th className="p-2 font-medium text-field-label">
                                        Concepto
                                    </th>
                                    <th className="p-2 text-right font-medium text-field-label">
                                        Importe
                                    </th>
                                    <th className="p-2 text-right font-medium text-field-label">
                                        Saldo
                                    </th>
                                    <th className="p-2 font-medium text-field-label">
                                        Estado
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((fila) => (
                                    <tr
                                        key={fila.rowNumber}
                                        /*
                                         * La fila atenuada trajo un
                                         * movimiento que el sistema ya
                                         * tenía: se guardó como evidencia,
                                         * pero no creó nada nuevo.
                                         */
                                        className={cn(
                                            'border-t',
                                            fila.repeated && 'bg-muted/40',
                                        )}
                                    >
                                        <td className="p-2 text-muted-foreground tabular-nums">
                                            {fila.rowNumber}
                                        </td>
                                        <td className="p-2 whitespace-nowrap">
                                            {fila.transactionDate
                                                ? date(fila.transactionDate)
                                                : '—'}
                                        </td>
                                        <td className="p-2 tabular-nums">
                                            {fila.operationId ?? '—'}
                                        </td>
                                        <td className="p-2 tabular-nums">
                                            {fila.causalCode ?? '—'}
                                        </td>
                                        <td className="p-2">
                                            <span className="block max-w-[26rem] truncate">
                                                {fila.description ??
                                                    fila.errorMessage ??
                                                    '—'}
                                            </span>
                                            {fila.counterpartyIdentifier && (
                                                <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                                                    <Link2 className="size-3" />
                                                    CUIT{' '}
                                                    {
                                                        fila.counterpartyIdentifier
                                                    }
                                                </span>
                                            )}
                                        </td>
                                        <td className="p-2 text-right tabular-nums">
                                            {fila.direction === 'debit'
                                                ? '−'
                                                : ''}
                                            {money(fila.amount)}
                                        </td>
                                        <td className="p-2 text-right text-muted-foreground tabular-nums">
                                            {money(fila.balanceAfter)}
                                        </td>
                                        <td className="p-2">
                                            {fila.repeated ? (
                                                <span className="inline-flex items-center gap-1 text-xs whitespace-nowrap text-muted-foreground">
                                                    <CopyCheck className="size-3.5 shrink-0" />
                                                    Ya registrado
                                                </span>
                                            ) : (
                                                <StatusBadge
                                                    label={
                                                        ETIQUETA_FILA[
                                                            fila.status
                                                        ] ?? fila.status
                                                    }
                                                    tone={
                                                        TONO_FILA[
                                                            fila.status
                                                        ] ?? 'neutral'
                                                    }
                                                />
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>

            <Dialog open={confirmando} onOpenChange={setConfirmando}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Revertir esta importación</DialogTitle>
                        <DialogDescription>
                            Se borran las {importacion.rowsTotal} filas del
                            archivo y los {importacion.rowsNew} movimientos que
                            nacieron con él. Los que ya venían de otra
                            importación se conservan. Si alguno fue conciliado o
                            dejado fuera del circuito, la reversión se rechaza.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setConfirmando(false)}
                        >
                            Cancelar
                        </Button>
                        <Button variant="destructive" onClick={revertir}>
                            Revertir
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function Dato({
    titulo,
    valor,
    pista,
}: {
    titulo: string;
    valor: string | number;
    pista?: string;
}) {
    return (
        <div>
            <dt className="text-xs font-medium text-field-label">{titulo}</dt>
            <dd className="mt-0.5 tabular-nums">{valor}</dd>
            {pista && (
                <p className="mt-0.5 text-xs text-muted-foreground">{pista}</p>
            )}
        </div>
    );
}
