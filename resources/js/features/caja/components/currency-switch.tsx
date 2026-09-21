import { Link } from '@inertiajs/react';
import type { CurrencyCode } from '@/lib/format';
import { cn } from '@/lib/utils';

const MONEDAS: { code: CurrencyCode; label: string }[] = [
    { code: 'ARS', label: 'Pesos' },
    { code: 'USD', label: 'Dólares' },
];

/**
 * Cuál de los dos libros se está mirando.
 *
 * El cajón es uno y guarda las dos monedas, pero la contabilidad las
 * separa entera: dos arqueos por día, dos cierres, dos saldos. Por eso el
 * control cambia de libro en vez de filtrar una lista — no hay una vista
 * con las dos monedas juntas, y no debería haberla: sumar pesos con
 * dólares no significa nada.
 *
 * Se muestra siempre, incluso con el libro en dólares vacío. Esconderlo
 * hasta que hubiera movimientos dejaría sin puerta de entrada al primero.
 */
export default function SelectorDeMoneda({
    moneda,
    href,
}: {
    moneda: CurrencyCode;
    /** La misma pantalla, en la otra moneda. */
    href: (moneda: CurrencyCode) => string;
}) {
    return (
        <div
            className="flex items-center rounded-md border bg-card p-0.5"
            role="group"
            aria-label="Moneda"
        >
            {MONEDAS.map(({ code, label }) => {
                const activa = code === moneda;

                return (
                    <Link
                        key={code}
                        href={href(code)}
                        aria-current={activa ? 'true' : undefined}
                        preserveScroll
                        className={cn(
                            'rounded-sm px-2.5 py-1 text-xs font-medium transition-colors',
                            'focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                            activa
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:text-foreground',
                        )}
                    >
                        {label}
                    </Link>
                );
            })}
        </div>
    );
}
