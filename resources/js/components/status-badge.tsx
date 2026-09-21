import { cn } from '@/lib/utils';

/**
 * Los cuatro tratamientos visuales que admite un estado.
 *
 * Deliberadamente pocos. El sistema tiene muchos estados y darle un color
 * propio a cada uno los volvería indistinguibles; lo que el operador
 * necesita saber de un vistazo es otra cosa: si algo está bien, si espera
 * una acción suya, si está trabado, o si es apenas informativo.
 */
export type StatusTone = 'neutral' | 'progress' | 'action' | 'blocked' | 'done';

const TONO: Record<StatusTone, string> = {
    neutral: 'bg-muted text-muted-foreground border-border',
    progress: 'bg-info-soft text-info-strong border-transparent',
    action: 'bg-warning-soft text-warning-strong border-transparent',
    blocked: 'bg-destructive-soft text-destructive-strong border-current',
    done: 'bg-success-soft text-success-strong border-transparent',
};

type Props = {
    label: string;
    tone?: StatusTone;
    /** Tacha el texto: se usa en comprobantes anulados, que conservan número. */
    voided?: boolean;
    className?: string;
};

export default function StatusBadge({
    label,
    tone = 'neutral',
    voided = false,
    className,
}: Props) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-xs font-medium whitespace-nowrap',
                TONO[tone],
                voided && 'line-through opacity-70',
                className,
            )}
        >
            {/*
             * El punto no es adorno: acompaña al color para que el estado
             * se distinga sin depender de percibirlo, que es el requisito
             * de accesibilidad que un badge sólo-color no cumple.
             */}
            <span
                aria-hidden="true"
                className="size-1.5 shrink-0 rounded-full bg-current"
            />
            {label}
        </span>
    );
}
