/**
 * El importe de un renglón mientras se carga. Lo decide el servidor: esto es lo
 * que se muestra antes de enviarlo, con las mismas cuentas y sin `number`, para
 * que un decimal no cambie un centavo.
 */

type Tipo = App.Modules.Aranceles.Data.FeeTypeData;
type Precio = App.Modules.Aranceles.Data.FeeTypePriceData;

/** Un decimal en texto a enteros de la escala pedida. Nulo si no es un número. */
function aEntero(valor: string, decimales: number): bigint | null {
    const limpio = valor.trim();

    if (!/^\d+([.,]\d+)?$/.test(limpio)) {
        return null;
    }

    const [entero, fraccion = ''] = limpio.replace(',', '.').split('.');

    return BigInt(entero + fraccion.padEnd(decimales, '0').slice(0, decimales));
}

function aDecimal(centavos: bigint): string {
    const texto = centavos.toString().padStart(3, '0');

    return `${texto.slice(0, -2)}.${texto.slice(-2)}`;
}

/** El precio que regía ese día: el último vigente que ya había empezado. */
export function precioEn(tipo: Tipo, fecha: string): Precio | null {
    return (
        tipo.prices
            .filter((p) => p.status !== 'voided' && p.validFrom <= fecha)
            .sort((a, b) => b.validFrom.localeCompare(a.validFrom))[0] ?? null
    );
}

/** Cantidad por precio, o el porcentaje del monto al centavo más cercano. */
export function importeCalculado(
    tipo: Tipo,
    precio: Precio | null,
    cantidad: string,
    montoBase: string,
): string | null {
    if (precio === null) {
        return null;
    }

    if (tipo.calculationMode === 'percentage') {
        const base = aEntero(montoBase, 2);
        const tasa = aEntero(precio.rate ?? '', 4);

        if (base === null || tasa === null || base === 0n) {
            return null;
        }

        // Centavos por tasa con cuatro decimales, dividido 100: seis ceros.
        return aDecimal((base * tasa + 500_000n) / 1_000_000n);
    }

    const unidades = /^\d+$/.test(cantidad.trim())
        ? BigInt(cantidad.trim())
        : 0n;
    const unitario = aEntero(precio.unitPrice ?? '', 2);

    if (unidades === 0n || unitario === null) {
        return null;
    }

    return aDecimal(unitario * unidades);
}
