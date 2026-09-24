import { Head, Link } from '@inertiajs/react';
import {
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    FileSpreadsheet,
    List,
    Lock,
} from 'lucide-react';
import { useState } from 'react';
import Money, { EnMoneda } from '@/components/money';
import PageHeader from '@/components/page-header';
import StatusBadge from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { pasoDeCalendario, yaEmpezo } from '@/features/caja/calendar-nav';
import type { DestinoDeCalendario } from '@/features/caja/calendar-nav';
import { DialogoCierre } from '@/features/caja/components/closing-dialogs';
import SelectorDeMoneda from '@/features/caja/components/currency-switch';
import DayActivity from '@/features/caja/components/day-activity';
import type { DayActivityEntry } from '@/features/caja/components/day-activity';
import FlujoDelDia from '@/features/caja/components/day-workflow';
import { conMoneda } from '@/features/caja/moneda';
import { businessToday, date as formatDate, money } from '@/lib/format';
import type { CurrencyCode } from '@/lib/format';
import { cn } from '@/lib/utils';
import { dia as caja } from '@/routes/caja';
import { calendario } from '@/routes/caja';
import { index as cierres, sheet } from '@/routes/caja/cierres';

type Dia = App.Modules.Ledger.Data.CalendarDayData;
type Mes = App.Modules.Ledger.Data.CalendarMonthData;
type Cierre = App.Modules.Ledger.Data.PeriodClosingListItemData;
type Arqueo = App.Modules.Ledger.Data.CashCountListItemData;

/** El arqueo y el cierre del día elegido, para las tarjetas de la derecha. */
type DetalleDia = {
    date: string;
    /** Todos los turnos del día, en orden: el panel muestra su historia. */
    arqueos: Arqueo[];
    /** El arqueo firme anterior a ese día, como referencia. */
    previousCount: Arqueo | null;
    /** Último conteo completo con billetes, para comparar composiciones. */
    compositionReference: Arqueo | null;
    /** Si la caja se movió después de ese día. */
    movedAfter: boolean;
    /** El saldo del libro a ese día, para adelantar la diferencia. */
    expectedCash: string;
    /** Lo que entró ese día y sigue en el cajón. */
    dayTakings: string;
    activity: DayActivityEntry[];
    closing: Cierre | null;
} | null;

type Props = {
    selected: {
        cashBoxId: number;
        cashBoxName: string;
        currency: CurrencyCode;
        year: number;
        month: number | null;
        /** El día del que se viene, para marcarlo. Null si nadie lo dijo. */
        day: string | null;
    };
    months: Mes[];
    days: Dia[];
    monthlyClosing: Cierre | null;
    dayDetail: DetalleDia;
    can: {
        count: boolean;
        review: boolean;
        adjust: boolean;
        close: boolean;
        reopen: boolean;
        export: boolean;
        regenerate: boolean;
    };
    /** Las que el arqueo ofrece, para poder contar desde acá. */
    suggestedDenominations: number[];
    /** La versión con la que dibuja el generador de planillas de hoy. */
    sheetVersion: string;
    /** Días con movimientos sin cerrar, por mes `YYYY-MM`. */
    pendingDaysByMonth: Record<string, number>;
};

/**
 * Los estados visibles de un día y su relación con el libro.
 *
 * Un cierre de caja se mira para encontrar **lo que falta**, no lo que
 * está. `pending` —hubo movimientos y nadie cerró— llama la atención sin
 * pintar de rojo toda la celda. Un día cuyos únicos asientos son
 * reversiones también queda pendiente de cierre, pero se ve neutro y se
 * nombra aparte: el libro se movió y no hay comprobante que lo explique,
 * así que llamarlo «con movimientos» fingiría un cobro o un pago.
 */
const ESTADO: Record<
    string,
    { clases: string; leyenda: string; punto: string }
