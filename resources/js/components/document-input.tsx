import { useLayoutEffect, useRef } from 'react';
import { Input } from '@/components/ui/input';
import { documentMask } from '@/lib/format';
import { cn } from '@/lib/utils';

/**
 * Qué hace válido a cada número.
 *
 * Viven acá, al lado del campo que los recibe, para que la explicación que
 * lee el operador y la regla que aplica el servidor no se cuenten dos
 * historias distintas.
 */
export const AYUDA_CUIT = (
    <>
        Once dígitos. El de una empresa u organismo empieza en{' '}
        <strong className="font-semibold">30, 33 o 34</strong>, y el último es
        un verificador que se calcula sobre los diez anteriores: si no cierra,
        el número está mal copiado.
    </>
);

export const AYUDA_DNI = (
    <>
        El DNI, de <strong className="font-semibold">6 a 8</strong> dígitos. O
        el CUIL entero: once dígitos que empiezan en{' '}
        <strong className="font-semibold">20, 23, 24 o 27</strong>. Del CUIL se
        guarda el DNI que tiene adentro, y su verificador también tiene que
        cerrar.
    </>
);

type Props = Omit<
    React.ComponentProps<typeof Input>,
    'value' | 'onChange' | 'type' | 'maxLength'
> & {
    /** Solo los dígitos: es lo que viaja al servidor. */
    value: string;
    onChange: (digits: string) => void;
    /** Ocho para un DNI, once cuando además se acepta un CUIT o un CUIL. */
    maxDigits?: number;
};

/**
 * Campo de documento con separadores.
 *
 * El expediente trae el número escrito como se lee —`20-29939415-9`,
 * `29.939.415`— y tipearlo de corrido obliga a cotejarlo dígito por dígito
 * contra el papel. La máscara lo agrupa mientras se escribe; el estado y lo
 * que se envía siguen siendo dígitos pelados.
 *
 * El cursor se repone contando dígitos, no caracteres: sin eso, corregir
 * una cifra del medio manda el cursor al final en cada tecla.
 */
export default function DocumentInput({
    value,
    onChange,
    maxDigits = 11,
    className,
    ...props
}: Props) {
    const input = useRef<HTMLInputElement>(null);
    const cursor = useRef<number | null>(null);

    useLayoutEffect(() => {
        if (cursor.current === null || input.current === null) {
            return;
        }

        input.current.setSelectionRange(cursor.current, cursor.current);
        cursor.current = null;
    });

    const handleChange = (event: React.ChangeEvent<HTMLInputElement>) => {
        const escrito = event.target.value;
        const digitosAntesDelCursor = escrito
            .slice(0, event.target.selectionStart ?? escrito.length)
            .replace(/\D/g, '').length;

        const digitos = escrito.replace(/\D/g, '').slice(0, maxDigits);

        cursor.current = posicionTrasDigito(
            documentMask(digitos),
            Math.min(digitosAntesDelCursor, digitos.length),
        );

        onChange(digitos);
    };

    return (
        <Input
            {...props}
            ref={input}
            type="text"
            inputMode="numeric"
            autoComplete="off"
            value={documentMask(value)}
            onChange={handleChange}
            className={cn('font-mono tabular-nums', className)}
        />
    );
}

/** Dónde queda el cursor después de escribir el dígito número `n`. */
function posicionTrasDigito(mascara: string, n: number): number {
    if (n === 0) {
        return 0;
    }

    let vistos = 0;

    for (let i = 0; i < mascara.length; i++) {
        if (/\d/.test(mascara[i])) {
            vistos++;

            if (vistos === n) {
                return i + 1;
            }
        }
    }

    return mascara.length;
}
