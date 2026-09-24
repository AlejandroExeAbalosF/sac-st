import Money from '@/components/money';
import {
    compareAmounts,
    date as formatDate,
    subtractAmounts,
} from '@/lib/format';

type Arqueo = App.Modules.Ledger.Data.CashCountListItemData;
type Linea = Arqueo['lines'][number];

export type CambioDeComposicion = {
    denomination: string;
    previousQuantity: number;
    currentQuantity: number;
    difference: number;
};

export type ComparacionDeComposicion = {
    sameAmount: boolean;
    sameComposition: boolean;
    changes: CambioDeComposicion[];
};

/** La foto completa incluye los billetes del día y los del fajo recontado. */
export function composicionCompleta(arqueo: Arqueo) {
    return [...agrupar([...arqueo.lines, ...arqueo.carryLines])]
        .map(([denomination, quantity]) => ({ denomination, quantity }))
        .sort((a, b) => compareAmounts(b.denomination, a.denomination));
}

/** Suma una columna de cantidades sin convertir los importes a punto flotante. */
export function totalEfectivoDeComposicion(
    cambios: CambioDeComposicion[],
    columna: 'previousQuantity' | 'currentQuantity',
): string {
    const centavos = cambios.reduce((total, linea) => {
        const [entero = '0', fraccion = ''] = linea.denomination.split('.');
        const denominacionEnCentavos =
            BigInt(entero) * 100n + BigInt(fraccion.padEnd(2, '0').slice(0, 2));

        return total + denominacionEnCentavos * BigInt(linea[columna]);
    }, 0n);
    const texto = centavos.toString().padStart(3, '0');

    return `${texto.slice(0, -2)}.${texto.slice(-2)}`;
}

/**
 * Compara dos fotos físicas. No intenta deducir qué billetes deberían quedar:
 * los egresos actuales registran importes, no denominaciones.
 */
export function compararComposicion(
    arqueo: Arqueo,
    referencia: Arqueo,
): ComparacionDeComposicion {
    const anterior = agrupar([...referencia.lines, ...referencia.carryLines]);
    const actual = agrupar(arqueo.carryLines);
    const denominaciones = new Set([...anterior.keys(), ...actual.keys()]);

    const changes = [...denominaciones]
        .map((denomination) => {
            const previousQuantity = anterior.get(denomination) ?? 0;
            const currentQuantity = actual.get(denomination) ?? 0;

            return {
                denomination,
                previousQuantity,
                currentQuantity,
                difference: currentQuantity - previousQuantity,
            };
        })
        .sort((a, b) => compareAmounts(b.denomination, a.denomination));

    return {
        sameAmount:
            compareAmounts(
                arqueo.carryCountedAmount ?? '0.00',
                referencia.countedAmount,
            ) === 0,
        sameComposition: changes.every((linea) => linea.difference === 0),
        changes,
    };
}

function agrupar(lineas: Linea[]): Map<string, number> {
    const cantidades = new Map<string, number>();

    for (const linea of lineas) {
        cantidades.set(
            linea.denomination,
            (cantidades.get(linea.denomination) ?? 0) + linea.quantity,
        );
    }

    return cantidades;
}

export default function DetalleComposicionDelFajo({
    arqueo,
    referencia,
    comparar = true,
}: {
    arqueo: Arqueo;
    referencia?: Arqueo | null;
    comparar?: boolean;
}) {
    if (arqueo.carryRecountReason === null) {
        return null;
    }

    const comparacion =
        comparar && referencia ? compararComposicion(arqueo, referencia) : null;
    const diferenciaInternaCompensada =
        arqueo.balanced &&
        compareAmounts(arqueo.carryDifferenceAmount ?? '0.00', '0.00') !== 0;

    return (
        <section className="grid gap-3 rounded-lg border bg-muted/30 p-3 text-sm">
            <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                Saldo anterior recontado
            </p>

            <dl className="grid gap-1.5">
                <Importe
                    termino="Encontrado"
                    valor={arqueo.carryCountedAmount ?? '0.00'}
                />
                <Importe
                    termino="Según el libro"
                    valor={arqueo.carryExpectedAmount ?? '0.00'}
                />
                <div className="border-t pt-1.5">
                    <Importe
                        termino="Diferencia contra el cálculo"
                        valor={arqueo.carryDifferenceAmount ?? '0.00'}
                        fuerte
                    />
                </div>
            </dl>

            {diferenciaInternaCompensada && (
                <p className="rounded-md bg-info-soft px-3 py-2 text-xs text-info-strong">
                    El total del cajón cuadra. Esta diferencia interna se
                    compensa con el conteo del día y no representa dinero
                    faltante o sobrante.
                </p>
            )}

            <p className="text-xs text-muted-foreground">
                {arqueo.carryRecountReason}
            </p>

            {comparar && (
                <ResumenComparacion
                    referencia={referencia ?? null}
                    comparacion={comparacion}
                />
            )}

            {comparacion && referencia ? (
                <TablaComparacion cambios={comparacion.changes} />
            ) : (
                <TablaConteo lineas={arqueo.carryLines} />
            )}
        </section>
    );
}

