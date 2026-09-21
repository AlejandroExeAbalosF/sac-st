import type { StatusTone } from '@/components/status-badge';

type Etapa = App.Modules.Haberes.Enums.InstallmentStage;

/**
 * La etapa de la cuota, en palabras.
 *
 * Duplica `InstallmentStage::label()` del servidor, como el resto de los
 * enums de dominio en este front: el transformador exporta los valores,
 * no los rótulos. Si se agrega un caso allá y no acá, `tsc` lo señala,
 * porque el Record está tipado sobre el enum completo.
 */
export const ETAPA: Record<Etapa, string> = {
    cancelled: 'Anulada',
    blocked: 'Bloqueada',
    suspended: 'Suspendida',
    unfunded: 'Sin financiar',
    awaiting_receipt: 'Falta el recibo',
    in_cash_box: 'En caja',
    deposit_in_transit: 'Depositada, en tránsito',
    at_bank: 'En el banco',
    order_issued: 'Orden emitida',
    transfer_reported: 'Transferencia informada',
    debit_observed: 'Débito observado',
    ready_to_validate: 'Lista para validar',
    paid: 'Pagada',
};

/**
 * Cómo se pinta cada una.
 *
 * `action` es lo que espera a una persona de esta oficina; `progress`, lo
 * que espera al banco o al organismo. La diferencia importa: sobre lo
 * primero se puede hacer algo hoy, sobre lo segundo no queda más que
 * esperar, y teñir las dos cosas igual convierte el listado en una pared
 * de alertas que nadie mira.
 *
 * `unfunded` va neutro a propósito: que el empleador todavía no haya
 * depositado no es una tarea nuestra.
 */
export const TONO_ETAPA: Record<Etapa, StatusTone> = {
    cancelled: 'neutral',
    blocked: 'blocked',
    suspended: 'blocked',
    unfunded: 'neutral',
    awaiting_receipt: 'action',
    in_cash_box: 'action',
    deposit_in_transit: 'progress',
    at_bank: 'action',
    order_issued: 'progress',
    transfer_reported: 'progress',
    debit_observed: 'progress',
    ready_to_validate: 'action',
    paid: 'done',
};
