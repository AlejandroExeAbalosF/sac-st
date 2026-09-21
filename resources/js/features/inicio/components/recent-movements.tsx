import { Link } from '@inertiajs/react';
import {
    ArrowDownLeft,
    ArrowUpRight,
    Banknote,
    Landmark,
    RotateCcw,
    Scale,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { date, dateTime, money } from '@/lib/format';
import { cn } from '@/lib/utils';

type Movement = App.Modules.Ledger.Data.MovementRowData;

/**
 * Ícono por tipo de hecho.
 *
 * El tipo llega como string y no como enum generado: `MovementRowData` lo
 * manda así justamente para que la pantalla pueda elegir un ícono sin que
 * agregar un tipo nuevo al motor contable rompa la compilación del front.
 * Lo que no reconoce cae en la balanza, que es el ícono del libro.
 */
const ICONO: Record<string, LucideIcon> = {
    funds_received: ArrowDownLeft,
    funds_allocated: Scale,
    cash_disbursement: ArrowUpRight,
    bank_disbursement: ArrowUpRight,
    legacy_disbursement: ArrowUpRight,
    cash_deposited_to_bank: Landmark,
    cash_deposit_credited: Landmark,
    opening_balance: Banknote,
    reversal: RotateCcw,
    cash_adjustment: Scale,
    authorized_adjustment: Scale,
};

/**
 * Lo último que se movió en el sistema.
 *
 * No cuadra nada —para eso está la caja del día— sino que contesta «¿qué
 * pasó desde ayer?». Los asientos revertidos aparecen tachados en vez de
 * desaparecer: una reversión es exactamente el tipo de novedad que
 * alguien quiere ver acá.
 */
export default function RecentMovements({
    movements,
}: {
    movements: Movement[];
}) {
    if (movements.length === 0) {
        return (
            <p className="px-5 py-10 text-center text-sm text-muted-foreground">
                Todavía no hay operaciones registradas.
                <br />
                Las recepciones, los recibos y las Órdenes van a aparecer acá.
            </p>
        );
    }

    return (
        <ul className="divide-y">
            {movements.map((movement) => {
                const Icono = ICONO[movement.type] ?? Scale;

                return (
                    <li key={movement.publicId}>
                        <Link
                            href={movement.href}
                            className="flex items-center gap-3 px-5 py-3.5 transition-colors hover:bg-accent/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:-outline-offset-2 focus-visible:outline-none"
                        >
                            <Icono
                                className={cn(
                                    'size-4 shrink-0',
                                    movement.isReversed
                                        ? 'text-destructive-strong'
                                        : 'text-muted-foreground',
                                )}
                                aria-hidden="true"
                            />

                            <div className="min-w-0 flex-1">
                                <p
                                    className={cn(
                                        'truncate text-sm font-medium',
                                        movement.isReversed &&
                                            'text-muted-foreground line-through',
                                    )}
                                >
                                    {movement.typeLabel}
                                </p>
                                <p className="truncate text-xs text-muted-foreground">
                                    Registrado {dateTime(movement.recordedAt)}
                                    {' · Fecha operativa '}
                                    {date(movement.eventDate)}
                                    {movement.cashBoxName &&
                                        ` · ${movement.cashBoxName}`}
                                    {movement.description &&
                                        ` · ${movement.description}`}
                                </p>
                            </div>

                            <span
                                className={cn(
                                    'shrink-0 font-mono text-sm tabular-nums',
                                    movement.isReversed &&
                                        'text-muted-foreground line-through',
                                )}
                            >
                                {money(movement.amount)}
                            </span>
                        </Link>
                    </li>
                );
            })}
        </ul>
    );
}