> = {
    closed: {
        clases: 'bg-success-soft text-success-strong border-transparent',
        leyenda: 'Cerrado',
        punto: 'bg-success-strong',
    },
    reopened: {
        clases: 'bg-info-soft text-info-strong border-transparent',
        leyenda: 'Reabierto',
        punto: 'bg-info-strong',
    },
    pending: {
        clases: 'border-warning-strong/50 bg-warning-soft text-warning-strong',
        leyenda: 'Con movimientos, sin cerrar',
        punto: 'bg-warning-strong',
    },
    reversal: {
        /*
         * Fondo neutro, borde de pendiente. La celda no puede fingir que
         * entro o salio plata, pero tampoco puede verse igual que un dia
         * vacio: el dia sigue bloqueando el cierre del mes, y la barra de
         * arriba lo cuenta. Con las clases de `quiet` se le pedía al
         * operador que buscara algo que no se veía.
         */
        clases: 'border-warning-strong/50 bg-card text-muted-foreground',
        leyenda: 'Solo reversiones, sin cerrar',
        punto: 'border border-muted-foreground bg-card',
    },
    quiet: {
        clases: 'border-border bg-card text-muted-foreground',
        leyenda: 'Sin movimientos',
        punto: 'bg-muted-foreground',
    },
    future: {
        clases: 'border-transparent bg-transparent text-muted-foreground/40',
        leyenda: 'Todavía no',
        punto: 'bg-muted-foreground/40',
    },
};

const DIAS = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];

/**
 * El calendario de la caja.
 *
 * **Es otra forma de mirar los mismos cierres, no otro dato.** Lo que
 * agrega sobre la lista es lo que una lista no puede mostrar: los días que
 * tuvieron movimientos y quedaron sin cerrar. Una lista enumera lo que
 * existe; acá lo que salta a la vista es lo que falta.
 *
 * Dos niveles, que son los dos que el dominio tiene: el año lleva adentro
 * de un mes, y el mes muestra sus días. **No hay cierre anual** — el año es
 * navegación, porque doce celdas entran en una pantalla y trescientas no.
 */
