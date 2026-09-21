import { date, dateTime } from '@/lib/format';
import { cn } from '@/lib/utils';

type LastChange = App.Modules.Shared.Data.LastChangeData;

type Props = {
    /** ISO-8601: cuándo se cargó al sistema. */
    createdAt: string;
    /** El último cambio después del alta, o `null` si no hubo ninguno. */
    lastChange: LastChange | null;
    className?: string;
};

/**
 * Cuándo se cargó y, debajo, cuándo se tocó por última vez.
 *
 * El segundo renglón **no se dibuja si no hubo cambios**. Repetir ahí la
 * fecha de carga sería un dato de mentira: diría que alguien modificó el
 * registro el día que se creó.
 *
 * La celda muestra solo el día porque es lo que se escanea; la hora y el
 * autor van al `title`, que es donde se los busca cuando hace falta
 * reconstruir qué pasó. El autor puede faltar —la auditoría admite
 * cambios sin sesión— y entonces la frase termina en la fecha.
 */
export default function RecordDates({
    createdAt,
    lastChange,
    className,
}: Props) {
    return (
        <div className={cn('text-sm', className)}>
            <p
                className="tabular-nums"
                title={`Cargado ${dateTime(createdAt)}`}
            >
                {date(createdAt)}
            </p>

            {lastChange && (
                <p
                    className="mt-0.5 text-xs text-muted-foreground tabular-nums"
                    title={
                        lastChange.by
                            ? `Modificado ${dateTime(lastChange.at)} por ${lastChange.by}`
                            : `Modificado ${dateTime(lastChange.at)}`
                    }
                >
                    modif. {date(lastChange.at)}
                </p>
            )}
        </div>
    );
}
