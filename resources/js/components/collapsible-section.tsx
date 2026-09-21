import { ChevronRight } from 'lucide-react';
import { useId, useState } from 'react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Props = {
    label: string;
    children: ReactNode;
    defaultOpen?: boolean;
    className?: string;
};

/**
 * Sección plegable de campos opcionales.
 *
 * El área pidió «cargar lo esencial» y también poder cargar todo. En vez
 * de dos formularios distintos —que serían dos juegos de validación
 * destinados a desincronizarse— lo esencial queda a la vista y el resto a
 * un clic, en la misma pantalla y con el mismo envío.
 */
export default function CollapsibleSection({
    label,
    children,
    defaultOpen = false,
    className,
}: Props) {
    const [abierto, setAbierto] = useState(defaultOpen);
    const panelId = useId();

    return (
        <div className={className}>
            <button
                type="button"
                onClick={() => setAbierto((v) => !v)}
                aria-expanded={abierto}
                aria-controls={panelId}
                className="flex items-center gap-1 rounded-md py-1 text-xs font-medium text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <ChevronRight
                    className={cn(
                        'size-3.5 transition-transform',
                        abierto && 'rotate-90',
                    )}
                    aria-hidden="true"
                />
                {label}
            </button>

            {abierto && (
                <div id={panelId} className="mt-3">
                    {children}
                </div>
            )}
        </div>
    );
}