export default function CajaCalendario({
    selected,
    months,
    days,
    monthlyClosing,
    dayDetail,
    can,
    suggestedDenominations,
    sheetVersion,
    pendingDaysByMonth,
}: Props) {
    const enMes = selected.month !== null;

    /** El mes que se está por cerrar, si alguien apretó el botón. */
    const [cerrandoMes, setCerrandoMes] = useState(false);

    const moneda = selected.currency;

    /** Cualquier dirección del calendario conserva el libro que se está viendo. */
    const enlace = (url: string) => conMoneda(url, moneda);

    const ir = (query: Record<string, number | undefined>) =>
        enlace(calendario({ query: { anio: selected.year, ...query } }).url);

    /*
     * Clic en un día lo elige: manda solo `?dia=`, del que salen el año y
     * el mes, y la grilla lo anilla mientras el panel de la derecha muestra
     * su arqueo y su cierre. Un solo parámetro, como el resto del
     * calendario.
     */
    const hrefDia = (fecha: string) =>
        enlace(calendario({ query: { dia: fecha } }).url);

    /*
     * Las flechas mueven lo que se está mirando —el mes cuando se entró a
     * uno, el año cuando se ve el año— y no arrastran el día marcado: la
     * marca dice de dónde se viene, y en cuanto uno navega deja de ser
     * cierto. El cálculo vive en `features/caja/calendar-nav`, con sus
     * pruebas: es donde estuvo el error.
     */
    const anterior = pasoDeCalendario(selected.year, selected.month, -1);
    const siguiente = pasoDeCalendario(selected.year, selected.month, 1);
    const haySiguiente = yaEmpezo(siguiente, new Date());

    const hrefDe = (destino: DestinoDeCalendario) =>
        enlace(
            calendario({ query: { anio: destino.anio, mes: destino.mes } }).url,
        );

    return (
        <EnMoneda moneda={moneda}>
            <Head title="Calendario de caja" />

            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Caja"
                    title="Calendario"
                    description={
                        enMes
                            ? `${months[selected.month! - 1]?.label} de ${selected.year}`
                            : `Año ${selected.year}`
                    }
                    actions={
                        <>
                            <SelectorDeMoneda
                                moneda={moneda}
                                href={(otra) =>
                                    conMoneda(
                                        calendario({
                                            query: {
                                                anio: selected.year,
                                                mes:
                                                    selected.month ?? undefined,
                                            },
                                        }).url,
                                        otra,
                                    )
                                }
                            />
                            <Button variant="outline" asChild>
                                <Link href={enlace(cierres().url)}>
                                    <List className="size-4" />
                                    Ver como lista
                                </Link>
                            </Button>
                        </>
                    }
                />

                <div className="flex flex-wrap items-center gap-2">
                    <Button variant="outline" size="icon" asChild>
                        <Link
                            href={hrefDe(anterior)}
                            aria-label={enMes ? 'Mes anterior' : 'Año anterior'}
                        >
                            <ChevronLeft className="size-4" />
                        </Link>
                    </Button>

                    <span className="min-w-36 text-center text-sm font-semibold">
                        {enMes
                            ? `${months[selected.month! - 1]?.label} ${selected.year}`
                            : selected.year}
                    </span>

                    <Button
                        variant="outline"
                        size="icon"
                        disabled={!haySiguiente}
                        asChild={haySiguiente}
                    >
                        {haySiguiente ? (
                            <Link
                                href={hrefDe(siguiente)}
                                aria-label={
                                    enMes ? 'Mes siguiente' : 'Año siguiente'
                                }
                            >
                                <ChevronRight className="size-4" />
                            </Link>
                        ) : (
                            <ChevronRight className="size-4" />
                        )}
                    </Button>

                    {enMes && (
                        <Button variant="ghost" size="sm" asChild>
                            <Link href={ir({ mes: undefined })}>
                                <CalendarDays className="size-4" />
                                Ver el año
                            </Link>
                        </Button>
                    )}
                </div>

                {enMes ? (
                    <div className="flex flex-col gap-4 lg:flex-row lg:items-start">
                        <div className="min-w-0 flex-1">
                            <GrillaDelMes
                                days={days}
                                marcado={selected.day}
                                monthlyClosing={monthlyClosing}
                                mes={selected.month}
                                onCerrarMes={() => setCerrandoMes(true)}
                                can={can}
                                hrefDia={hrefDia}
                            />
                            <div className="mt-4">
                                <Leyenda />
                            </div>
                        </div>
                        <PanelDelDia
                            detalle={dayDetail}
                            can={can}
                            selected={selected}
                            denominaciones={suggestedDenominations}
                            sheetVersion={sheetVersion}
                        />
                    </div>
                ) : (
                    <GrillaDelAnio
                        months={months}
                        year={selected.year}
                        hrefDe={(mes) => ir({ mes })}
                        can={can}
                    />
                )}

                {!enMes && <Leyenda />}
            </div>

            {cerrandoMes && selected.month !== null && (
                <DialogoCierre
                    inicial={{
                        date: `${selected.year}-${String(selected.month).padStart(2, '0')}-01`,
                        periodType: 'monthly',
                    }}
                    cerrar={() => setCerrandoMes(false)}
                    selected={selected}
                    pendingDaysByMonth={pendingDaysByMonth}
                />
            )}
        </EnMoneda>
    );
}

/**
 * El día elegido, con sus dos tarjetas.
 *
 * Son las mismas que la caja del día —arqueo y cierre—, acá al costado de
 * la grilla para no mandar al operador a otra pantalla a ver el detalle del
 * día que ya tiene elegido. Sin día elegido, invita a elegir uno: la
 * columna existe pero todavía no tiene qué contar.
 */
