import { Head } from '@inertiajs/react';
import { Info } from 'lucide-react';
import Money, { EnMoneda } from '@/components/money';
import PageHeader from '@/components/page-header';
import SelectorDeMoneda from '@/features/caja/components/currency-switch';
import { conMoneda } from '@/features/caja/moneda';
import { date as formatDate } from '@/lib/format';
import type { CurrencyCode } from '@/lib/format';
import { index as saldoAnterior } from '@/routes/caja/saldo-anterior';

type Reserva = {
    id: number;
    date: string;
    description: string | null;
    amount: string;
    released: string;
};

type Props = {
    selected: {
        cashBoxId: number;
        currency: CurrencyCode;
        cashBoxName: string;
    };
    balances: {
        pending: string;
        cash: string;
        cheques: string;
        bank: string;
    };
    /**
     * Cuánto de lo que queda del sistema anterior está en cada lugar. Es
     * el tope de lo que se reserva de ahí: el resto de cada saldo es plata
     * que entró después.
     */
    legacyByPlace: { cash: string; cheques: string; bank: string };
    /** Lo reservado para cuotas de expedientes históricos. */
    setAside: Reserva[];
};

/**
 * El saldo del sistema anterior: cuánto queda, dónde está y qué se reservó.
 *
 * **Es una pantalla de consulta.** Responde la pregunta que dice cuándo se
 * apaga la planilla en paralelo: cuánto queda del sistema anterior sin
 * reservar. Nada se paga desde acá: un caso viejo se paga cargando su
 * expediente histórico, reservando la plata para la cuota y pagándola por
 * el circuito, así cada peso queda atado a su cuota.
 */
export default function SaldoAnterior({
    selected,
    balances,
    legacyByPlace,
    setAside,
}: Props) {
    const quedaAlgo = /[1-9]/.test(balances.pending);

    return (
        <EnMoneda moneda={selected.currency}>
            <Head title="Saldo del sistema anterior" />

            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow={selected.cashBoxName}
                    title="Saldo del sistema anterior"
                    description="Lo que la caja tenía al abrir los libros: cuánto queda sin reservar, dónde está y qué ya se reservó para cuotas históricas."
                    actions={
                        <SelectorDeMoneda
                            moneda={selected.currency}
                            href={(otra) =>
                                conMoneda(saldoAnterior().url, otra)
                            }
                        />
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="rounded-lg border-2 border-primary/30 bg-card p-4">
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Del sistema anterior, sin reservar
                        </p>
                        <p className="mt-2 text-2xl">
                            <Money value={balances.pending} dimWhenZero />
                        </p>
                        <p className="mt-2 text-xs text-muted-foreground">
                            {quedaAlgo
                                ? 'Baja con cada reserva para una cuota histórica. En cero, no queda ningún caso viejo sin resolver.'
                                : 'No queda nada del sistema anterior sin reservar.'}
                        </p>
                    </div>

                    <Saldo
                        titulo="Efectivo en caja"
                        valor={balances.cash}
                        delAnterior={legacyByPlace.cash}
                    />
                    <Saldo
                        titulo="Cheques en custodia"
                        valor={balances.cheques}
                        delAnterior={legacyByPlace.cheques}
                    />
                    <Saldo
                        titulo="Depósitos directos"
                        valor={balances.bank}
                        delAnterior={legacyByPlace.bank}
                    />
                </div>

                {quedaAlgo ? (
                    <p className="flex items-start gap-2 rounded-lg border bg-card px-4 py-3 text-sm text-muted-foreground">
                        <Info className="mt-0.5 size-4 shrink-0" />
                        Para pagar un caso del sistema anterior se carga su
                        expediente y, en la cuota, se usa «Cuota del sistema
                        anterior»: la plata se reserva para esa cuota y se le
                        paga por el circuito normal.
                    </p>
                ) : (
                    setAside.length === 0 && (
                        <p className="flex items-center gap-2 rounded-lg border bg-card px-4 py-3 text-sm text-muted-foreground">
                            <Info className="size-4 shrink-0" />
                            Esta caja no declaró saldo del sistema anterior al
                            abrir los libros.
                        </p>
                    )
                )}

                {setAside.length > 0 && (
                    <section className="flex flex-col gap-2">
                        <h2 className="text-sm font-semibold">
                            Reservado para cuotas históricas
                        </h2>
                        <p className="text-xs text-muted-foreground">
                            Plata del sistema anterior que ya tiene dueño: una
                            cuota de un expediente histórico. Sigue en la caja
                            hasta que se le pague; lo liberado vuelve al saldo.
                        </p>
                        <div className="overflow-hidden rounded-lg border bg-card">
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead className="bg-muted/50 text-xs tracking-wide text-muted-foreground uppercase">
                                        <tr>
                                            <th className="px-4 py-2 text-left font-medium">
                                                Fecha
                                            </th>
                                            <th className="px-4 py-2 text-left font-medium">
                                                Para
                                            </th>
                                            <th className="px-4 py-2 text-right font-medium">
                                                Reservado
                                            </th>
                                            <th className="px-4 py-2 text-right font-medium">
                                                Liberado
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {setAside.map((reserva) => (
                                            <tr key={reserva.id}>
                                                <td className="px-4 py-2 whitespace-nowrap">
                                                    {formatDate(reserva.date)}
                                                </td>
                                                <td className="px-4 py-2">
                                                    {reserva.description}
                                                </td>
                                                <td className="px-4 py-2 text-right">
                                                    <Money
                                                        value={reserva.amount}
                                                    />
                                                </td>
                                                <td className="px-4 py-2 text-right">
                                                    <Money
                                                        value={reserva.released}
                                                        dimWhenZero
                                                    />
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </section>
                )}
            </div>
        </EnMoneda>
    );
}

/**
 * Un saldo de la caja, con la parte que es del sistema anterior.
 *
 * El saldo mezcla plata vieja con la que entró después; lo que se reserva
 * del sistema anterior sale solo de la parte vieja.
 */
function Saldo({
    titulo,
    valor,
    delAnterior,
}: {
    titulo: string;
    valor: string;
    delAnterior: string;
}) {
    return (
        <div className="rounded-lg border bg-card p-4">
            <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {titulo}
            </p>
            <p className="mt-2 text-2xl">
                <Money value={valor} dimWhenZero />
            </p>
            <p className="mt-2 text-xs text-muted-foreground">
                Del sistema anterior, sin reservar:{' '}
                <Money value={delAnterior} />
            </p>
        </div>
    );
}
