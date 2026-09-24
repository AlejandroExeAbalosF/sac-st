import { Head, Link, router } from '@inertiajs/react';
import {
    BookOpen,
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    HandCoins,
    Lock,
    Scale,
} from 'lucide-react';
import Money, { EnMoneda } from '@/components/money';
import PageHeader from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import SelectorDeMoneda from '@/features/caja/components/currency-switch';
import DayActivity from '@/features/caja/components/day-activity';
import type { DayActivityEntry } from '@/features/caja/components/day-activity';
import FlujoDelDia from '@/features/caja/components/day-workflow';
import { conMoneda } from '@/features/caja/moneda';
import { useDrawer } from '@/features/drawer/drawer-context';
import { businessToday, date as formatDate } from '@/lib/format';
import type { CurrencyCode } from '@/lib/format';
import { calendario, dia as caja } from '@/routes/caja';
import { index as apertura } from '@/routes/caja/apertura';
import { index as arqueos } from '@/routes/caja/arqueos';
import { index as cierres } from '@/routes/caja/cierres';
import { index as pagosAnteriores } from '@/routes/caja/pagos-anteriores';

type Estado = App.Modules.Ledger.Data.CashBoxStateData;
type Arqueo = App.Modules.Ledger.Data.CashCountListItemData;
type Cierre = App.Modules.Ledger.Data.PeriodClosingListItemData;

type Fila = {
    number: string;
    receiptId: number;
    /**
     * Nulo en los pagos de haberes anteriores, que no cuelgan de ninguna
     * cuota. Decide qué fila abre panel: ofrecer uno que no lleva a ningún
     * lado es peor que no ofrecerlo.
     */
    installmentId: number | null;
    cash: string;
    cheques: string;
    bank: string;
};

type Props = {
    selected: {
        cashBoxId: number;
        cashBoxName: string;
        currency: CurrencyCode;
        date: string;
    };
    state: Estado;
    book: { income: Fila[]; expense: Fila[] };
    /** Lo que entró hoy y sigue en el cajón, según el libro. */
    dayTakings: string;
    activity: DayActivityEntry[];
    counts: Arqueo[];
    /** El arqueo firme anterior a este día, como referencia. */
    previousCount: Arqueo | null;
    /** Último conteo completo con billetes, para comparar composiciones. */
    compositionReference: Arqueo | null;
    /** Si la caja se movió después del día que se muestra. */
    movedAfter: boolean;
    closing: Cierre | null;
    needsOpening: boolean;
    /** La versión con la que dibuja el generador de planillas de hoy. */
    sheetVersion: string;
    /** El asiento con el que arrancó todo, y la nota que lo explica. */
    opening: { date: string; notes: string | null } | null;
    legacyPending: string;
    suggestedDenominations: number[];
    can: {
        open: boolean;
        payLegacy: boolean;
        regenerate: boolean;
        count: boolean;
        review: boolean;
        adjust: boolean;
        close: boolean;
        reopen: boolean;
        export: boolean;
    };
};

/**
 * La caja del día.
 *
 * Cierra el recorrido que la barra lateral describe. Arriba responde
 * «cuánto hay y dónde», que es lo primero que el área pregunta; abajo, de
 * dónde salió ese número — el mismo orden que tiene la planilla de papel,
 * porque el área ya sabe leerla así.
 */
