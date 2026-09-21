import { useId, useState } from 'react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { amountInWords } from '@/lib/amount-words';
import { money, parseAmount } from '@/lib/format';
import { cn } from '@/lib/utils';

type Props = {
    id?: string;
    name?: string;
    value: string;
    onChange: (value: string) => void;
    placeholder?: string;
    required?: boolean;
    disabled?: boolean;
    className?: string;
    /**
     * Dónde se muestra el importe en letras.
     *
     * `below` ocupa un renglón propio bajo el campo, reservado aunque esté
     * vacío para que aparecer no mueva nada. Es lo que corresponde cuando
     * el campo tiene lugar alrededor.
     *
     * `tooltip` lo muestra flotando mientras el campo tiene el foco. Es lo
     * que corresponde dentro de una tabla: en el flujo cambiaría el alto de
     * la fila al entrar y salir, y un elemento posicionado a mano lo
     * recortaría el `overflow` del contenedor que permite desplazarla a lo
     * ancho. El tooltip se dibuja en un portal, fuera de ese contenedor, así
     * que no hay nada que lo recorte.
     */
    words?: 'below' | 'tooltip';
    'aria-label'?: string;
    'aria-invalid'?: boolean;
    'aria-describedby'?: string;
};

/**
 * Campo de importe.
 *
 * Guarda lo que el usuario tipea tal cual mientras escribe, y recién al
 * salir del campo lo normaliza a `1.204.500,00`. Formatear en cada tecla
 * pelea con el cursor —hay que recalcular dónde quedó el punto de
 * inserción después de cada agrupación— y es la causa habitual de que un
 * operador termine cargando un cero de más.
 *
 * El eco en letras hace el trabajo que el formateo no hace: «10.000.000»
 * y «100.000.000» obligan a contar grupos de tres, que es exactamente el
 * acto en el que se falla; «diez millones» y «cien millones» no se parecen
 * en nada.
 *
 * El valor viaja siempre como texto: en ningún punto de la pantalla un
 * importe se convierte a `number`.
 */
export default function AmountInput({
    value,
    onChange,
    className,
    words,
    ...props
}: Props) {
    const [enfocado, setEnfocado] = useState(false);
    const tooltipId = useId();
    const letras = amountInWords(value);
    const describedBy = [
        props['aria-describedby'],
        words === 'tooltip' && letras !== '' ? tooltipId : undefined,
    ]
        .filter(Boolean)
        .join(' ');

    const campo = (
        <div className="relative">
            <span
                aria-hidden="true"
                className="pointer-events-none absolute top-1/2 left-2.5 -translate-y-1/2 font-mono text-sm text-muted-foreground"
            >
                $
            </span>
            <input
                {...props}
                type="text"
                inputMode="decimal"
                autoComplete="off"
                value={value}
                aria-describedby={describedBy || undefined}
                onChange={(e) => onChange(e.target.value)}
                onFocus={() => setEnfocado(true)}
                onBlur={() => {
                    setEnfocado(false);

                    // Al salir del campo ya no hay cursor que respetar, así
                    // que es el momento seguro para agrupar.
                    const normalizado = parseAmount(value);

                    if (normalizado !== '') {
                        onChange(money(normalizado, { symbol: false }));
                    }
                }}
                className={cn(
                    'h-9 w-full rounded-md border bg-card py-1 pr-2.5 pl-6 text-right font-mono text-sm tabular-nums shadow-xs outline-none max-sm:min-h-11',
                    'placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring',
                    'aria-invalid:border-destructive aria-invalid:ring-destructive/30',
                    className,
                )}
            />
        </div>
    );

    if (words === 'tooltip') {
        return (
            /*
             * Abierto solo mientras el campo tiene el foco: es una ayuda
             * para quien está escribiendo, no un dato que haga falta
             * consultar después. Radix le pone `aria-describedby` al campo
             * mientras está abierto, así que un lector de pantalla también
             * lo recibe.
             */
            <Tooltip open={enfocado && letras !== ''}>
                <TooltipTrigger asChild>{campo}</TooltipTrigger>
                <TooltipContent
                    id={tooltipId}
                    side="top"
                    align="end"
                    className="max-w-80 text-pretty first-letter:uppercase"
                >
                    {letras}
                </TooltipContent>
            </Tooltip>
        );
    }

    return (
        <div>
            {campo}

            {/*
             * Dos renglones reservados y texto que envuelve, no recortado:
             * «novecientos noventa y nueve mil» no entra en el ancho del
             * campo, y cortarlo con puntos suspensivos anula justamente lo
             * que el eco venía a hacer.
             */}
            {words === 'below' && (
                <p
                    className="mt-1 min-h-8 text-right text-xs leading-4 text-balance text-muted-foreground first-letter:uppercase"
                    aria-live="polite"
                >
                    {letras}
                </p>
            )}
        </div>
    );
}
