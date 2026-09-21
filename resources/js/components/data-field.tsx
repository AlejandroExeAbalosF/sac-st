import type { ReactNode } from 'react';

/**
 * Un renglón de ficha: su rótulo y su valor.
 *
 * La del expediente y la del haber son la misma tarjeta con otros campos,
 * y hasta acá cada una escribía su propio `dt`/`dd`. Con el renglón en un
 * solo lugar no pueden verse distinto ni empezar a separarse cuando una de
 * las dos cambie.
 *
 * Va dentro de un `<dl>`: el `div` intermedio es lo que HTML permite para
 * agrupar el par sin romper la lista de definiciones.
 */
export default function DataField({
    label,
    children,
    className,
}: {
    label: string;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div className={className}>
            <dt className="text-xs tracking-wide text-field-label uppercase">
                {label}
            </dt>
            <dd className="mt-0.5 text-sm">{children}</dd>
        </div>
    );
}

/** El renglón que quedó en blanco, dicho con todas las letras. */
export function EmptyValue({
    children = 'Sin cargar',
}: {
    children?: ReactNode;
}) {
    return <span className="text-muted-foreground italic">{children}</span>;
}