export default function CajaIndex({
    selected,
    state,
    book,
    dayTakings,
    activity,
    counts,
    previousCount,
    compositionReference,
    movedAfter,
    closing,
    needsOpening,
    opening,
    sheetVersion,
    legacyPending,
    suggestedDenominations,
    can,
}: Props) {
    /*
     * La fecha de hoy es la de Salta, no la del navegador: `businessToday`
     * la fija sin depender de la zona del cliente, igual que el resto de
     * las pantallas de caja. Todo lo que sigue trabaja con cadenas
     * `AAAA-MM-DD`, que se comparan y ordenan bien tal cual, sin construir
     * `Date` locales que el huso podría correr un día.
     */
    const hoy = businessToday();
    const moneda = selected.currency;

    /** Cualquier dirección de la Caja conserva el libro que se está viendo. */
    const enlace = (url: string) => conMoneda(url, moneda);

    const irA = (fecha: string) =>
        router.get(
            enlace(caja({ query: { fecha } }).url),
            {},
            { preserveScroll: true },
        );

    const desplazar = (dias: number) => {
        const [anio, mes, dia] = selected.date.split('-').map(Number);
        // Aritmética en UTC para no arrastrar el huso del navegador.
        const base = new Date(Date.UTC(anio, mes - 1, dia));
        base.setUTCDate(base.getUTCDate() + dias);
        const destino = base.toISOString().slice(0, 10);

        if (destino > hoy) {
            return;
        }

        irA(destino);
    };

    return (
        <EnMoneda moneda={moneda}>
            <Head title="Caja del día" />

            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow={state.name}
                    title="Caja del día"
                    description={formatDate(selected.date)}
                    actions={
                        <>
                            <SelectorDeMoneda
                                moneda={moneda}
                                href={(otra) =>
                                    conMoneda(
                                        caja({
                                            query: { fecha: selected.date },
                                        }).url,
                                        otra,
                                    )
                                }
                            />
                            <div className="flex items-center gap-1">
                                <Button
                                    variant="outline"
                                    size="icon"
                                    onClick={() => desplazar(-1)}
                                    aria-label="Día anterior"
                                >
                                    <ChevronLeft className="size-4" />
                                </Button>
                                <Input
                                    type="date"
                                    value={selected.date}
                                    max={hoy}
                                    onChange={(e) =>
                                        e.target.value && irA(e.target.value)
                                    }
                                    className="w-40"
                                    aria-label="Fecha"
                                />
                                <Button
                                    variant="outline"
                                    size="icon"
                                    onClick={() => desplazar(1)}
                                    disabled={selected.date >= hoy}
                                    aria-label="Día siguiente"
                                >
                                    <ChevronRight className="size-4" />
                                </Button>
                            </div>

                            {/*
                             * Al calendario, ya puesto en el mes del día que
                             * se está mirando y con ese día marcado. Sin eso
                             * se entra por la vista del año, que es el nivel
                             * correcto cuando se viene del menú y el
                             * equivocado cuando se viene de un día.
                             *
                             * Va el día y no el año y el mes: los otros dos
                             * salen de él, y así no hay forma de pedir un 15
                             * de junio dentro de julio.
                             */}
                            <Button variant="outline" asChild>
                                <Link
                                    href={enlace(
                                        calendario({
                                            query: { dia: selected.date },
                                        }).url,
                                    )}
                                >
                                    <CalendarDays className="size-4" />
                                    Calendario
                                </Link>
                            </Button>

                            {can.count && (
                                <Button variant="outline" asChild>
                                    <Link
                                        href={enlace(
                                            arqueos({
                                                query: { fecha: selected.date },
                                            }).url,
                                        )}
                                    >
                                        <Scale className="size-4" />
                                        Arqueos
                                    </Link>
                                </Button>
                            )}
                            {can.close && (
                                <Button asChild>
                                    <Link
                                        href={enlace(
                                            cierres({
                                                query: { fecha: selected.date },
                                            }).url,
                                        )}
                                    >
                                        <Lock className="size-4" />
                                        Cierres
                                    </Link>
                                </Button>
                            )}
                        </>
                    }
                />

                {/*
                 * La observación de la apertura vive acá y no solo en su
                 * pantalla: es la única explicación de dónde salieron los
                 * tres saldos con los que arrancó la caja, y quien mira el
                 * día no tiene por qué ir a buscarla a otro lado.
                 */}
                {opening?.notes && (
                    <p className="text-sm text-muted-foreground">
                        Libros abiertos el {formatDate(opening.date)}:{' '}
                        {opening.notes}
                    </p>
                )}

                {needsOpening && (
                    <div className="flex flex-wrap items-center gap-3 rounded-lg border border-warning-soft bg-warning-soft px-4 py-3 text-warning-strong">
                        <BookOpen className="size-5 shrink-0" />
                        <div className="min-w-0 text-sm">
                            <p className="font-medium">
                                Los libros de esta caja todavía no se abrieron.
                            </p>
                            <p>
                                Hasta que se declare el saldo que ya está en el
                                cajón, los saldos arrancan en cero y el primer
                                arqueo va a dar una diferencia igual a todo el
                                saldo histórico.
                            </p>
                        </div>
                        {can.open && (
                            <Button
                                variant="outline"
                                className="ml-auto"
                                asChild
                            >
                                <Link href={enlace(apertura().url)}>
                                    Abrir libros
                                </Link>
                            </Button>
                        )}
                    </div>
                )}

                {/*
                 * Los tres saldos de la planilla más la cola de trabajo.
                 * Los tres primeros dicen dónde está la plata; el cuarto,
                 * de quién todavía no se sabe.
                 */}
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <Saldo
                        titulo="Efectivo en caja"
                        valor={state.cash}
                        destacado
                    />
                    <Saldo titulo="Cheques en custodia" valor={state.cheques} />
                    <Saldo titulo="En la cuenta" valor={state.bank} />
                    <Saldo
                        titulo="Sin identificar"
                        valor={state.unassigned}
                        nota="La cola de trabajo: entró y todavía no se sabe de quién es."
                    />
                </div>

                {/*
                 * Lo que queda del sistema anterior, mientras quede algo.
                 * Desaparece solo el día que se pague el último caso viejo,
                 * y ese es el día que se apaga la planilla en paralelo.
                 */}
                {/[1-9]/.test(legacyPending) && (
                    <div className="flex flex-wrap items-center gap-3 rounded-lg border bg-card px-4 py-3 text-sm">
                        <HandCoins className="size-4 shrink-0 text-muted-foreground" />
                        <span>
                            Quedan <Money value={legacyPending} /> del sistema
                            anterior por pagar.
                        </span>
                        {can.payLegacy && (
                            <Button
                                variant="outline"
                                size="sm"
                                className="ml-auto"
                                asChild
                            >
                                <Link href={pagosAnteriores().url}>
                                    Ver haberes anteriores
                                </Link>
                            </Button>
                        )}
                    </div>
                )}

                {/*
                 * El tránsito solo aparece cuando existe. Casi siempre es
                 * cero, y cuando no lo es explica por qué el arqueo cierra
                 * con menos plata sin que haya salido un peso al beneficiario.
                 */}
                {/[1-9]/.test(state.inTransit) && (
                    <p className="rounded-md border border-info-soft bg-info-soft px-3 py-2 text-sm text-info-strong">
                        <Money value={state.inTransit} /> salieron hacia el
                        banco y todavía no fueron acreditados.
                    </p>
                )}

                <div className="grid gap-4 lg:grid-cols-3">
                    <section className="flex flex-col gap-4 lg:col-span-2">
                        <Libro income={book.income} expense={book.expense} />
                        <DayActivity entries={activity} />
                    </section>

                    <aside className="flex flex-col gap-4">
                        <FlujoDelDia
                            fecha={selected.date}
                            cashBoxId={selected.cashBoxId}
                            cajaNombre={selected.cashBoxName}
                            currency={selected.currency}
                            arqueos={counts}
                            anterior={previousCount}
                            referenciaComposicion={compositionReference}
                            esperado={state.cash}
                            recaudacion={dayTakings}
                            cajonMovido={movedAfter}
                            cierre={closing}
                            denominaciones={suggestedDenominations}
                            sheetVersion={sheetVersion}
                            can={can}
                        />
                    </aside>
                </div>
            </div>
        </EnMoneda>
    );
}

