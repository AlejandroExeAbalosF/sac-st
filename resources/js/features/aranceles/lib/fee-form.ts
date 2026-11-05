import type { LineaDeCobro } from '@/features/aranceles/components/fee-line-editor';

/**
 * La clave sale de los renglones, nunca de un contador del módulo: con SSR, ese
 * contador sigue subiendo en el servidor entre pedidos y el navegador arranca de
 * uno, así que los `id` del formulario no coinciden al hidratar.
 */
export const lineaVacia = (key = 1): LineaDeCobro => ({
    key,
    feeTypeId: null,
    quantity: '1',
    baseAmount: '',
    amount: '',
    reasonCode: '',
    reason: '',
});

/** Los renglones con uno vacío al final, con una clave que ninguno está usando. */
export const conLineaNueva = (lineas: LineaDeCobro[]): LineaDeCobro[] => [
    ...lineas,
    lineaVacia(Math.max(0, ...lineas.map((l) => l.key)) + 1),
];

/** Solo lo que carga el operador: el precio lo pone el servidor. */
export const itemsDeLineas = (lineas: LineaDeCobro[]) =>
    lineas.map((l) => ({
        feeTypeId: l.feeTypeId,
        quantity: l.quantity.trim() === '' ? null : l.quantity.trim(),
        baseAmount: l.baseAmount || null,
        amount: l.amount || null,
        reasonCode: l.reasonCode || null,
        reason: l.reason.trim() || null,
    }));
