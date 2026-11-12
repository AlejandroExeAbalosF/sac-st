import { Head, Link, router, useForm, usePoll } from '@inertiajs/react';
import { FileSpreadsheet, Play } from 'lucide-react';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import Money from '@/components/money';
import PageHeader from '@/components/page-header';
import PaginationFooter from '@/components/pagination-footer';
import type { PaginationData } from '@/components/pagination-footer';
import StatusBadge from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { IMPORT_CELL_STATUS, IMPORT_STATUS } from '@/features/multas/labels';
import { date } from '@/lib/format';
import { cn } from '@/lib/utils';
import {
    consolidate,
    exportMethod,
    resolveInBulk,
    show,
} from '@/routes/multas/importaciones';
import { resolve as resolveCell } from '@/routes/multas/importaciones/celdas';
import { resolve as resolveRow } from '@/routes/multas/importaciones/filas';

type Lote = App.Modules.Multas.Data.FineImportBatchData;
type Fila = App.Modules.Multas.Data.FineImportRowData;
type Celda = App.Modules.Multas.Data.FineImportCellData;
type Problema = Fila['issues'][number];
type Pestana =
    | 'conciliacion'
    | 'revision'
    | 'pendientes'
    | 'completar'
    | 'importadas'
    | 'excluidas';

type Props = {
    lote: Lote;
    pestana: Pestana;
    filtros: { hoja: string; problema: string | null };
    filas: (PaginationData & { data: Fila[] }) | null;
    celdas: (PaginationData & { data: Celda[] }) | null;
    enBloque: {
        code: string;
        label: string;
        rows: number;
        actions: { value: string; label: string }[];
    }[];
    puedeConsolidar: boolean;
    problemas: { value: string; label: string; blocking: boolean }[];
};

const PESTANAS: { value: Pestana; label: string }[] = [
    { value: 'conciliacion', label: 'Conciliación' },
    { value: 'revision', label: 'Revisión' },
    { value: 'pendientes', label: 'Pendientes de identificar' },
    { value: 'completar', label: 'Para completar' },
    { value: 'importadas', label: 'Importadas' },
    { value: 'excluidas', label: 'Excluidas' },
];

const TODOS = '__todos__';

/**
 * Un lote de la planilla histórica: la conciliación por hoja, lo que hay que
 * decidir, los pagos posteriores a la apertura y lo importado. Mientras se lee
 * o se consolida, la pantalla se actualiza sola.
 */