function Saldo({
    titulo,
    valor,
    nota,
    destacado = false,
}: {
    titulo: string;
    valor: string;
    nota?: string;
    destacado?: boolean;
}) {
    return (
        <div
            className={
                destacado
                    ? 'rounded-lg border-2 border-primary/30 bg-card p-4'
                    : 'rounded-lg border bg-card p-4'
            }
        >
            <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {titulo}
            </p>
            <p className="mt-2 text-2xl">
                <Money value={valor} dimWhenZero />
            </p>
            {nota && (
                <p className="mt-2 text-xs text-muted-foreground">{nota}</p>
            )}
        </div>
    );
}

/** El detalle del día, con la misma forma que el anverso de la planilla. */
function Libro({ income, expense }: { income: Fila[]; expense: Fila[] }) {
    /*
     * Solo las tres columnas de importe. Escrito como `keyof Omit<Fila,
     * 'number'>` dejaba entrar `spoiled`, que es un booleano: el tipo
     * pasaba a ser `string | boolean` y la suma recibía algo que no se
     * puede sumar.
     */
    const total = (filas: Fila[], campo: 'cash' | 'cheques' | 'bank') =>
        filas.reduce(
            (acumulado, fila) => sumar(acumulado, fila[campo]),
            '0.00',
        );

    return (
        <div className="overflow-hidden rounded-lg border bg-card">
            <div className="border-b px-4 py-3">
                <h2 className="text-sm font-semibold">Movimientos del día</h2>
                <p className="text-xs text-muted-foreground">
                    Una fila por comprobante, como en la planilla.
                </p>
            </div>

            {income.length === 0 && expense.length === 0 ? (
                <p className="px-4 py-10 text-center text-sm text-muted-foreground">
                    No hubo comprobantes este día.
                </p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-xs tracking-wide text-muted-foreground uppercase">
                            <tr>
                                <th className="px-4 py-2 text-left font-medium">
                                    Recibo Nº
                                </th>
                                <th className="px-4 py-2 text-right font-medium">
                                    Efectivo
                                </th>
                                <th className="px-4 py-2 text-right font-medium">
                                    Cheques
                                </th>
                                <th className="px-4 py-2 text-right font-medium">
                                    Depósitos directos
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            <Bloque
                                filas={income}
                                rotulo="Ingresos"
                                total={[
                                    total(income, 'cash'),
                                    total(income, 'cheques'),
                                    total(income, 'bank'),
                                ]}
                            />
                            <Bloque
                                filas={expense}
                                rotulo="Egresos"
                                total={[
                                    total(expense, 'cash'),
                                    total(expense, 'cheques'),
                                    total(expense, 'bank'),
                                ]}
                            />
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}

function Bloque({
    filas,
    rotulo,
    total,
}: {
    filas: Fila[];
    rotulo: string;
    total: [string, string, string];
}) {
    return (
        <>
            {filas.map((fila) => (
                <tr key={`${rotulo}-${fila.receiptId}`}>
                    <td className="px-4 py-1.5 font-mono text-xs">
                        <ReciboLink fila={fila} />
                    </td>
                    <td className="px-4 py-1.5 text-right">
                        <Money value={fila.cash} dimWhenZero />
                    </td>
                    <td className="px-4 py-1.5 text-right">
                        <Money value={fila.cheques} dimWhenZero />
                    </td>
                    <td className="px-4 py-1.5 text-right">
                        <Money value={fila.bank} dimWhenZero />
                    </td>
                </tr>
            ))}
            <tr className="bg-muted/40 font-semibold">
                <td className="px-4 py-2 text-xs tracking-wide uppercase">
                    {rotulo}
                </td>
                {total.map((importe, i) => (
                    <td key={i} className="px-4 py-2 text-right">
                        <Money value={importe} dimWhenZero />
                    </td>
                ))}
            </tr>
        </>
    );
}

/**
 * El número del comprobante, que abre su panel cuando hay de qué hablar.
 *
 * Sin `installmentId` queda como texto: es un pago de haber anterior y el
 * panel no tendría a qué apuntar. Un número que parece clicable y no hace
 * nada es peor que uno que no lo parece.
 */
function ReciboLink({ fila }: { fila: Fila }) {
    const { openDrawer } = useDrawer();

    if (fila.installmentId === null) {
        return <span>{fila.number}</span>;
    }

    return (
        <button
            type="button"
            onClick={() => openDrawer({ kind: 'receipt', id: fila.receiptId })}
            className="rounded-sm underline decoration-dotted underline-offset-4 transition-colors hover:text-primary hover:decoration-solid focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            aria-label={`Ver de quién es el comprobante ${fila.number}`}
        >
            {fila.number}
        </button>
    );
}

/**
 * Suma dos importes decimales sin pasar por `number`.
 *
 * Es la misma regla que rige del lado de PHP: el punto flotante no existe
 * en ningún punto de la pila. Sumar centavos como enteros es exacto;
 * `0.1 + 0.2` no lo es.
 */
function sumar(a: string, b: string): string {
    const centavos = (valor: string) => {
        const negativo = valor.trimStart().startsWith('-');
        const [entero = '0', decimal = ''] = valor
            .replace(/[^0-9.]/g, '')
            .split('.');
        const total =
            BigInt(entero || '0') * 100n +
            BigInt((decimal + '00').slice(0, 2) || '0');

        return negativo ? -total : total;
    };

    const total = centavos(a) + centavos(b);
    const signo = total < 0n ? '-' : '';
    const absoluto = (total < 0n ? -total : total).toString().padStart(3, '0');

    return `${signo}${absoluto.slice(0, -2)}.${absoluto.slice(-2)}`;
}
