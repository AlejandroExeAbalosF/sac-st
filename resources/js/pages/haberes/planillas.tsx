import { Head, Link, router } from '@inertiajs/react';
import {
    Banknote,
    ChevronRight,
    Clock,
    Download,
    ListFilter,
    Printer,
} from 'lucide-react';
import { useId, useState } from 'react';
import FormRowField from '@/components/form-row-field';
import PageHeader from '@/components/page-header';
import PaginationFooter from '@/components/pagination-footer';
import type { PaginationData } from '@/components/pagination-footer';
import StatusBadge from '@/components/status-badge';
import type { StatusTone } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { date, money } from '@/lib/format';
import { cn } from '@/lib/utils';
import { show as haberShow } from '@/routes/haberes/haber';
import { index, print } from '@/routes/planillas';

type CounterRow = App.Modules.Haberes.Data.CounterPayoutRowData;
type TransferRow = App.Modules.Haberes.Data.UnconfirmedTransferRowData;
type AllRow = App.Modules.Haberes.Data.AllInstallmentRowData;

type Cola = 'mostrador' | 'transferencias' | 'todas';
type Filters = {
    expediente: string;
    beneficiario: string;
    empleador: string;
    stages: string[];
    medio: string;
    medioPrevisto: string;
    retenido: boolean;
    vencimientoDesde: string;
    vencimientoHasta: string;
    pagoDesde: string;
    pagoHasta: string;
    importeMin: string;
    importeMax: string;
};
type StageOption = { value: string; label: string };

const EMPTY_FILTERS: Filters = {
    expediente: '',
    beneficiario: '',
    empleador: '',
    stages: [],
    medio: '',
    medioPrevisto: '',
    retenido: false,
    vencimientoDesde: '',
    vencimientoHasta: '',
    pagoDesde: '',
    pagoHasta: '',
    importeMin: '',
    importeMax: '',
};

const TRANSFER_STAGES = new Set([
    'transfer_reported',
    'debit_observed',
    'ready_to_validate',
]);

function activeFilterCount(filters: Filters, cola: Cola) {
    const common = [
        filters.expediente,
        filters.beneficiario,
        filters.empleador,
        filters.medioPrevisto,
        filters.vencimientoDesde,
        filters.vencimientoHasta,
        filters.importeMin,
        filters.importeMax,
    ].filter(Boolean).length;

    if (cola === 'mostrador') {
        return common;
    }

    if (cola === 'transferencias') {
        return (
            common +
            Number(filters.stages.some((stage) => TRANSFER_STAGES.has(stage)))
        );
    }

    return (
        common +
        [filters.medio, filters.pagoDesde, filters.pagoHasta].filter(Boolean)
            .length +
        Number(filters.stages.length > 0) +
        Number(filters.retenido)
    );
}

function planillaUrl(
    base: string,
    cola: Cola,
    filters: Filters,
    extra: Record<string, string> = {},
) {
    const query = new URLSearchParams({ cola, ...extra });

    for (const [key, value] of Object.entries(filters)) {
        if (key === 'stages') {
            for (const stage of value as string[]) {
                query.append('stages[]', stage);
            }
        } else if (value === true) {
            query.set(key, '1');
        } else if (typeof value === 'string' && value.trim() !== '') {
            query.set(key, value.trim());
        }
    }

    return `${base}?${query.toString()}`;
}

type Props = {
    cola: Cola;
    counter: CounterRow[];
    transfers: TransferRow[];
    all: (PaginationData & { data: AllRow[] }) | null;
    allCount: number;
    filters: Filters;
    stageOptions: StageOption[];
    /** Sumados en el servidor: acá el dinero no se suma, se muestra. */
    counterTotal: string;
    transfersTotal: string;
};

const TONO: Record<string, StatusTone> = {
    report_received: 'progress',
    bank_debit_observed: 'progress',
    ready_for_validation: 'action',
};

const ETIQUETA: Record<string, string> = {
    report_received: 'Transferencia informada · falta débito',
    bank_debit_observed: 'Débito identificado · falta informe',
    ready_for_validation: 'Informe y débito registrados · falta validación',
};

/**
 * Las dos colas del egreso.
 *
 * Una pantalla y no dos porque son las dos mitades del mismo dinero
 * saliendo: lo que se entrega en mano y lo que salió por el banco y todavía
 * nadie dio por hecho. La primera es del cajero y la segunda del contador,
 * pero las dos contestan la misma pregunta —a quién le debemos y en qué
 * estado está—.
 *
 * No se opera desde acá: cada fila lleva al haber, que es donde viven los
 * actos. Esto lista, cuenta e imprime.
 */
