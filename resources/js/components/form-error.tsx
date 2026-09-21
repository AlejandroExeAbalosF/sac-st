import { TriangleAlert } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * El error que no es de un campo sino del formulario entero.
 *
 * «El usuario o la contraseña no coinciden» no pertenece al campo Usuario:
 * pertenece al intento. Colgarlo del primer input hacía dos cosas mal —
 * señalaba un campo que podía estar bien, y empujaba hacia abajo todo lo que
 * seguía—. Acá arriba, el aviso aparece donde el ojo vuelve después de
 * apretar el botón y ningún campo se mueve de lugar.
 *
 * Entra con la misma transición corta que `InputError`, y con el tratamiento
 * suave de los avisos del sistema: fondo tenue y borde, no un bloque rojo.
 */
export default function FormError({
    message,
    className,
}: {
    message?: string;
    className?: string;
}) {
    if (!message) {
        return null;
    }

    return (
        <p
            role="alert"
            className={cn(
                'flex items-start gap-2 rounded-lg border border-destructive bg-destructive-soft px-3 py-2 text-sm text-destructive-strong',
                'animate-in duration-200 ease-out fade-in slide-in-from-top-1',
                className,
            )}
        >
            <TriangleAlert
                className="mt-0.5 size-4 shrink-0"
                aria-hidden="true"
            />
            {message}
        </p>
    );
}
