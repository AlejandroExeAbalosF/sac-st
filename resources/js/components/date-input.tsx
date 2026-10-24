import { CalendarDays } from 'lucide-react';
import { useRef, useState } from 'react';
import { PatternFormat } from 'react-number-format';
import { digitsToIso, isoToDigits, parsePastedDate } from '@/lib/date-input';
import { cn } from '@/lib/utils';

/**
 * Lo que recibe `onChange`: la fecha en `AAAA-MM-DD`, o vacío.
 *
 * Tiene la forma de un evento —`e.target.value`— para que el campo
 * reemplace a `<Input type="date">` sin cambiar cómo cada pantalla lee el
 * valor.
 */
export type DateChange = { target: { value: string } };

type Props = {
    id?: string;
    /** La fecha en `AAAA-MM-DD`, o vacío. */
    value: string;
    onChange: (e: DateChange) => void;
    min?: string;
    max?: string;
    required?: boolean;
    autoFocus?: boolean;
    disabled?: boolean;
    className?: string;
    'aria-label'?: string;
    'aria-invalid'?: boolean;
    'aria-describedby'?: string;
};

/**
 * Campo de fecha: se escribe o se pega `dd/mm/aaaa`, y el botón abre el
 * calendario del navegador.
 *
 * No es el `<input type="date">` nativo porque Chrome no le entrega el
 * pegado: con el foco en el día, el mes o el año, el evento `paste` no llega
 * al campo, y no hay manejador que pueda leerlo. Un campo de texto lo recibe
 * siempre. Lo que se pega pasa por `parsePastedDate`, así que `6/6/23` o
 * una fecha con hora también entran; lo que se tipea va cifra por cifra
 * sobre la máscara, con las barras puestas.
 *
 * El calendario sigue siendo el nativo —en el celular, el selector del
 * sistema—: un `<input type="date">` invisible que el botón abre con
 * `showPicker()` y que respeta `min` y `max`.
 *
 * Mientras faltan cifras, o si la fecha no existe —`31/02/2023`—, el
 * formulario recibe vacío: nunca una fecha a medias.
 */
export default function DateInput({
    value,
    onChange,
    min,
    max,
    disabled,
    className,
    ...props
}: Props) {
    const [digitos, setDigitos] = useState(() => isoToDigits(value));
    const [anterior, setAnterior] = useState(value);
    const calendario = useRef<HTMLInputElement>(null);

    // La pantalla cambió la fecha por su cuenta —un reset, un valor
    // precargado—: se muestra la nueva. Si es la que el campo acaba de
    // emitir, lo escrito se conserva, aunque esté a medias.
    if (value !== anterior) {
        setAnterior(value);

        if (value !== digitsToIso(digitos)) {
            setDigitos(isoToDigits(value));
        }
    }

    const emitir = (nuevos: string) => {
        setDigitos(nuevos);

        const iso = digitsToIso(nuevos);

        if (iso !== value) {
            onChange({ target: { value: iso } });
        }
    };

    const inexistente = digitos.length === 8 && digitsToIso(digitos) === '';

    return (
        <div className={cn('relative', className)}>
            <PatternFormat
                {...props}
                format="##/##/####"
                mask="_"
                value={digitos}
                valueIsNumericString
                placeholder="dd/mm/aaaa"
                inputMode="numeric"
                autoComplete="off"
                disabled={disabled}
                aria-invalid={props['aria-invalid'] || inexistente || undefined}
                onValueChange={(valores, origen) => {
                    if (origen.source === 'event') {
                        emitir(valores.value);
                    }
                }}
                onPaste={(e) => {
                    const fecha = parsePastedDate(
                        e.clipboardData.getData('text'),
                    );

                    // Lo que no se lee como fecha lo procesa la máscara,
                    // que se queda con las cifras.
                    if (fecha === null) {
                        return;
                    }

                    e.preventDefault();
                    emitir(isoToDigits(fecha));
                }}
                className={cn(
                    'flex h-9 w-full min-w-0 rounded-md border border-input bg-transparent py-1 pr-9 pl-3 font-mono text-base tabular-nums shadow-xs transition-[color,box-shadow] outline-none max-sm:min-h-11 md:text-sm',
                    'placeholder:font-sans placeholder:text-muted-foreground disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50',
                    'focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50',
                    'aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40',
                )}
            />

            <button
                type="button"
                disabled={disabled}
                aria-label="Elegir en el calendario"
                title="Elegir en el calendario"
                onClick={() => {
                    try {
                        calendario.current?.showPicker();
                    } catch {
                        // Sin `showPicker` —un navegador viejo— el campo
                        // sigue andando: se escribe o se pega.
                    }
                }}
                className="absolute top-1/2 right-1 flex size-7 -translate-y-1/2 items-center justify-center rounded-sm text-muted-foreground hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:pointer-events-none disabled:opacity-50"
            >
                <CalendarDays className="size-4" />
            </button>

            {/*
             * Invisible pero dibujado, a lo ancho del pie del campo: así
             * `showPicker()` puede abrirlo y el navegador ubica el
             * calendario debajo del campo.
             */}
            <input
                ref={calendario}
                type="date"
                tabIndex={-1}
                aria-hidden="true"
                value={value}
                min={min}
                max={max}
                disabled={disabled}
                onChange={(e) => emitir(isoToDigits(e.target.value))}
                className="pointer-events-none absolute inset-x-0 bottom-0 h-px opacity-0"
            />
        </div>
    );
}