export default function Egresos({
    cola,
    counter,
    transfers,
    all,
    allCount,
    filters,
    stageOptions,
    counterTotal,
    transfersTotal,
}: Props) {
    const cambiar = (siguiente: Cola) =>
        router.get(
            planillaUrl(index().url, siguiente, filters),
            {},
            { preserveState: true, replace: true },
        );
    const activeFilters = activeFilterCount(filters, cola);

    const enMostrador = cola === 'mostrador';
    const enTransferencias = cola === 'transferencias';

    return (
        <>
            <Head title="Planillas" />

            <div className="space-y-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Haberes"
                    title="Planillas"
                    description="Cuotas por entregar, transferencias sin confirmar y el inventario completo con su estado."
                />

                <div className="flex flex-wrap items-center gap-2">
                    <Solapa
                        activo={enMostrador}
                        onClick={() => cambiar('mostrador')}
                        etiqueta="Por entregar"
                        cantidad={counter.length}
                    />
                    <Solapa
                        activo={enTransferencias}
                        onClick={() => cambiar('transferencias')}
                        etiqueta="Transferencias sin confirmar"
                        cantidad={transfers.length}
                    />
                    <Solapa
                        activo={cola === 'todas'}
                        onClick={() => cambiar('todas')}
                        etiqueta="Todas las cuotas"
                        cantidad={allCount}
                    />

                    <Button
                        asChild
                        variant="outline"
                        size="sm"
                        className="ml-auto"
                    >
                        {/* La planilla de lo que se está mirando. En pestaña
                            nueva: la lista sigue acá cuando el papel ya salió
                            por la impresora. */}
                        <a
                            href={planillaUrl(print().url, cola, filters)}
                            target="_blank"
                            rel="noreferrer"
                        >
                            <Printer aria-hidden="true" />
                            Planilla
                        </a>
                    </Button>
                    <Button asChild variant="outline" size="sm">
                        <a
                            href={planillaUrl(print().url, cola, filters, {
                                descargar: '1',
                            })}
                        >
                            <Download aria-hidden="true" /> PDF
                        </a>
                    </Button>
                    <Button asChild variant="outline" size="sm">
                        <a
                            href={planillaUrl(print().url, cola, filters, {
                                formato: 'xlsx',
                            })}
                        >
                            <Download aria-hidden="true" /> Excel
                        </a>
                    </Button>
                </div>

                <PlanillaFilterPanel
                    cola={cola}
                    filters={filters}
                    stageOptions={stageOptions}
                    activeFilters={activeFilters}
                />

                {enMostrador ? (
                    <Mostrador filas={counter} total={counterTotal} />
                ) : enTransferencias ? (
                    <Transferencias filas={transfers} total={transfersTotal} />
                ) : all ? (
                    <TodasLasCuotas pagina={all} filtered={activeFilters > 0} />
                ) : null}
            </div>
        </>
    );
}