function ResumenComparacion({
    referencia,
    comparacion,
}: {
    referencia: Arqueo | null;
    comparacion: ComparacionDeComposicion | null;
}) {
    if (referencia === null || comparacion === null) {
        return (
            <p className="rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground">
                Primer conteo completo con denominaciones disponible: todavía no
                hay una composición anterior para comparar.
            </p>
        );
    }

    const fecha = formatDate(referencia.countedOn);

    if (comparacion.sameAmount && comparacion.sameComposition) {
        return (
            <p className="rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground">
                El importe y la composición coinciden con el último conteo
                completo del {fecha}.
            </p>
        );
    }

    if (comparacion.sameAmount) {
        return (
            <p className="rounded-md bg-info-soft px-3 py-2 text-xs text-info-strong">
                El importe coincide. Cambió la composición de billetes desde el
                último conteo completo del {fecha}.
            </p>
        );
    }

    return (
        <p className="rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground">
            Comparación con el último conteo completo del {fecha}. El importe
            también cambió; los movimientos entre ambas fechas pueden
            explicarlo.
        </p>
    );
}

function TablaConteo({ lineas }: { lineas: Linea[] }) {
    if (lineas.length === 0) {
        return (
            <p className="text-xs text-muted-foreground">
                El recuento registró el saldo anterior vacío.
            </p>
        );
    }

    return (
        <table className="w-full text-sm">
            <thead>
                <tr className="text-xs text-muted-foreground">
                    <th className="text-left font-normal">Billete</th>
                    <th className="text-right font-normal">Cantidad</th>
                    <th className="text-right font-normal">Subtotal</th>
                </tr>
            </thead>
            <tbody className="divide-y">
                {lineas.map((linea) => (
                    <tr key={linea.denomination}>
                        <td className="py-1">
                            <Money value={linea.denomination} />
                        </td>
                        <td className="py-1 text-right tabular-nums">
                            {linea.quantity}
                        </td>
                        <td className="py-1 text-right">
                            <Money value={linea.subtotal} />
                        </td>
                    </tr>
                ))}
            </tbody>
            <tfoot>
                <tr className="border-t font-medium">
                    <th scope="row" className="pt-2 text-left">
                        Total de billetes
                    </th>
                    <td className="pt-2 text-right tabular-nums">
                        {lineas.reduce(
                            (total, linea) => total + linea.quantity,
                            0,
                        )}
                    </td>
                    <td />
                </tr>
            </tfoot>
        </table>
    );
}

function TablaComparacion({ cambios }: { cambios: CambioDeComposicion[] }) {
    const anterior = cambios.reduce(
        (total, linea) => total + linea.previousQuantity,
        0,
    );
    const actual = cambios.reduce(
        (total, linea) => total + linea.currentQuantity,
        0,
    );
    const efectivoAnterior = totalEfectivoDeComposicion(
        cambios,
        'previousQuantity',
    );
    const efectivoActual = totalEfectivoDeComposicion(
        cambios,
        'currentQuantity',
    );
    const cambioDeEfectivo = subtractAmounts(efectivoActual, efectivoAnterior);

    return (
        <table className="w-full text-sm">
            <thead>
                <tr className="text-xs text-muted-foreground">
                    <th className="text-left font-normal">Billete</th>
                    <th className="text-right font-normal">Anterior</th>
                    <th className="text-right font-normal">Actual</th>
                    <th className="text-right font-normal">Cambio</th>
                </tr>
            </thead>
            <tbody className="divide-y">
                {cambios.map((linea) => (
                    <tr key={linea.denomination}>
                        <td className="py-1">
                            <Money value={linea.denomination} />
                        </td>
                        <td className="py-1 text-right tabular-nums">
                            {linea.previousQuantity}
                        </td>
                        <td className="py-1 text-right tabular-nums">
                            {linea.currentQuantity}
                        </td>
                        <td className="py-1 text-right tabular-nums">
                            {linea.difference > 0 ? '+' : ''}
                            {linea.difference}
                        </td>
                    </tr>
                ))}
            </tbody>
            <tfoot>
                <tr className="border-t font-medium">
                    <th scope="row" className="pt-2 text-left">
                        Total de billetes
                    </th>
                    <td className="pt-2 text-right tabular-nums">{anterior}</td>
                    <td className="pt-2 text-right tabular-nums">{actual}</td>
                    <td className="pt-2 text-right tabular-nums">
                        {actual > anterior ? '+' : ''}
                        {actual - anterior}
                    </td>
                </tr>
                <tr className="font-medium">
                    <th scope="row" className="pt-1 text-left">
                        Total en efectivo
                    </th>
                    <td className="pt-1 text-right">
                        <Money value={efectivoAnterior} />
                    </td>
                    <td className="pt-1 text-right">
                        <Money value={efectivoActual} />
                    </td>
                    <td className="pt-1 text-right">
                        <Money value={cambioDeEfectivo} dimWhenZero />
                    </td>
                </tr>
            </tfoot>
        </table>
    );
}

function Importe({
    termino,
    valor,
    fuerte = false,
}: {
    termino: string;
    valor: string;
    fuerte?: boolean;
}) {
    return (
        <div className="flex items-baseline justify-between gap-4">
            <dt className={fuerte ? 'font-medium' : 'text-muted-foreground'}>
                {termino}
            </dt>
            <dd>
                <Money
                    value={valor}
                    className={fuerte ? 'font-semibold' : undefined}
                    dimWhenZero
                />
            </dd>
        </div>
    );
}
