import type { LineaDeCobro } from '@/features/aranceles/components/fee-line-editor';

let proximaClave = 1;

export const lineaVacia = (): LineaDeCobro => ({
    key: proximaClave++,
    feeTypeId: null,
    quantity: '1',
    baseAmount: '',
    amount: '',
    reasonCode: '',
    reason: '',
});

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
