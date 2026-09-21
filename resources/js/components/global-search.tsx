import { Search } from 'lucide-react';
import { index as haberes } from '@/routes/expedientes';

/**
 * Búsqueda global.
 *
 * Es la acción número uno del tablero: el operador llega con un número de
 * expediente anotado en un papel. El diseño del área la pone arriba de
 * todo y tiene razón.
 *
 * Va contra el listado de expedientes, que ya sabe buscar por número,
 * carátula, empleador, beneficiario y documento (`Expediente::buscar`).
 * Un `<form method="get">` y no una llamada aparte: el resultado queda en
 * una dirección que se puede compartir y que sobrevive al refresco, que
 * es exactamente lo que el listado ya hace con `?q=`.
 */
export default function GlobalSearch({
    disabled = false,
}: {
    disabled?: boolean;
}) {
    return (
        <form action={haberes().url} method="get" className="max-w-xl">
            {/*
             * El contenedor posicionado envuelve SOLO al campo. Cuando
             * también encerraba a la leyenda de abajo, `top-1/2` centraba
             * la lupa respecto de la altura total —campo más leyenda— y el
             * ícono quedaba desplazado hacia abajo.
             */}
            <div className="relative">
                <Search
                    className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    aria-hidden="true"
                />
                <input
                    type="search"
                    name="q"
                    disabled={disabled}
                    aria-label="Buscar por número de expediente, beneficiario, CUIT o recibo"
                    placeholder="Buscar por expediente, beneficiario, CUIT o recibo…"
                    className="h-10 w-full rounded-lg border bg-card pr-3 pl-9 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:bg-muted/50 max-sm:min-h-11"
                />
            </div>

            {disabled && (
                <p className="mt-1.5 text-xs text-muted-foreground">
                    La búsqueda se habilita cuando puedas ver expedientes.
                </p>
            )}
        </form>
    );
}
