import { useId, useState } from 'react';
import { NumericFormat } from 'react-number-format';
import { useMoneda } from '@/components/money';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    amountFromPaste,
    amountOnBlur,
    amountToNumeric,
} from '@/lib/amount-input';
import { amountInWords } from '@/lib/amount-words';
import { cn } from '@/lib/utils';

type Props = {
    id?: string;
    name?: string;
    /**
     * El importe: el decimal del servidor (`2034800.00`), lo que el campo
     * emitió, o un importe ya formateado. Ver `amountToNumeric`.
     */
    value: string;
    /** Siempre un decimal en texto con punto, `2034800.5`, o vacío. */
    onChange: (value: string) => void;
    placeholder?: string;
    required?: boolean;
    disabled?: boolean;
    readOnly?: boolean;
    /** Solo donde un importe puede ser negativo. Por defecto, no. */
    allowNegative?: boolean;
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
 * Agrupa los miles mientras se escribe —`2.034.800,00`— y guarda el decimal
 * con punto —`2034800.00`—: la presentación y el dato no se mezclan. El
 * motor es `react-number-format`, que recalcula dónde queda el cursor
 * después de cada agrupación; formatear a mano en cada tecla perdía el
 * cursor, y era la causa habitual de que un operador terminara cargando un
 * cero de más.
 *
 * El punto —del teclado común o del numérico— escribe la coma decimal: es
 * la tecla que el operador tiene a mano, y la librería lo tomaría como
 * separador de miles. Por lo mismo, el pegado no pasa por la librería (ver
 * `amountFromPaste`).
 *
 * El eco en letras hace lo que el agrupado no alcanza: «10.000.000» y
 * «100.000.000» obligan a contar grupos de tres, que es exactamente el acto
 * en el que se falla; «diez millones» y «cien millones» no se parecen en
 * nada.
 *
 * El valor viaja siempre como texto: se lee `values.value`, nunca
 * `floatValue`.
 */
export default function AmountInput({
    value,
    onChange,
    className,
    words,
    allowNegative = false,
    ...props
}: Props) {
    const [enfocado, setEnfocado] = useState(false);
    // La moneda de la pantalla, como `Money`: en la apertura en dólares el
    // campo no puede decir «$».
    const dolares = useMoneda() === 'USD';
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
                {dolares ? 'US$' : '$'}
            </span>
            <NumericFormat
                {...props}
                value={amountToNumeric(value)}
                valueIsNumericString
                thousandSeparator="."
                decimalSeparator=","
                allowedDecimalSeparators={[',']}
                decimalScale={2}
                allowNegative={allowNegative}
                inputMode="decimal"
                autoComplete="off"
                aria-describedby={describedBy || undefined}
                onValueChange={(valores, origen) => {
                    // Solo lo que hizo el operador: el valor que llega por
                    // prop ya es el de la pantalla, y devolverlo la
                    // re-renderizaría en bucle.
                    if (origen.source === 'event') {
                        onChange(valores.value);
                    }
                }}
                onKeyDown={(e) => {
                    if (e.key !== '.' && e.key !== 'Decimal') {
                        return;
                    }

                    /*
                     * El punto se escribe como la coma decimal. Con el punto
                     * como separador de miles, la librería lo descarta y
                     * junta las cifras: `1500.25` quedaba en ciento
                     * cincuenta mil veinticinco, sin aviso. Se inserta la
                     * coma como si se la hubiera tecleado, así la librería
                     * decide lo de siempre —si ya hay decimales, dónde queda
                     * el cursor— en vez de que el valor llegue por prop y el
                     * cursor quede antes de la coma.
                     */
                    e.preventDefault();
                    document.execCommand('insertText', false, ',');
                }}
                onPaste={(e) => {
                    const pegado = amountFromPaste(
                        e.clipboardData.getData('text'),
                    );

                    if (pegado === null) {
                        return;
                    }

                    e.preventDefault();
                    onChange(allowNegative ? pegado : pegado.replace(/^-/, ''));
                }}
                onFocus={() => setEnfocado(true)}
                onBlur={() => {
                    setEnfocado(false);

                    // Al salir, los centavos completos: `2034800.5` pasa a
                    // `2034800.50` y se ve `2.034.800,50`.
                    const completo = amountOnBlur(value);

                    if (completo !== value) {
                        onChange(completo);
                    }
                }}
                className={cn(
                    'h-9 w-full rounded-md border bg-card py-1 pr-2.5 text-right font-mono text-sm tabular-nums shadow-xs outline-none max-sm:min-h-11',
                    dolares ? 'pl-10' : 'pl-6',
                    'placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring',
                    'aria-invalid:border-destructive aria-invalid:ring-destructive/30',
                    'read-only:bg-muted/50 read-only:text-muted-foreground',
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