function PlanillaFilterPanel({
    cola,
    filters,
    stageOptions,
    activeFilters,
}: {
    cola: Cola;
    filters: Filters;
    stageOptions: StageOption[];
    activeFilters: number;
}) {
    const [draft, setDraft] = useState<Filters>(filters);
    const [open, setOpen] = useState(false);
    const change = (key: keyof Filters, value: string | boolean | string[]) =>
        setDraft((current) => ({ ...current, [key]: value }));
    const apply = (next: Filters) => {
        setOpen(false);
        router.get(planillaUrl(index().url, cola, next), {}, { replace: true });
    };

    return (
        <Sheet
            open={open}
            onOpenChange={(value) => {
                setOpen(value);

                if (value) {
                    setDraft(filters);
                }
            }}
        >
            <SheetTrigger asChild>
                <Button type="button" variant="outline" size="sm">
                    <ListFilter aria-hidden="true" /> Filtros
                    {activeFilters > 0 ? ` (${activeFilters})` : ''}
                </Button>
            </SheetTrigger>
            <SheetContent side="right" className="overflow-y-auto sm:max-w-lg">
                <SheetHeader className="border-b pr-12">
                    <SheetTitle>Filtros para planillas</SheetTitle>
                    <SheetDescription>
                        Se muestran los criterios útiles para esta pestaña. Los
                        filtros comunes también afectan las otras planillas.
                    </SheetDescription>
                </SheetHeader>
                <form
                    className="standard-form space-y-5 px-4 pb-6"
                    onSubmit={(event) => {
                        event.preventDefault();
                        apply(draft);
                    }}
                >
                    <div className="grid gap-3 sm:grid-cols-2">
                        <FilterInput
                            label="Expediente"
                            value={draft.expediente}
                            onChange={(value) => change('expediente', value)}
                        />
                        <FilterInput
                            label="Beneficiario o documento"
                            value={draft.beneficiario}
                            onChange={(value) => change('beneficiario', value)}
                        />
                        <FilterInput
                            label="Empleador"
                            value={draft.empleador}
                            onChange={(value) => change('empleador', value)}
                        />
                        {cola === 'todas' && (
                            <FormRowField
                                htmlFor="planilla-medio"
                                label="Medio de pago"
                            >
                                <select
                                    id="planilla-medio"
                                    className="h-9 rounded-md border border-input bg-background px-3 font-normal"
                                    value={draft.medio}
                                    onChange={(event) =>
                                        change('medio', event.target.value)
                                    }
                                >
                                    <option value="">Todos</option>
                                    <option value="cash">Efectivo</option>
                                    <option value="cheque">Cheque</option>
                                    <option value="bank_transfer">
                                        Transferencia
                                    </option>
                                </select>
                            </FormRowField>
                        )}
                        <FormRowField
                            htmlFor="planilla-medio-previsto"
                            label="Medio de ingreso previsto"
                        >
                            <select
                                id="planilla-medio-previsto"
                                className="h-9 rounded-md border border-input bg-background px-3 font-normal"
                                value={draft.medioPrevisto}
                                onChange={(event) =>
                                    change('medioPrevisto', event.target.value)
                                }
                            >
                                <option value="">Todos</option>
                                <option value="cash">Efectivo</option>
                                <option value="cheque">Cheque</option>
                                <option value="bank">Banco</option>
                            </select>
                        </FormRowField>
                    </div>
                    {cola !== 'mostrador' && (
                        <fieldset className="space-y-2">
                            <legend className="text-sm font-medium">
                                {cola === 'todas'
                                    ? 'Situación de la cuota'
                                    : 'Avance de la transferencia'}
                            </legend>
                            <div className="grid gap-2 sm:grid-cols-2">
                                {stageOptions
                                    .filter(
                                        (option) =>
                                            cola === 'todas' ||
                                            TRANSFER_STAGES.has(option.value),
                                    )
                                    .map((option) => (
                                        <label
                                            key={option.value}
                                            className="flex items-center gap-2 text-sm"
                                        >
                                            <Checkbox
                                                checked={draft.stages.includes(
                                                    option.value,
                                                )}
                                                onCheckedChange={(checked) =>
                                                    change(
                                                        'stages',
                                                        checked
                                                            ? [
                                                                  ...draft.stages,
                                                                  option.value,
                                                              ]
                                                            : draft.stages.filter(
                                                                  (stage) =>
                                                                      stage !==
                                                                      option.value,
                                                              ),
                                                    )
                                                }
                                            />
                                            {option.label}
                                        </label>
                                    ))}
                            </div>
                        </fieldset>
                    )}
                    {cola === 'todas' && (
                        <label className="flex items-center gap-2 text-sm font-medium">
                            <Checkbox
                                checked={draft.retenido}
                                onCheckedChange={(checked) =>
                                    change('retenido', checked === true)
                                }
                            />
                            Solo cuotas con pago retenido
                        </label>
                    )}
                    <div className="grid gap-3 sm:grid-cols-2">
                        <FilterInput
                            label="Vencimiento desde"
                            type="date"
                            value={draft.vencimientoDesde}
                            onChange={(value) =>
                                change('vencimientoDesde', value)
                            }
                        />
                        <FilterInput
                            label="Vencimiento hasta"
                            type="date"
                            value={draft.vencimientoHasta}
                            onChange={(value) =>
                                change('vencimientoHasta', value)
                            }
                        />
                        {cola === 'todas' && (
                            <FilterInput
                                label="Pago desde"
                                type="date"
                                value={draft.pagoDesde}
                                onChange={(value) => change('pagoDesde', value)}
                            />
                        )}
                        {cola === 'todas' && (
                            <FilterInput
                                label="Pago hasta"
                                type="date"
                                value={draft.pagoHasta}
                                onChange={(value) => change('pagoHasta', value)}
                            />
                        )}
                        <FilterInput
                            label="Importe mínimo"
                            type="amount"
                            value={draft.importeMin}
                            onChange={(value) => change('importeMin', value)}
                        />
                        <FilterInput
                            label="Importe máximo"
                            type="amount"
                            value={draft.importeMax}
                            onChange={(value) => change('importeMax', value)}
                        />
                    </div>
                    <div className="flex justify-end gap-2 border-t pt-4">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => {
                                setDraft(EMPTY_FILTERS);
                                apply(EMPTY_FILTERS);
                            }}
                        >
                            Limpiar todos
                        </Button>
                        <Button type="submit">Aplicar filtros</Button>
                    </div>
                </form>
            </SheetContent>
        </Sheet>
    );
}

