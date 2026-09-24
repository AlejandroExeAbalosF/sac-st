import { describe, expect, it } from 'vitest';
import {
    compararComposicion,
    composicionCompleta,
    totalEfectivoDeComposicion,
} from '@/features/caja/components/carry-composition';

type Arqueo = App.Modules.Ledger.Data.CashCountListItemData;

const base: Arqueo = {
    id: 1,
    countedOn: '2026-09-20',
    sequence: 1,
    currency: 'ARS',
    expectedAmount: '2000000.00',
    countedAmount: '2000000.00',
    uncountedAmount: '0.00',
    differenceAmount: '0.00',
    status: 'reviewed',
    statusLabel: 'Revisado',
    explanation: null,
    balanced: true,
    fullyCounted: true,
    editable: false,
    performedById: 1,
    performedBy: 'Operador',
    reviewedBy: 'Revisor',
    selfReviewed: false,
    adjusted: false,
    lines: [],
    carryRecountReason: null,
    carryExpectedAmount: null,
    carryCountedAmount: null,
    carryDifferenceAmount: null,
    carryLines: [],
};

describe('compararComposicion', () => {
    it('distingue un cambio de billetes sin diferencia monetaria', () => {
        const referencia: Arqueo = {
            ...base,
            lines: [
                {
                    denomination: '100000.00',
                    quantity: 20,
                    subtotal: '2000000.00',
                },
            ],
        };
        const actual: Arqueo = {
            ...base,
            id: 2,
            countedOn: '2026-09-23',
            carryRecountReason: 'Verificación periódica.',
            carryExpectedAmount: '2000000.00',
            carryCountedAmount: '2000000.00',
            carryDifferenceAmount: '0.00',
            carryLines: [
                {
                    denomination: '50000.00',
                    quantity: 40,
                    subtotal: '2000000.00',
                },
            ],
        };

        const comparacion = compararComposicion(actual, referencia);

        expect(comparacion).toEqual({
            sameAmount: true,
            sameComposition: false,
            changes: [
                {
                    denomination: '100000.00',
                    previousQuantity: 20,
                    currentQuantity: 0,
                    difference: -20,
                },
                {
                    denomination: '50000.00',
                    previousQuantity: 0,
                    currentQuantity: 40,
                    difference: 40,
                },
            ],
        });
        expect(
            totalEfectivoDeComposicion(comparacion.changes, 'previousQuantity'),
        ).toBe('2000000.00');
        expect(
            totalEfectivoDeComposicion(comparacion.changes, 'currentQuantity'),
        ).toBe('2000000.00');
    });

    it('suma las líneas del día y del fajo del conteo de referencia', () => {
        const referencia: Arqueo = {
            ...base,
            lines: [
                {
                    denomination: '100000.00',
                    quantity: 5,
                    subtotal: '500000.00',
                },
            ],
            carryLines: [
                {
                    denomination: '100000.00',
                    quantity: 15,
                    subtotal: '1500000.00',
                },
            ],
        };
        const actual: Arqueo = {
            ...base,
            id: 2,
            carryRecountReason: 'Cambio de responsable.',
            carryExpectedAmount: '2000000.00',
            carryCountedAmount: '2000000.00',
            carryDifferenceAmount: '0.00',
            carryLines: [
                {
                    denomination: '100000.00',
                    quantity: 20,
                    subtotal: '2000000.00',
                },
            ],
        };

        const comparacion = compararComposicion(actual, referencia);

        expect(comparacion.sameAmount).toBe(true);
        expect(comparacion.sameComposition).toBe(true);
        expect(comparacion.changes).toHaveLength(1);
        expect(comparacion.changes[0]?.difference).toBe(0);
        expect(composicionCompleta(referencia)).toEqual([
            { denomination: '100000.00', quantity: 20 },
        ]);
    });
});
