import { ArrowRight } from 'lucide-react';
import { cn } from '@/lib/utils';

export type AuditChange = {
    field: string;
    before: string | null;
    after: string | null;
};

/**
 * El antes y el después de un evento de auditoría, campo por campo.
 *
 * **Se muestra el antes y el después**, no solo que «hubo un cambio». La
 * pregunta real nunca es si alguien tocó algo, es qué decía antes.
 *
 * Salvo cuando no hubo un antes. Un alta o un depósito no corrigen nada, y
 * dibujarlos como «— → valor», con la raya tachada, hacía leer un cambio
 * donde solo hay un hecho.
 *
 * Lo usan el panel de historial y la auditoría de operaciones: los textos
 * ya llegan traducidos del servidor, así que acá solo se dibujan.
 */
export default function ChangeList({
    changes,
    className,
}: {
    changes: AuditChange[];
    className?: string;
}) {
    if (changes.length === 0) {
        return null;
    }

    return (
        <dl
            className={cn(
                'divide-y divide-border/60 rounded-md bg-muted/45 px-3 py-1',
                className,
            )}
        >
            {changes.map((c, indice) => (
                <div
                    // El rótulo puede repetirse —«Importe» de la cuota y el
                    // de la metadata—, así que la posición desempata.
                    key={`${c.field}-${indice}`}
                    className="grid grid-cols-[minmax(0,6.5rem)_minmax(0,1fr)] gap-x-2 py-1 text-sm"
                >
                    <dt className="text-xs leading-5 text-field-label">
                        {c.field}
                    </dt>
                    <dd className="flex min-w-0 flex-wrap items-center gap-x-1.5 gap-y-0.5 leading-5">
                        {c.before !== null && (
                            <>
                                <span className="min-w-0 break-words text-muted-foreground line-through">
                                    {c.before}
                                </span>
                                <ArrowRight
                                    className="size-3 shrink-0 text-muted-foreground"
                                    aria-hidden="true"
                                />
                            </>
                        )}
                        <span className="min-w-0 font-medium break-words">
                            {c.after ?? '—'}
                        </span>
                    </dd>
                </div>
            ))}
        </dl>
    );
}
