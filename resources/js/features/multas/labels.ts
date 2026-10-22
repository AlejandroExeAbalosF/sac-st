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
type PaymentMedium = App.Modules.Multas.Enums.FinePaymentMedium;
type PaymentStatus = App.Modules.Multas.Enums.FinePaymentStatus;
type Timeliness = App.Modules.Multas.Enums.FineTimeliness;
type AppealKind = App.Modules.Multas.Enums.AppealKind;
type CollectionPlace = App.Modules.Multas.Enums.FineCollectionPlace;
type ChequeBankStatus = App.Modules.Banking.Enums.ChequeRejectionBankStatus;
type ChequeDisposition = App.Modules.Banking.Enums.ChequeDisposition;

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

export const PAYMENT_MEDIUM: Record<PaymentMedium, string> = {
    cash: 'Efectivo',
    cheque: 'Cheque',
    bank_transfer: 'Transferencia',
    direct_deposit: 'Depósito directo',
    echeq: 'Cheque electrónico',
};

export const PAYMENT_STATUS: Record<
    PaymentStatus,
    { label: string; tone: StatusTone }
> = {
    reported: { label: 'Informado', tone: 'action' },
    applied: { label: 'Aplicado', tone: 'done' },
    discarded: { label: 'Descartado', tone: 'neutral' },
};

export const APPEAL_KIND: Record<AppealKind, string> = {
    hierarchical: 'Recurso jerárquico',
    appeal: 'Apelación',
};

/** Si se presentó antes o después del cierre del mes de la multa (M08). */
export const TIMELINESS: Record<
    Timeliness,
    { label: string; tone: StatusTone }
> = {
    on_time: { label: 'En término', tone: 'done' },
    late: { label: 'Fuera de término', tone: 'action' },
    undetermined: { label: 'Término a revisar', tone: 'blocked' },
};

/** Dónde está la plata de un cobro de la caja (M06). */
export const COLLECTION_PLACE: Record<
    CollectionPlace,
    { label: string; tone: StatusTone }
> = {
    in_custody: { label: 'En la caja', tone: 'action' },
    in_transit: { label: 'Depositado, sin acreditar', tone: 'progress' },
    in_bank: { label: 'Acreditado en el banco', tone: 'done' },
    rejected: { label: 'Cheque rechazado', tone: 'blocked' },
};

/** Qué se sabe del banco sobre un cheque rechazado (DER físico §6.6). */
export const CHEQUE_BANK_STATUS: Record<
    ChequeBankStatus,
    { label: string; tone: StatusTone }
> = {
    not_applicable: { label: 'Banco: no corresponde', tone: 'neutral' },
    unverified: { label: 'Revisión bancaria pendiente', tone: 'action' },
    debit_pending: {
        label: 'Rechazo con acreditación previa: revisión bancaria pendiente',
        tone: 'action',
    },
    debit_recorded: { label: 'Banco: débito registrado', tone: 'done' },
};

/** Qué se hizo con el papel del cheque rechazado (M03). */
export const CHEQUE_DISPOSITION: Record<ChequeDisposition, string> = {
    voided_returned: 'Anulado y devuelto',
    act: 'Acta de cheque rechazado',
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