function PanelDelDia({
    detalle,
    can,
    selected,
    denominaciones,
    sheetVersion,
}: {
    detalle: DetalleDia;
    can: Props['can'];
    selected: Props['selected'];
    denominaciones: number[];
    sheetVersion: string;
}) {
    if (detalle === null) {
        return (
            <aside className="rounded-lg border border-dashed bg-card/50 p-6 text-center text-sm text-muted-foreground lg:w-80 lg:shrink-0">
                Elegí un día en la grilla para ver su arqueo y su cierre.
            </aside>
        );
    }

    return (
        <aside className="flex flex-col gap-4 lg:w-80 lg:shrink-0">
            <div className="flex items-center justify-between gap-2">
                <p className="text-sm font-semibold">
                    {formatDate(detalle.date)}
                </p>
                <Button variant="ghost" size="sm" asChild>
                    <Link
                        href={conMoneda(
                            caja({ query: { fecha: detalle.date } }).url,
                            selected.currency,
                        )}
                    >
                        Abrir el día
                    </Link>
                </Button>
            </div>

            <DayActivity entries={detalle.activity} />

            {/*
             * El mismo componente que la caja del día, no una copia de
             * solo lectura.
             *
             * Antes el panel dibujaba las dos tarjetas sin acciones, así
             * que elegir un día en la grilla servía para mirarlo y nada
             * más: para contar el cajón, cerrarlo o rehacer su planilla
             * había que irse a otra pantalla y volver a elegir el mismo
             * día. Compartir el flujo evita además que las dos vistas se
             * separen cuando el circuito cambie.
             */}
            <FlujoDelDia
                fecha={detalle.date}
                cashBoxId={selected.cashBoxId}
                cajaNombre={selected.cashBoxName}
                currency={selected.currency}
                arqueos={detalle.arqueos}
                anterior={detalle.previousCount}
                referenciaComposicion={detalle.compositionReference}
                cajonMovido={detalle.movedAfter}
                esperado={detalle.expectedCash}
                recaudacion={detalle.dayTakings}
                cierre={detalle.closing}
                denominaciones={denominaciones}
                sheetVersion={sheetVersion}
                can={can}
            />
        </aside>
    );
}

function GrillaDelAnio({
    months,
    year,
    hrefDe,
    can,
}: {
    months: Mes[];
    year: number;
    hrefDe: (mes: number) => string;
    can: Props['can'];
}) {
    return (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            {months.map((mes) => (
                <article
                    key={mes.month}
                    className={cn(
                        'rounded-lg border bg-card p-4',
                        mes.isFuture && 'opacity-50',
                    )}
                >
                    <div className="flex items-start justify-between gap-2">
                        <h2 className="text-sm font-semibold">
                            {mes.label}{' '}
                            <span className="font-normal text-muted-foreground">
                                {year}
                            </span>
                        </h2>
                        {mes.closed && (
                            <StatusBadge label="Cerrado" tone="done" />
                        )}
                    </div>

                    <p className="mt-3 text-xl">
                        <Money value={mes.closingCash} dimWhenZero />
                    </p>

                    {/*
                     * La única cifra de avance que importa: si el mes está
                     * al día o quedaron jornadas sin cerrar.
                     */}
                    <p className="mt-1 text-xs text-muted-foreground">
                        {mes.daysWithMovements === 0
                            ? 'Sin movimientos'
                            : `${mes.daysClosed} de ${mes.daysWithMovements} días con movimiento, cerrados`}
                    </p>

                    <div className="mt-3 flex gap-2">
                        {!mes.isFuture && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={hrefDe(mes.month)}>Ver el mes</Link>
                            </Button>
                        )}
                        {can.export && mes.closed && mes.closingId && (
                            <Button variant="ghost" size="sm" asChild>
                                <a href={sheet({ closing: mes.closingId }).url}>
                                    <FileSpreadsheet className="size-4" />
                                    Libro
                                </a>
                            </Button>
                        )}
                    </div>
                </article>
            ))}
        </div>
    );
}