export default function ImportacionShow({
    lote,
    pestana,
    filtros,
    filas,
    celdas,
    enBloque,
    puedeConsolidar,
    problemas,
}: Props) {
    const estado = IMPORT_STATUS[lote.status];
    const trabajando =
        lote.status === 'reading' || lote.status === 'consolidating';
    const { start, stop } = usePoll(3000, {}, { autoStart: false });

    useEffect(() => {
        if (trabajando) {
            start();
        } else {
            stop();
        }

        return stop;
    }, [trabajando, start, stop]);

    const ir = (cambios: Record<string, string | null>) =>
        router.get(
            show(lote.id).url,
            {
                pestana,
                hoja: filtros.hoja || null,
                problema: filtros.problema,
                ...cambios,
            },
            { preserveScroll: true, preserveState: true },
        );

    return (
        <>
            <Head title={`Importación N.º ${lote.id}`} />

            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Multas · Importar planilla"
                    title={`Importación N.º ${lote.id}`}
                    description={`${lote.filename} · corte en ${date(lote.cutoffMonth)}: lo pagado desde ese mes no se importa y queda para identificar.`}
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <a href={exportMethod(lote.id).url}>
                                    <FileSpreadsheet
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    Exportar revisión
                                </a>
                            </Button>
                            <Button
                                disabled={!puedeConsolidar || trabajando}
                                onClick={() =>
                                    router.post(
                                        consolidate(lote.id).url,
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <Play className="size-4" aria-hidden="true" />
                                Consolidar
                            </Button>
                        </>
                    }
                />

                <section className="flex flex-wrap items-center gap-x-5 gap-y-2 rounded-lg border p-4 text-sm">
                    <StatusBadge label={estado.label} tone={estado.tone} />
                    <Cuenta label="Filas" valor={lote.counts.rows} />
                    <Cuenta label="Para revisar" valor={lote.counts.blocked} />
                    <Cuenta
                        label="Listas para consolidar"
                        valor={lote.counts.ready}
                    />
                    <Cuenta
                        label="Consolidadas"
                        valor={lote.counts.consolidated}
                    />
                    <Cuenta label="Excluidas" valor={lote.counts.excluded} />
                    {lote.counts.unchanged > 0 && (
                        <Cuenta
                            label="Ya importadas sin cambios"
                            valor={lote.counts.unchanged}
                        />
                    )}
                    {lote.counts.failed > 0 && (
                        <Cuenta label="Con error" valor={lote.counts.failed} />
                    )}
                    <Cuenta
                        label="Pagos por identificar"
                        valor={lote.counts.pendingCells}
                    />
                    {lote.failure && (
                        <p className="w-full text-destructive">
                            {lote.failure}
                        </p>
                    )}
                </section>

                <nav
                    aria-label="Secciones del lote"
                    className="-mb-2 flex flex-wrap gap-1 border-b"
                >
                    {PESTANAS.map((p) => (
                        <button
                            key={p.value}
                            type="button"
                            onClick={() => ir({ pestana: p.value })}
                            className={cn(
                                '-mb-px border-b-2 px-3 py-2 text-sm',
                                pestana === p.value
                                    ? 'border-primary font-medium'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            )}
                            aria-current={
                                pestana === p.value ? 'page' : undefined
                            }
                        >
                            {p.label}
                        </button>
                    ))}
                </nav>

                {pestana === 'conciliacion' ? (
                    <Conciliacion lote={lote} />
                ) : (
                    <>
                        <Filtros
                            hojas={lote.reconciliation.map((h) =>
                                String(h.sheet),
                            )}
                            problemas={problemas}
                            filtros={filtros}
                            mostrarProblema={pestana !== 'pendientes'}
                            onChange={ir}
                        />

                        {pestana === 'revision' && enBloque.length > 0 && (
                            <EnBloque loteId={lote.id} bloques={enBloque} />
                        )}

                        {pestana === 'pendientes' && celdas && (
                            <Pendientes celdas={celdas} />
                        )}

                        {filas && (
                            <>
                                {filas.data.length === 0 ? (
                                    <p className="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">
                                        No hay filas en esta sección.
                                    </p>
                                ) : (
                                    <ul className="grid gap-3">
                                        {filas.data.map((fila) => (
                                            <FilaImportada
                                                key={fila.id}
                                                fila={fila}
                                                decidir={
                                                    pestana === 'revision' &&
                                                    !trabajando
                                                }
                                            />
                                        ))}
                                    </ul>
                                )}
                                <PaginationFooter
                                    pagination={filas}
                                    singular="fila"
                                    plural="filas"
                                />
                            </>
                        )}
                    </>
                )}
            </div>
        </>
    );
}

function Cuenta({ label, valor }: { label: string; valor: number }) {
    return (
        <span>
            <span className="text-muted-foreground">{label}:</span>{' '}
            <span className="font-medium tabular-nums">{valor}</span>
        </span>
    );
}

/** Lo que suma el detalle de cada hoja contra lo que dicen sus pies de control. */
function Conciliacion({ lote }: { lote: Lote }) {
    if (lote.reconciliation.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                La conciliación aparece al terminar de leer la planilla.
            </p>
        );
    }

    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full text-sm">
                <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                    <tr>
                        <th className="px-3 py-2">Hoja</th>
                        <th className="px-3 py-2 text-right">Filas</th>
                        <th className="px-3 py-2 text-right">Para revisar</th>
                        <th className="px-3 py-2 text-right">Importe</th>
                        <th className="px-3 py-2 text-right">Pagado</th>
                        <th className="px-3 py-2">Pies de control</th>
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {lote.reconciliation.map((hoja) => {
                        const pies = (hoja.footers ?? []) as {
                            row: number;
                            amount: string | null;
                            paid: string;
                            amount_matches: boolean;
                            paid_matches: boolean;
                        }[];

                        return (
                            <tr key={String(hoja.sheet)}>
                                <td className="px-3 py-2 font-medium">
                                    {String(hoja.sheet)}
                                </td>
                                <td className="px-3 py-2 text-right tabular-nums">
                                    {String(hoja.rows)}
                                </td>
                                <td className="px-3 py-2 text-right tabular-nums">
                                    {String(hoja.blocked)}
                                </td>
                                <td className="px-3 py-2 text-right">
                                    <Money value={String(hoja.amount)} />
                                </td>
                                <td className="px-3 py-2 text-right">
                                    <Money value={String(hoja.paid)} />
                                </td>
                                <td className="px-3 py-2 text-xs">
                                    {pies.length === 0
                                        ? 'Sin pie'
                                        : pies.map((pie) => (
                                              <span
                                                  key={pie.row}
                                                  className="mr-3 inline-block"
                                              >
                                                  Fila {pie.row}:{' '}
                                                  {pie.amount_matches &&
                                                  pie.paid_matches ? (
                                                      'coincide'
                                                  ) : (
                                                      <span className="text-destructive">
                                                          {pie.amount_matches
                                                              ? 'el importe coincide; lo pagado da '
                                                              : 'el importe da '}
                                                          <Money
                                                              value={
                                                                  pie.amount_matches
                                                                      ? pie.paid
                                                                      : (pie.amount ??
                                                                        '0.00')
                                                              }
                                                          />
                                                      </span>
                                                  )}
                                              </span>
                                          ))}
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

function Filtros({
    hojas,
    problemas,
    filtros,
    mostrarProblema,
    onChange,
}: {
    hojas: string[];
    problemas: Props['problemas'];
    filtros: Props['filtros'];
    mostrarProblema: boolean;
    onChange: (cambios: Record<string, string | null>) => void;
}) {
    return (
        <div className="flex flex-col gap-3 sm:flex-row">
            <div className="sm:w-40">
                <Label htmlFor="filtro-hoja">Hoja</Label>
                <Select
                    value={filtros.hoja || TODOS}
                    onValueChange={(v) =>
                        onChange({ hoja: v === TODOS ? null : v })
                    }
                >
                    <SelectTrigger id="filtro-hoja" className="mt-1">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={TODOS}>Todas</SelectItem>
                        {hojas.map((h) => (
                            <SelectItem key={h} value={h}>
                                {h}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>
            {mostrarProblema && (
                <div className="sm:w-80">
                    <Label htmlFor="filtro-problema">Problema</Label>
                    <Select
                        value={filtros.problema ?? TODOS}
                        onValueChange={(v) =>
                            onChange({ problema: v === TODOS ? null : v })
                        }
                    >
                        <SelectTrigger id="filtro-problema" className="mt-1">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={TODOS}>Todos</SelectItem>
                            {problemas.map((p) => (
                                <SelectItem key={p.value} value={p.value}>
                                    {p.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
            )}
        </div>
    );
}

/** Solo los problemas en que la planilla se contradice consigo misma se deciden en bloque. */
function EnBloque({
    loteId,
    bloques,
}: {
    loteId: number;
    bloques: Props['enBloque'];
}) {
    return (
        <section className="grid gap-3 rounded-lg border p-4">
            <h2 className="text-sm font-medium">Decidir en bloque</h2>
            {bloques.map((bloque) => (
                <BloqueForm key={bloque.code} loteId={loteId} bloque={bloque} />
            ))}
        </section>
    );
}

function BloqueForm({
    loteId,
    bloque,
}: {
    loteId: number;
    bloque: Props['enBloque'][number];
}) {
    const { data, setData, post, processing, errors } = useForm({
        issue: bloque.code,
        action: '',
        reason: '',
    });

    return (
        <form
            className="flex flex-col gap-2 border-t pt-3 sm:flex-row sm:items-end"
            onSubmit={(e) => {
                e.preventDefault();
                post(resolveInBulk(loteId).url, { preserveScroll: true });
            }}
        >
            <div className="min-w-0 flex-1 text-sm">
                <p className="font-medium">{bloque.label}</p>
                <p className="text-xs text-muted-foreground">
                    {bloque.rows} filas sin decidir
                </p>
            </div>
            <Accion
                id={`bloque-${bloque.code}`}
                acciones={bloque.actions}
                valor={data.action}
                onChange={(v) => setData('action', v)}
            />
            <Input
                aria-label="Motivo"
                placeholder="Motivo"
                value={data.reason}
                maxLength={500}
                onChange={(e) => setData('reason', e.target.value)}
                className="sm:w-56"
            />
            <Button type="submit" disabled={processing || data.action === ''}>
                Aplicar a {bloque.rows}
            </Button>
            <InputError message={errors.action ?? errors.reason} />
        </form>
    );
}

function Accion({
    id,
    acciones,
    valor,
    onChange,
}: {
    id: string;
    acciones: { value: string; label: string }[];
    valor: string;
    onChange: (valor: string) => void;
}) {
    return (
        <Select
            value={valor === '' ? undefined : valor}
            onValueChange={onChange}
        >
            <SelectTrigger id={id} aria-label="Decisión" className="sm:w-64">
                <SelectValue placeholder="Elegí qué hacer" />
            </SelectTrigger>
            <SelectContent>
                {acciones.map((a) => (
                    <SelectItem key={a.value} value={a.value}>
                        {a.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

/** Una fila: lo que decía la planilla y cada problema, con su columna y su decisión. */
function FilaImportada({ fila, decidir }: { fila: Fila; decidir: boolean }) {
    return (
        <li className="grid gap-2 rounded-lg border p-3 text-sm">
            <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <span className="font-medium">
                    {fila.sheet} · fila {fila.row}
                </span>
                <span className="min-w-0 flex-1 truncate">
                    {fila.caption || 'Sin carátula'}
                </span>
                {fila.fineUrl && (
                    <Link href={fila.fineUrl} className="text-xs underline">
                        Ver la multa
                    </Link>
                )}
            </div>
            <p className="text-xs text-muted-foreground">
                Expte. {fila.caseText || '—'} · Resol.{' '}
                {fila.resolutionText || '—'}
                {fila.actText && ` · Acta ${fila.actText}`} · Importe{' '}
                {fila.amount ? <Money value={fila.amount} /> : '—'} · Pagado{' '}
                <Money value={fila.paid} />
            </p>
            {fila.failure && (
                <p className="text-xs text-destructive">
                    No se pudo consolidar: {fila.failure}
                </p>
            )}
            <ul className="grid gap-1.5">
                {fila.issues.map((problema, i) => (
                    <ProblemaDeFila
                        key={`${problema.code}-${problema.column ?? ''}-${i}`}
                        filaId={fila.id}
                        problema={problema}
                        decidir={decidir}
                        yaListado={fila.issues
                            .slice(0, i)
                            .some((p) => p.code === problema.code)}
                    />
                ))}
            </ul>
        </li>
    );
}

function ProblemaDeFila({
    filaId,
    problema,
    decidir,
    yaListado,
}: {
    filaId: number;
    problema: Problema;
    decidir: boolean;
    yaListado: boolean;
}) {
    return (
        <li className="grid gap-1 rounded-md border border-dashed p-2 text-xs">
            <div className="flex flex-wrap items-center gap-2">
                <StatusBadge
                    label={problema.label}
                    tone={
                        !problema.blocking
                            ? 'neutral'
                            : problema.resolution
                              ? 'done'
                              : 'blocked'
                    }
                />
                {problema.column && (
                    <span className="text-muted-foreground">
                        Columna {problema.column}
                        {problema.value !== null &&
                            problema.value !== '' &&
                            `: ${problema.value}`}
                    </span>
                )}
            </div>
            <p>{problema.message}</p>
            {problema.resolution && (
                <p className="text-muted-foreground">
                    Decidido: {problema.resolution.label}
                    {problema.resolution.reason &&
                        ` — ${problema.resolution.reason}`}
                    {problema.resolution.byName &&
                        ` · ${problema.resolution.byName}`}
                </p>
            )}
            {decidir &&
                problema.blocking &&
                !problema.resolution &&
                !yaListado && (
                    <DecidirProblema filaId={filaId} problema={problema} />
                )}
        </li>
    );
}

function DecidirProblema({
    filaId,
    problema,
}: {
    filaId: number;
    problema: Problema;
}) {
    const { data, setData, post, processing, errors } = useForm({
        issue: problema.code,
        action: '',
        reason: '',
        fineCaseId: null as number | null,
    });

    return (
        <form
            className="flex flex-col gap-2 pt-1 sm:flex-row sm:flex-wrap sm:items-start"
            onSubmit={(e) => {
                e.preventDefault();
                post(resolveRow(filaId).url, { preserveScroll: true });
            }}
        >
            <Accion
                id={`decision-${filaId}-${problema.code}`}
                acciones={problema.actions}
                valor={data.action}
                onChange={(v) => setData('action', v)}
            />
            {data.action === 'same_case' && (
                <Select
                    value={
                        data.fineCaseId === null
                            ? undefined
                            : String(data.fineCaseId)
                    }
                    onValueChange={(v) => setData('fineCaseId', Number(v))}
                >
                    <SelectTrigger aria-label="Expediente" className="sm:w-56">
                        <SelectValue placeholder="Elegí el expediente" />
                    </SelectTrigger>
                    <SelectContent>
                        {problema.cases.map((c) => (
                            <SelectItem key={c.id} value={String(c.id)}>
                                {c.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            )}
            <Input
                aria-label="Motivo"
                placeholder={
                    data.action === 'exclude'
                        ? 'Motivo (obligatorio)'
                        : 'Motivo'
                }
                value={data.reason}
                maxLength={500}
                onChange={(e) => setData('reason', e.target.value)}
                className="sm:w-56"
            />
            <Button
                type="submit"
                size="sm"
                disabled={processing || data.action === ''}
            >
                Registrar
            </Button>
            <InputError
                className="w-full"
                message={errors.action ?? errors.reason ?? errors.fineCaseId}
            />
        </form>
    );
}

/** Los pagos de la planilla desde la apertura: se identifican a mano y se marcan. */
function Pendientes({ celdas }: { celdas: NonNullable<Props['celdas']> }) {
    if (celdas.data.length === 0) {
        return (
            <p className="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">
                No hay pagos posteriores a la apertura en filas consolidadas.
            </p>
        );
    }

    return (
        <>
            <ul className="grid gap-3">
                {celdas.data.map((celda) => (
                    <CeldaPendiente key={celda.id} celda={celda} />
                ))}
            </ul>
            <PaginationFooter
                pagination={celdas}
                singular="pago"
                plural="pagos"
            />
        </>
    );
}

function CeldaPendiente({ celda }: { celda: Celda }) {
    const estado = IMPORT_CELL_STATUS[celda.status];
    const [abierta, setAbierta] = useState(false);
    const { data, setData, post, processing, errors } = useForm({
        status: 'identified',
        note: '',
    });

    return (
        <li className="grid gap-2 rounded-lg border p-3 text-sm">
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <span className="font-medium">
                    {celda.sheet} · fila {celda.row} · columna {celda.column}
                </span>
                <span className="min-w-0 flex-1 truncate">{celda.caption}</span>
                <span>
                    {date(celda.month).slice(3)} ·{' '}
                    <Money value={celda.amount} />
                </span>
                <StatusBadge label={estado.label} tone={estado.tone} />
                {celda.fineUrl && (
                    <Link href={celda.fineUrl} className="text-xs underline">
                        Ver la multa
                    </Link>
                )}
            </div>
            {celda.note && (
                <p className="text-xs text-muted-foreground">{celda.note}</p>
            )}
            {celda.status === 'pending' &&
                (abierta ? (
                    <form
                        className="flex flex-col gap-2 sm:flex-row sm:items-start"
                        onSubmit={(e) => {
                            e.preventDefault();
                            post(resolveCell(celda.id).url, {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <Select
                            value={data.status}
                            onValueChange={(v) => setData('status', v)}
                        >
                            <SelectTrigger
                                aria-label="Estado"
                                className="sm:w-48"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="identified">
                                    Identificado
                                </SelectItem>
                                <SelectItem value="dismissed">
                                    Descartado
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <Input
                            aria-label="Nota"
                            placeholder={
                                data.status === 'identified'
                                    ? 'Con qué crédito o cobro'
                                    : 'Por qué se descarta'
                            }
                            value={data.note}
                            maxLength={500}
                            onChange={(e) => setData('note', e.target.value)}
                            className="min-w-0 flex-1"
                        />
                        <Button type="submit" size="sm" disabled={processing}>
                            Marcar
                        </Button>
                        <InputError message={errors.note ?? errors.status} />
                    </form>
                ) : (
                    <div>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setAbierta(true)}
                        >
                            Marcar
                        </Button>
                    </div>
                ))}
        </li>
    );
}
