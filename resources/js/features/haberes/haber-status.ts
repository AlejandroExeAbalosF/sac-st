import type { StatusTone } from '@/components/status-badge';

type Gestion = App.Modules.Haberes.Enums.HaberWorkflowStatus;
type Pago = App.Modules.Haberes.Enums.HaberPaymentStatus;

/**
 * Duplican los `label()` del servidor, como el resto de los enums de
 * dominio en este front: el transformador exporta los valores, no los
 * rótulos. Si se agrega un caso allá y no acá, `tsc` lo señala, porque
 * cada Record está tipado sobre el enum completo.
 */
const GESTION: Record<Gestion, { label: string; tone: StatusTone }> = {
    active: { label: 'Activo', tone: 'progress' },
    suspended: { label: 'Suspendido', tone: 'action' },
    blocked: { label: 'Bloqueado', tone: 'blocked' },
    cancelled: { label: 'Anulado', tone: 'neutral' },
    closed: { label: 'Cerrado', tone: 'done' },
};

/**
 * `incomplete_plan` pide algo de esta oficina —cargar las cuotas que
 * faltan—; `unpaid` va neutro porque lo que espera es que el empleador
 * deposite, que no es tarea nuestra.
 */
const PAGO: Record<Pago, { label: string; tone: StatusTone }> = {
    incomplete_plan: { label: 'Plan incompleto', tone: 'action' },
    unpaid: { label: 'Sin pagos', tone: 'neutral' },
    partially_paid: { label: 'Pago parcial', tone: 'progress' },
    fully_paid: { label: 'Pago completado', tone: 'done' },
};

/**
 * Lo que dice el campo «Estado» de un haber.
 *
 * Mientras el haber está activo, decir «Activo» no informa nada: lo que
 * importa es en qué va su pago. Cualquier otro estado de gestión —anulado,
 * sobre todo— manda sobre el pago, porque dice que el haber no avanza.
 */
export function estadoDelHaber(haber: {
    status: Gestion;
    paymentStatus: Pago;
}): { label: string; tone: StatusTone } {
    return haber.status === 'active'
        ? PAGO[haber.paymentStatus]
        : GESTION[haber.status];
}
