import { cn } from '@/lib/utils';

type CashBox = App.Modules.Shared.Data.CashBoxSummaryData;

/**
 * Volumen de trámites por caja.
 *
 * Retoma la idea de «Trámites registrados por módulo» del diseño del
 * área, pero en una franja compacta en lugar de una tarjeta grande: es
 * información de contexto, no de operación, y no debe competir en peso
 * visual con las colas de trabajo.
 */
export default function CashBoxSummary({ boxes }: { boxes: CashBox[] }) {
    return (
        <dl className="grid gap-px overflow-hidden rounded-lg border bg-border sm:grid-cols-3">
            {boxes.map((box) => (
                <div key={box.code} className="bg-card px-5 py-4">
                    <div className="flex items-baseline gap-2">
                        <dt className="text-xs font-medium tracking-wide text-field-label uppercase">
                            {box.name}
                        </dt>
                        {box.isCurrentPhase && (
                            <span className="rounded-full bg-info-soft px-1.5 py-px text-[10px] font-medium text-info-strong">
                                en curso
                            </span>
                        )}
                    </div>

                    <dd className="mt-1 font-mono text-2xl leading-none font-semibold tabular-nums">
                        {box.count ?? (
                            <span className="text-base font-normal text-muted-foreground">
                                —
                            </span>
                        )}
                    </dd>

                    <div
                        className="mt-2 h-1 overflow-hidden rounded-full bg-muted"
                        role="presentation"
                    >
                        <div
                            className={cn(
                                'h-full rounded-full',
                                box.isCurrentPhase
                                    ? 'bg-primary'
                                    : 'bg-muted-foreground/40',
                            )}
                            style={{ width: `${box.share ?? 0}%` }}
                        />
                    </div>
                </div>
            ))}
        </dl>
    );
}