function GrillaDelMes({
    days,
    marcado,
    monthlyClosing,
    mes,
    can,
    hrefDia,
    onCerrarMes,
}: {
    days: Dia[];
    marcado: string | null;
    monthlyClosing: Cierre | null;
    mes: number | null;
    can: Props['can'];
    hrefDia: (fecha: string) => string;
    onCerrarMes: () => void;
}) {
    /*
     * Cerrar el mes se ofrece acá porque acá es donde se lo está mirando.
     *
     * Estaba solo en la pantalla de Cierres, detrás de un botón que dice
     * «Cerrar período» y de un selector que arranca en «Diario»: había
     * que saber de antemano que ahí adentro estaba el mensual. En el
     * calendario el mes es lo que se tiene delante.
     */
    const delMes = days.filter((dia) => dia.inMonth);
    const conMovimiento = delMes.filter((dia) => dia.hasMovements);
    const sinCerrar = delMes.filter((dia) => dia.state === 'pending').length;

    /*
     * El mes en curso no se cierra: todavía no terminó. Se compara el
     * último día del mes contra hoy, que es la misma regla que aplica el
     * Action.
     */
    const ultimoDelMes = delMes.at(-1)?.date ?? '';
    const terminado = ultimoDelMes !== '' && ultimoDelMes < businessToday();

    const motivo = !terminado
        ? 'El mes todavía no terminó.'
        : sinCerrar > 0
          ? sinCerrar === 1
              ? 'Queda 1 día con movimientos sin cerrar.'
              : `Quedan ${sinCerrar} días con movimientos sin cerrar.`
          : null;

    return (
        <div className="flex flex-col gap-4">
            {/*
             * Sin cierre mensual y con movimientos, la barra ofrece
             * cerrarlo; el botón se apaga con el motivo al lado cuando
             * todavía no corresponde, en vez de esconderse.
             */}
            {monthlyClosing === null &&
                mes !== null &&
                conMovimiento.length > 0 &&
                can.close && (
                    <div className="flex flex-wrap items-center gap-3 rounded-lg border bg-card px-4 py-3">
                        <Lock className="size-4 text-muted-foreground" />
                        <span className="text-sm font-medium">
                            El mes no está cerrado
                        </span>
                        {motivo !== null && (
                            <span
                                id="motivo-mes-bloqueado"
                                className="text-sm text-muted-foreground"
                            >
                                {motivo}
                            </span>
                        )}
                        <Button
                            size="sm"
                            className="ml-auto"
                            disabled={motivo !== null}
                            aria-describedby={
                                motivo !== null
                                    ? 'motivo-mes-bloqueado'
                                    : undefined
                            }
                            onClick={onCerrarMes}
                        >
                            <Lock className="size-4" />
                            Cerrar el mes
                        </Button>
                    </div>
                )}

            {monthlyClosing && (
                <div className="flex flex-wrap items-center gap-3 rounded-lg border bg-card px-4 py-3">
                    <Lock className="size-4 text-muted-foreground" />
                    <span className="text-sm font-medium">
                        El mes está cerrado
                    </span>
                    <span className="text-sm text-muted-foreground">
                        Saldo final <Money value={monthlyClosing.closingCash} />
                    </span>
                    {can.export && (
                        <Button
                            variant="outline"
                            size="sm"
                            className="ml-auto"
                            asChild
                        >
                            <a href={sheet({ closing: monthlyClosing.id }).url}>
                                <FileSpreadsheet className="size-4" />
                                Libro del mes
                            </a>
                        </Button>
                    )}
                </div>
            )}

            <div className="overflow-x-auto rounded-lg border bg-card p-3">
                <div className="grid min-w-[22rem] grid-cols-7 gap-2">
                    {DIAS.map((dia) => (
                        <div
                            key={dia}
                            className="pb-1 text-center text-sm font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            {dia}
                        </div>
                    ))}

                    {days.map((dia) => (
                        <Celda
                            key={dia.date}
                            dia={dia}
                            marcado={dia.date === marcado}
                            href={hrefDia(dia.date)}
                        />
                    ))}
                </div>
            </div>
        </div>
    );
}

