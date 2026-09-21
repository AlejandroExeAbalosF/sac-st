import { createContext, use } from 'react';
import type { ReactNode } from 'react';
import { isNegative, money } from '@/lib/format';
import type { CurrencyCode, DecimalString } from '@/lib/format';
import { cn } from '@/lib/utils';

/**
 * La moneda de la pantalla, para no repetirla en cada importe.
 *
 * Una pantalla que muestra un solo libro —la Caja en dólares, por
 * ejemplo— la declara una vez arriba y todos los importes de adentro
 * salen con el símbolo que corresponde. La alternativa era pasar
 * `currency` a los treinta y seis importes de la Caja y confiar en no
 * olvidarse de ninguno; olvidarse de uno es mostrar dólares con cara de
 * pesos.
 *
 * Los pesos son el valor por omisión: el resto del sistema no sabe de
 * esto y sigue funcionando igual.
 */
const MonedaDeLaPantalla = createContext<CurrencyCode>('ARS');

/** La moneda de la pantalla, para lo que no es un importe: un enlace, un rótulo. */
export function useMoneda(): CurrencyCode {
    return use(MonedaDeLaPantalla);
}

export function EnMoneda({
    moneda,
    children,
}: {
    moneda: CurrencyCode;
    children: ReactNode;
}) {
    return <MonedaDeLaPantalla value={moneda}>{children}</MonedaDeLaPantalla>;
}

type Props = {
    value: DecimalString | null | undefined;
    /** Solo cuando el importe no es de la moneda de la pantalla. */
    currency?: CurrencyCode;
    /** Atenúa el importe cuando es cero y eso no requiere atención. */
    dimWhenZero?: boolean;
    className?: string;
};

/**
 * Un importe en pantalla.
 *
 * Monoespaciada y `tabular-nums` para que los dígitos alineen en columna:
 * es lo que permite ver de un vistazo que a un importe le sobra un cero.
 * El formato lo decide `lib/format.ts`; acá solo se resuelve el aspecto.
 */
export default function Money({
    value,
    currency,
    dimWhenZero = false,
    className,
}: Props) {
    const deLaPantalla = use(MonedaDeLaPantalla);
    const moneda = currency ?? deLaPantalla;
    const vacio = value === null || value === undefined;
    const cero = !vacio && !/[1-9]/.test(value);

    return (
        <span
            className={cn(
                'font-mono whitespace-nowrap tabular-nums',
                isNegative(value) && 'text-destructive-strong',
                ((dimWhenZero && cero) || vacio) && 'text-muted-foreground',
                className,
            )}
        >
            {money(value, { currency: moneda })}
        </span>
    );
}
