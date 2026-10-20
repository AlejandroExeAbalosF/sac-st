import type { StatusTone } from '@/components/status-badge';
import { date } from '@/lib/format';

type DocumentKind = App.Modules.Multas.Enums.FineDocumentKind;
type DocumentRole = App.Modules.Multas.Enums.FineDocumentRole;
type DebtKind = App.Modules.Multas.Enums.FineDebtEntryKind;
type DebtStatus = App.Modules.Multas.Enums.FineDebtStatus;
type HistoricalKind = App.Modules.Multas.Enums.HistoricalMovementKind;
type HistoricalStatus = App.Modules.Multas.Enums.HistoricalMovementStatus;
type Precision = App.Modules.Multas.Enums.DatePrecision;
type ActType = App.Modules.Multas.Enums.FineActType;

/*
 * Los rótulos de los enums de Multas, en un solo lugar.
 *
 * Repiten los `label()` de PHP porque el transformador de tipos exporta los
 * valores, no los rótulos. Si uno cambia del otro lado, el test de la
 * pantalla que lo muestra lo delata.
 */

export const DOCUMENT_KIND: Record<DocumentKind, string> = {
    resolution: 'Resolución',
    act: 'Acta',
    other: 'Otro documento',
};

export const ACT_TYPE: Record<ActType, string> = {
    LAB: 'Laboral',
    HYS: 'Higiene y seguridad',
};

export const DOCUMENT_ROLE: Record<DocumentRole, string> = {
    origin: 'Origen',
    modification: 'Modificación',
    ratification: 'Ratificación',
    support: 'Respaldo',
};

export const DEBT_KIND: Record<DebtKind, string> = {
    constitution: 'Importe original',
    increase: 'Aumento',
    reduction: 'Reducción',
    reversal: 'Reversión',
};

export const DEBT_STATUS: Record<
    DebtStatus,
    { label: string; tone: StatusTone }
> = {
    pending: { label: 'Pendiente', tone: 'action' },
    partial: { label: 'Con pagos parciales', tone: 'progress' },
    cancelled: { label: 'Cancelada', tone: 'done' },
    without_effect: { label: 'Sin efecto', tone: 'neutral' },
};

export const HISTORICAL_KIND: Record<HistoricalKind, string> = {
    payment: 'Pago anterior',
    refund: 'Devolución anterior',
};

export const HISTORICAL_STATUS: Record<
    HistoricalStatus,
    { label: string; tone: StatusTone }
> = {
    pending_review: { label: 'Pendiente de revisión', tone: 'action' },
    validated: { label: 'Validado', tone: 'done' },
    rejected: { label: 'Descartado', tone: 'neutral' },
};

const MESES = [
    'enero',
    'febrero',
    'marzo',
    'abril',
    'mayo',
    'junio',
    'julio',
    'agosto',
    'septiembre',
    'octubre',
    'noviembre',
    'diciembre',
];

/**
 * Una fecha con la precisión que de verdad tiene.
 *
 * «07/05/2026» si se sabe el día, «julio de 2025» si solo el mes y «sin
 * fecha» si no se sabe: mostrar el primer día del mes como si fuera un día
 * cierto sería inventar el dato que la base se cuidó de no inventar.
 */
export function partialDate(value: string, precision: Precision): string {
    if (precision === 'unknown' || value === '') {
        return 'Sin fecha';
    }

    if (precision === 'month') {
        const [anio, mes] = value.split('-');

        return `${MESES[Number(mes) - 1]} de ${anio}`;
    }

    return date(value);
}
