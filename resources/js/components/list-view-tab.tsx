import { cn } from '@/lib/utils';

/**
 * Una pestaña de un listado con dos lecturas del mismo material —por
 * expediente o por haber, por expediente o por multa—.
 *
 * Es un botón y no un enlace porque cambiar de vista conserva lo que ya
 * está escrito en el buscador —la consulta viaja con `preserveState`—,
 * y `aria-current` es lo que le dice al lector de pantalla cuál de las
 * dos se está viendo.
 */
export default function ListViewTab({
    active,
    onClick,
    children,
}: {
    active: boolean;
    onClick: () => void;
    children: React.ReactNode;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-current={active ? 'page' : undefined}
            className={cn(
                '-mb-px border-b-2 px-3 py-2 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                active
                    ? 'border-primary text-primary'
                    : 'border-transparent text-muted-foreground hover:border-border hover:text-foreground',
            )}
        >
            {children}
        </button>
    );
}
