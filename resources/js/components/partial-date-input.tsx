import { useId, useState } from 'react';
import { PatternFormat } from 'react-number-format';
import DateInput from '@/components/date-input';
import { Checkbox } from '@/components/ui/checkbox';
import {
    digitsToMonth,
    isMonthOnly,
    monthToDigits,
    parsePastedMonth,
} from '@/lib/partial-date-input';
import { cn } from '@/lib/utils';

type Props = {
    id?: string;
    /** `AAAA-MM-DD`, `AAAA-MM` o vacío. */
    value: string;
    onChange: (value: string) => void;
    /** Arranca en «solo el mes» cuando está vacía: un pago anterior se conoce por el mes. */
    monthFirst?: boolean;
    max?: string;
    className?: string;
    'aria-invalid'?: boolean;
};

/**
 * Fecha de la que puede saberse solo el mes: la resolución, el acta o el
 * pago anterior de los que el papel no dice el día.
 *
 * Por defecto es el campo de fecha del sistema —dd/mm/aaaa, calendario y
 * pegado—. «Solo sé el mes» lo cambia por `mm/aaaa`. Al servidor llega lo
 * de siempre (`PartialDateRule`): `2026-05-07` o `2026-05`.
 *
 * Al marcar la casilla, una fecha completa conserva su mes; al desmarcarla,
 * un mes se vacía, porque el día no se inventa.
 */
export default function PartialDateInput({
    id,
    value,
    onChange,
    monthFirst = false,
    max,
    className,
    ...props
}: Props) {
    const casilla = useId();
    const [soloMes, setSoloMes] = useState(
        isMonthOnly(value) || (value === '' && monthFirst),
    );
    const [digitos, setDigitos] = useState(() => monthToDigits(value));
    const [anterior, setAnterior] = useState(value);

    // La pantalla cambió el valor por su cuenta: se muestra el nuevo.
    if (value !== anterior) {
        setAnterior(value);

        if (value !== digitsToMonth(digitos)) {
            setDigitos(monthToDigits(value));
        }
    }

    const emitirMes = (nuevos: string) => {
        setDigitos(nuevos);

        const mes = digitsToMonth(nuevos);

        if (mes !== value) {
            onChange(mes);
        }
    };

    const cambiarModo = (mes: boolean) => {
        setSoloMes(mes);

        if (mes && !isMonthOnly(value)) {
            const conservado = value === '' ? '' : value.slice(0, 7);
            setDigitos(monthToDigits(conservado));
            onChange(conservado);
        }

        if (!mes && isMonthOnly(value)) {
            setDigitos('');
            onChange('');
        }
    };

    return (
        <div className={cn('grid gap-1.5', className)}>
            {soloMes ? (
                <PatternFormat
                    id={id}
                    format="##/####"
                    mask="_"
                    value={digitos}
                    valueIsNumericString
                    placeholder="mm/aaaa"
                    inputMode="numeric"
                    autoComplete="off"
                    aria-invalid={props['aria-invalid'] || undefined}
                    onValueChange={(valores, origen) => {
                        if (origen.source === 'event') {
                            emitirMes(valores.value);
                        }
                    }}
                    onPaste={(e) => {
                        const mes = parsePastedMonth(
                            e.clipboardData.getData('text'),
                        );

                        if (mes !== null) {
                            e.preventDefault();
                            emitirMes(monthToDigits(mes));
                        }
                    }}
                    className={cn(
                        'flex h-9 w-full max-w-40 min-w-0 rounded-md border border-input bg-transparent px-3 py-1 font-mono text-base tabular-nums shadow-xs transition-[color,box-shadow] outline-none max-sm:min-h-11 md:text-sm',
                        'placeholder:font-sans placeholder:text-muted-foreground',
                        'focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50',
                        'aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40',
                    )}
                />
            ) : (
                <DateInput
                    id={id}
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    max={max}
                    aria-invalid={props['aria-invalid']}
                />
            )}
            <label
                htmlFor={casilla}
                className="flex items-center gap-2 text-xs text-muted-foreground"
            >
                <Checkbox
                    id={casilla}
                    checked={soloMes}
                    onCheckedChange={(marcada) => cambiarModo(marcada === true)}
                />
                Solo sé el mes
            </label>
        </div>
    );
}
