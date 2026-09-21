import Money from '@/components/money';
import { date as formatDate } from '@/lib/format';

export type DayActivityEntry = {
    publicId: string;
    label: string;
    amount: string;
    note: string | null;
    reversedOn: string | null;
};

/** Asientos del día: pueden existir aunque no haya comprobantes en la planilla. */
export default function DayActivity({
    entries,
}: {
    entries: DayActivityEntry[];
}) {
    if (entries.length === 0) {
        return null;
    }

    return (
        <section className="overflow-hidden rounded-lg border bg-card">
            <div className="border-b px-4 py-3">
                <h2 className="text-sm font-semibold">Actividad contable</h2>
                <p className="text-xs text-muted-foreground">
                    Estos asientos modifican los saldos, aunque no tengan un
                    comprobante en la planilla.
                </p>
            </div>
            <ul className="divide-y">
                {entries.map((entry) => (
                    <li key={entry.publicId} className="px-4 py-3 text-sm">
                        <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                            <span className="font-medium">{entry.label}</span>
                            <Money value={entry.amount} />
                        </div>
                        {entry.reversedOn && (
                            <p className="text-xs text-muted-foreground">
                                Revertido el {formatDate(entry.reversedOn)}
                            </p>
                        )}
                        {entry.note && (
                            <p className="mt-1 text-xs text-muted-foreground">
                                {entry.note}
                            </p>
                        )}
                    </li>
                ))}
            </ul>
        </section>
    );
}
