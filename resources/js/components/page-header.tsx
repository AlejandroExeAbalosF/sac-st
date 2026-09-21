import type { ReactNode } from 'react';

type Props = {
    title: string;
    /** Línea de contexto encima del título: fecha operativa, caja, expediente. */
    eyebrow?: ReactNode;
    description?: ReactNode;
    /** Acciones principales de la pantalla, alineadas a la derecha. */
    actions?: ReactNode;
};

/**
 * Encabezado de pantalla.
 *
 * Un solo lugar define la jerarquía de todas las pantallas del sistema:
 * contexto arriba, título, bajada, y las acciones a la derecha.
 */
export default function PageHeader({
    title,
    eyebrow,
    description,
    actions,
}: Props) {
    return (
        <div className="flex flex-wrap items-end gap-6">
            <div className="min-w-0">
                {/*
                 * Versalitas con el acento del sistema, no gris. Le da
                 * carácter al encabezado sin ocupar más lugar, y separa
                 * con claridad el contexto del título.
                 */}
                {eyebrow && (
                    <p className="mb-1.5 text-[0.7rem] font-semibold tracking-[0.11em] text-primary uppercase">
                        {eyebrow}
                    </p>
                )}
                <h1 className="text-xl font-semibold tracking-tight text-balance">
                    {title}
                </h1>
                {description && (
                    <p className="mt-1 text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>

            {actions && (
                <div className="ml-auto flex flex-wrap gap-2">{actions}</div>
            )}
        </div>
    );
}