function FilterInput({
    label,
    value,
    onChange,
    type = 'text',
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    type?: string;
}) {
    const id = useId();

    return (
        <FormRowField htmlFor={id} label={label}>
            <Input
                id={id}
                type={type === 'amount' ? 'text' : type}
                inputMode={type === 'amount' ? 'decimal' : undefined}
                placeholder={type === 'amount' ? '0,00' : undefined}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className="font-normal"
            />
        </FormRowField>
    );
}

function TodasLasCuotas({
    pagina,
    filtered,
}: {
    pagina: PaginationData & { data: AllRow[] };
    filtered: boolean;
}) {
    if (pagina.total === 0) {
        return (
            <Vacio
                titulo={
                    filtered
                        ? 'No hay cuotas con estos filtros.'
                        : 'No hay cuotas registradas.'
                }
                detalle={
                    filtered
                        ? 'Probá con otros criterios o limpiá los filtros.'
                        : 'La planilla general se completa a medida que se cargan los haberes.'
                }
            />
        );
    }

    return (
        <div className="space-y-4">
            <p className="text-sm text-muted-foreground">
                Incluye cuotas vigentes, pagadas, históricas y anuladas. La
                planilla PDF contiene todas; la pantalla muestra 50 por página.
            </p>
            <div className="grid gap-3 md:hidden">
                {pagina.data.map((fila) => (
                    <Link
                        key={fila.installmentId}
                        href={haberShow([fila.expedienteId, fila.haberNumber])}
                        className="rounded-lg border p-3 transition-colors hover:bg-muted/40"
                    >
                        <div className="flex items-baseline justify-between gap-3">
                            <span className="font-medium">
                                {fila.beneficiaryName}
                            </span>
                            <span className="font-mono tabular-nums">
                                {money(fila.amount)}
                            </span>
                        </div>
                        <div className="mt-1 text-xs text-muted-foreground">
                            {fila.expedienteNumber} · {fila.installmentLabel}
                        </div>
                        <p className="mt-2 text-xs font-medium">
                            {fila.status}
                        </p>
                        {fila.paymentMethod && (
                            <p className="mt-1 text-xs text-muted-foreground">
                                Salida: {fila.paymentMethod}
                            </p>
                        )}
                    </Link>
                ))}
            </div>

            <div className="hidden overflow-x-auto rounded-lg border md:block">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-left">
                        <tr>
                            <th className="p-3 font-medium text-field-label">
                                Beneficiario
                            </th>
                            <th className="p-3 font-medium text-field-label">
                                Expediente
                            </th>
                            <th className="p-3 font-medium text-field-label">
                                Haber / Cuota
                            </th>
                            <th className="p-3 text-right font-medium text-field-label">
                                Importe
                            </th>
                            <th className="p-3 font-medium text-field-label">
                                Estado
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {pagina.data.map((fila) => (
                            <tr key={fila.installmentId} className="border-t">
                                <td className="p-3 font-medium">
                                    {fila.beneficiaryName}
                                </td>
                                <td className="p-3">{fila.expedienteNumber}</td>
                                <td className="p-3">
                                    <Link
                                        href={haberShow([
                                            fila.expedienteId,
                                            fila.haberNumber,
                                        ])}
                                        className="hover:underline"
                                    >
                                        {fila.installmentLabel}
                                    </Link>
                                    {fila.concept && (
                                        <div className="text-xs text-muted-foreground">
                                            {fila.concept}
                                        </div>
                                    )}
                                </td>
                                <td className="p-3 text-right font-mono tabular-nums">
                                    {money(fila.amount)}
                                </td>
                                <td className="p-3">
                                    {fila.status}
                                    {fila.paymentMethod && (
                                        <div className="text-xs text-muted-foreground">
                                            Salida: {fila.paymentMethod}
                                        </div>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            <PaginationFooter
                pagination={pagina}
                singular="cuota"
                plural="cuotas"
            />
        </div>
    );
}

function Mostrador({ filas, total }: { filas: CounterRow[]; total: string }) {
    if (filas.length === 0) {
        return (
            <Vacio
                titulo="No hay cuotas en efectivo listas para entregar."
                detalle="Una cuota aparece acá cuando está financiada, tiene su recibo de ingreso y no la bloquea ninguna etiqueta."
            />
        );
    }

    return (
        <div className="grid gap-4">
            <div className="grid gap-3 md:hidden">
                {filas.map((fila) => (
                    <Link
                        key={fila.installmentId}
                        href={haberShow([fila.expedienteId, fila.haberNumber])}
                        className="rounded-lg border p-3 transition-colors hover:bg-muted/40"
                    >
                        <div className="flex items-baseline justify-between gap-3">
                            <span className="font-medium">
                                {fila.beneficiaryName}
                            </span>
                            <span className="font-mono tabular-nums">
                                {money(fila.amount)}
                            </span>
                        </div>
                        <div className="mt-1 text-xs text-muted-foreground">
                            {fila.expedienteNumber} · {fila.installmentLabel}
                        </div>
                    </Link>
                ))}
            </div>

            <div className="hidden overflow-x-auto rounded-lg border md:block">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-left">
                        <tr>
                            <th className="p-3 font-medium text-field-label">
                                Beneficiario
                            </th>
                            <th className="p-3 font-medium text-field-label">
                                Expediente
                            </th>
                            <th className="p-3 font-medium text-field-label">
                                Haber / Cuota
                            </th>
                            <th className="p-3 text-right font-medium text-field-label">
                                Importe
                            </th>
                            <th className="p-3 font-medium text-field-label">
                                Recibo ingreso
                            </th>
                            <th className="p-3" />
                        </tr>
                    </thead>
                    <tbody>
                        {filas.map((fila) => (
                            <tr
                                key={fila.installmentId}
                                className="border-t transition-colors focus-within:bg-muted/40 hover:bg-muted/40"
                            >
                                <td className="p-3">
                                    <div className="font-medium">
                                        {fila.beneficiaryName}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {fila.beneficiaryDocument ??
                                            'Sin documento'}
                                    </div>
                                </td>
                                <td className="p-3">
                                    {fila.expedienteNumber}
                                    <div className="text-xs text-muted-foreground">
                                        {fila.employerName ?? 'Sin empleador'}
                                    </div>
                                </td>
                                <td className="p-3">
                                    {fila.installmentLabel}
                                    {fila.concept && (
                                        <div className="max-w-xs text-xs text-muted-foreground">
                                            {fila.concept}
                                        </div>
                                    )}
                                </td>
                                <td className="p-3 text-right font-mono tabular-nums">
                                    {money(fila.amount)}
                                </td>
                                <td className="p-3 tabular-nums">
                                    {fila.incomeReceiptNumber ?? '—'}
                                </td>
                                <td className="p-3 text-right">
                                    <Button asChild variant="ghost" size="icon">
                                        <Link
                                            href={haberShow([
                                                fila.expedienteId,
                                                fila.haberNumber,
                                            ])}
                                            aria-label={`Ver el haber de ${fila.beneficiaryName}`}
                                        >
                                            <ChevronRight aria-hidden="true" />
                                        </Link>
                                    </Button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot className="border-t bg-muted/30">
                        <tr>
                            <td className="p-3 font-medium" colSpan={3}>
                                Total a entregar
                            </td>
                            <td className="p-3 text-right font-mono font-medium tabular-nums">
                                {money(total)}
                            </td>
                            <td className="p-3" colSpan={2} />
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    );
}

function Transferencias({
    filas,
    total,
}: {
    filas: TransferRow[];
    total: string;
}) {
    if (filas.length === 0) {
        return (
            <Vacio
                titulo="No hay transferencias esperando confirmación."
                detalle="Un egreso aparece acá desde que el organismo informa o el débito se reconoce, y sale cuando el contador lo valida."
            />
        );
    }

    return (
        <div className="grid gap-4">
            <div className="grid gap-3 md:hidden">
                {filas.map((fila) => (
                    <Link
                        key={fila.disbursementId}
                        href={haberShow([fila.expedienteId, fila.haberNumber])}
                        className="rounded-lg border p-3 transition-colors hover:bg-muted/40"
                    >
                        <div className="flex items-baseline justify-between gap-3">
                            <span className="font-medium">
                                {fila.beneficiaryName}
                            </span>
                            <span className="font-mono tabular-nums">
                                {money(fila.amount)}
                            </span>
                        </div>
                        <div className="mt-1 text-xs text-muted-foreground">
                            {fila.expedienteNumber} · {fila.installmentLabel}
                        </div>
                        <StatusBadge
                            className="mt-2"
                            label={ETIQUETA[fila.status] ?? fila.status}
                            tone={TONO[fila.status] ?? 'neutral'}
                        />
                    </Link>
                ))}
            </div>

            <div className="hidden overflow-x-auto rounded-lg border md:block">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-left">
                        <tr>
                            <th className="p-3 font-medium text-field-label">
                                Beneficiario
                            </th>
                            <th className="p-3 font-medium text-field-label">
                                Expediente
                            </th>
                            <th className="p-3 text-right font-medium text-field-label">
                                Importe
                            </th>
                            <th className="p-3 font-medium text-field-label">
                                Orden
                            </th>
                            <th className="p-3 font-medium text-field-label">
                                Informe
                            </th>
                            <th className="p-3 font-medium text-field-label">
                                Débito
                            </th>
                            <th className="p-3 font-medium text-field-label">
                                Estado
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {filas.map((fila) => (
                            <tr
                                key={fila.disbursementId}
                                className="border-t transition-colors hover:bg-muted/40"
                            >
                                <td className="p-3 font-medium">
                                    {fila.beneficiaryName}
                                </td>
                                <td className="p-3">
                                    <Link
                                        href={haberShow([
                                            fila.expedienteId,
                                            fila.haberNumber,
                                        ])}
                                        className="font-medium hover:underline"
                                    >
                                        {fila.expedienteNumber}
                                    </Link>
                                    <div className="text-xs text-muted-foreground">
                                        {fila.installmentLabel}
                                    </div>
                                </td>
                                <td className="p-3 text-right font-mono tabular-nums">
                                    {money(fila.amount)}
                                </td>
                                <td className="p-3 tabular-nums">
                                    {fila.paymentOrderNumber ?? '—'}
                                </td>
                                <td className="p-3 whitespace-nowrap">
                                    {fila.reportedAt
                                        ? date(fila.reportedAt)
                                        : '—'}
                                </td>
                                <td className="p-3 whitespace-nowrap">
                                    {fila.debitObservedAt
                                        ? date(fila.debitObservedAt)
                                        : '—'}
                                </td>
                                <td className="p-3">
                                    <StatusBadge
                                        label={
                                            ETIQUETA[fila.status] ?? fila.status
                                        }
                                        tone={TONO[fila.status] ?? 'neutral'}
                                    />
                                    {fila.waitingDays > 7 && (
                                        <span className="mt-1 flex items-center gap-1 text-xs text-warning-strong">
                                            <Clock className="size-3" />
                                            hace {fila.waitingDays} días
                                        </span>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                    <tfoot className="border-t bg-muted/30">
                        <tr>
                            <td className="p-3 font-medium" colSpan={2}>
                                Total sin confirmar
                            </td>
                            <td className="p-3 text-right font-mono font-medium tabular-nums">
                                {money(total)}
                            </td>
                            <td className="p-3" colSpan={4} />
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    );
}

function Vacio({ titulo, detalle }: { titulo: string; detalle: string }) {
    return (
        <div className="rounded-lg border border-dashed p-10 text-center">
            <Banknote className="mx-auto size-8 text-muted-foreground" />
            <p className="mt-3 font-medium">{titulo}</p>
            <p className="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
                {detalle}
            </p>
        </div>
    );
}

function Solapa({
    activo,
    onClick,
    etiqueta,
    cantidad,
}: {
    activo: boolean;
    onClick: () => void;
    etiqueta: string;
    cantidad: number;
}) {
    return (
        <Button
            variant={activo ? 'default' : 'outline'}
            size="sm"
            onClick={onClick}
        >
            {etiqueta}
            <span
                className={cn(
                    'ml-1 tabular-nums',
                    !activo && 'text-muted-foreground',
                )}
            >
                {cantidad}
            </span>
        </Button>
    );
}
