import { Link } from '@inertiajs/react';
import { ArrowRight, CheckCircle2 } from 'lucide-react';

type WorkQueue = App.Modules.Shared.Data.WorkQueueData;

export default function QueueList({ queues }: { queues: WorkQueue[] }) {
    const pending = queues.filter(
        (queue) => queue.count !== null && queue.count > 0,
    );

    if (pending.length === 0) {
        return (
            <div className="flex items-center gap-3 rounded-lg border border-dashed bg-card px-5 py-6 text-sm text-muted-foreground">
                <CheckCircle2
                    className="size-5 shrink-0 text-success-strong"
                    aria-hidden="true"
                />
                No hay tareas pendientes en este circuito.
            </div>
        );
    }

    return (
        <ul className="divide-y overflow-hidden rounded-lg border bg-card shadow-raised">
            {pending.map((queue) => (
                <li key={queue.key}>
                    {queue.href ? (
                        <Link
                            href={queue.href}
                            className="group flex min-h-20 items-center gap-4 px-5 py-4 transition-colors hover:bg-accent/35 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset"
                        >
                            <QueueContent queue={queue} showSamples={false} />
                            <ArrowRight
                                className="ml-auto size-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5"
                                aria-hidden="true"
                            />
                        </Link>
                    ) : (
                        <div className="flex min-h-20 items-start gap-4 px-5 py-4">
                            <QueueContent queue={queue} showSamples />
                        </div>
                    )}
                </li>
            ))}
        </ul>
    );
}

function QueueContent({
    queue,
    showSamples,
}: {
    queue: WorkQueue;
    showSamples: boolean;
}) {
    return (
        <>
            <span className="min-w-10 text-right text-xl font-semibold tabular-nums">
                {queue.count}
                {queue.hasMore ? '+' : ''}
            </span>
            <span className="min-w-0">
                <span className="block text-sm font-semibold">
                    {queue.title}
                </span>
                <span className="mt-0.5 block text-xs text-muted-foreground">
                    {queue.description}
                </span>
                {showSamples && queue.samples.length > 0 && (
                    <span className="mt-2 flex flex-wrap gap-x-3 gap-y-1">
                        {queue.samples.slice(0, 3).map((sample) => (
                            <Link
                                key={sample.href}
                                href={sample.href}
                                className="text-xs font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                {sample.label}
                            </Link>
                        ))}
                    </span>
                )}
            </span>
        </>
    );
}
