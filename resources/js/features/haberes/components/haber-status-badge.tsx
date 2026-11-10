import Money from '@/components/money';
import StatusBadge from '@/components/status-badge';
import {
    avanceDelHaber,
    estadoDelHaber,
} from '@/features/haberes/haber-status';

type Haber = Parameters<typeof estadoDelHaber>[0] &
    Parameters<typeof avanceDelHaber>[0];

/** El estado del haber y, mientras se paga, cuánto va pagado de lo reconocido. */
export default function HaberStatusBadge({ haber }: { haber: Haber }) {
    const avance = avanceDelHaber(haber);

    return (
        <span className="inline-flex flex-col items-start gap-0.5">
            <StatusBadge {...estadoDelHaber(haber)} />
            {avance && (
                <span className="text-xs text-muted-foreground">
                    Pagado <Money value={avance.pagado} /> de{' '}
                    <Money value={avance.reconocido} />
                </span>
            )}
        </span>
    );
}