function Celda({
    dia,
    marcado,
    href,
}: {
    dia: Dia;
    marcado: boolean;
    href: string;
}) {
    const soloReversion = dia.state === 'pending' && dia.onlyReversals;
    const estilo = soloReversion
        ? ESTADO.reversal
        : (ESTADO[dia.state] ?? ESTADO.quiet);
    const interactivo = dia.inMonth && dia.state !== 'future';

    const clases = cn(
        'flex min-h-20 min-w-11 flex-col rounded-md border p-2 text-left transition',
        estilo.clases,
        !dia.inMonth && 'opacity-35',
        /*
         * El día elegido. El anillo va por fuera del borde —con `offset`—
         * para no taparle el color, que es lo que dice el estado.
         */
        marcado && 'ring-2 ring-primary ring-offset-2 ring-offset-background',
        interactivo && 'hover:ring-2 hover:ring-primary/40',
        !interactivo && 'cursor-default',
    );

    const contenido = (
        <>
            <span className="flex items-center gap-1.5 text-xs font-semibold tabular-nums">
                {dia.day}
                {dia.isToday && (
                    <span className="rounded-full bg-primary px-1.5 text-xs font-medium text-primary-foreground">
                        hoy
                    </span>
                )}
            </span>

            {dia.closingCash !== null && (
                <span className="mt-auto text-xs">
                    <Money value={dia.closingCash} />
                </span>
            )}

            <span className="mt-1 flex items-center gap-1">
                {/*
                 * El punto de la leyenda, no la palabra: siete columnas
                 * con tres rótulos de texto empujaban la grilla a 32rem y
                 * la obligaban a scrollear en horizontal en un teléfono.
                 * Qué significa lo dicen el `title` y la etiqueta
                 * accesible, que ya lo nombran entero.
                 */}
                {soloReversion && (
                    <span
                        className={cn(
                            'size-2 rounded-full',
                            ESTADO.reversal.punto,
                        )}
                        title="Solo reversiones: el libro se movió sin comprobante"
                    />
                )}
                {/*
                 * El arqueo parcial se marca aparte: cuadrar no es haber
                 * contado todo, y en una grilla es la única forma de que se
                 * note sin abrir el día.
                 */}
                {dia.partiallyCounted && (
                    <span
                        className="text-xs text-warning-strong"
                        title="Cuadró, pero no se contó todo el cajón"
                    >
                        parcial
                    </span>
                )}
                {dia.countBalanced === false && (
                    <span
                        className="text-xs font-semibold text-warning-strong"
                        title="El arqueo no cuadró"
                    >
                        difiere
                    </span>
                )}
                {dia.hasSheet && (
                    <FileSpreadsheet
                        className="size-3 opacity-60"
                        aria-label="Planilla emitida"
                    />
                )}
            </span>
        </>
    );

    if (!interactivo) {
        return (
            <div
                className={clases}
                aria-label={descripcionAccesible(dia, estilo.leyenda)}
            >
                {contenido}
            </div>
        );
    }

    return (
        <Link
            href={href}
            preserveScroll
            className={clases}
            aria-current={marcado ? 'date' : undefined}
            aria-label={descripcionAccesible(dia, estilo.leyenda)}
        >
            {contenido}
        </Link>
    );
}

function descripcionAccesible(dia: Dia, estado: string): string {
    const detalles = [
        `${dia.date} — ${estado}`,
        dia.closingCash === null
            ? null
            : `saldo de cierre ${money(dia.closingCash)}`,
        dia.partiallyCounted ? 'arqueo parcial' : null,
        dia.countBalanced === false ? 'arqueo con diferencia' : null,
        dia.hasSheet ? 'planilla emitida' : null,
    ].filter((detalle): detalle is string => detalle !== null);

    return detalles.join('. ');
}

function Leyenda() {
    return (
        <div className="flex flex-wrap gap-4 text-xs text-muted-foreground">
            {['closed', 'pending', 'reopened', 'reversal', 'quiet'].map(
                (estado) => (
                    <span key={estado} className="flex items-center gap-1.5">
                        <span
                            className={cn(
                                'size-2 rounded-full',
                                ESTADO[estado].punto,
                            )}
                            aria-hidden="true"
                        />
                        {ESTADO[estado].leyenda}
                    </span>
                ),
            )}
        </div>
    );
}
