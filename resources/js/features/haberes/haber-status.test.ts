import { describe, expect, it } from 'vitest';
import { avanceDelHaber } from './haber-status';

const haber = (
    status: App.Modules.Haberes.Enums.HaberWorkflowStatus,
    paymentStatus: App.Modules.Haberes.Enums.HaberPaymentStatus,
) => ({
    status,
    paymentStatus,
    paidAmount: '80000.00',
    assignedAmount: '200000.00',
});

describe('el avance del haber en importes', () => {
    it.each(['unpaid', 'partially_paid'] as const)(
        'acompaña a %s con lo pagado de lo reconocido',
        (situacion) => {
            expect(avanceDelHaber(haber('active', situacion))).toEqual({
                pagado: '80000.00',
                reconocido: '200000.00',
            });
        },
    );

    it.each(['incomplete_plan', 'fully_paid'] as const)(
        'no agrega nada con %s',
        (situacion) => {
            expect(avanceDelHaber(haber('active', situacion))).toBeNull();
        },
    );

    it('no aparece en un haber anulado', () => {
        expect(avanceDelHaber(haber('cancelled', 'partially_paid'))).toBeNull();
    });
});
