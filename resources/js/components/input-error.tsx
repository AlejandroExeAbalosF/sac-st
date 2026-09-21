import type { HTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

/**
 * El error de un campo, debajo de su input.
 *
 * Entra con una transición corta —se desvanece y baja un par de píxeles— en
 * vez de aparecer de golpe: el mensaje sigue empujando lo que tiene abajo,
 * pero el salto deja de leerse como un tirón de la tarjeta.
 *
 * No reserva espacio cuando no hay error, y es a propósito: el componente lo
 * usan ciento y pico de campos, y dejar un hueco fijo debajo de cada uno para
 * un mensaje que casi nunca aparece afloja todos los formularios del sistema.
 * Cuando el error no pertenece a un campo sino al formulario entero —«el
 * usuario o la contraseña no coinciden»— va arriba, como aviso, y entonces
 * ningún campo se mueve.
 *
 * `role="alert"` para que el lector de pantalla lo anuncie al aparecer, que
 * es justo lo que la animación se encarga de que no pase desapercibido.
 */
export default function InputError({
    message,
    className = '',
    ...props
}: HTMLAttributes<HTMLParagraphElement> & { message?: string }) {
    return message ? (
        <p
            {...props}
            role="alert"
            className={cn(
                'animate-in text-sm text-destructive-strong duration-200 ease-out fade-in slide-in-from-top-1',
                className,
            )}
        >
            {message}
        </p>
    ) : null;
}
