import { describe, expect, it } from 'vitest';
import { acceptsPaymentEvidence } from './payment-evidence';

describe('respaldo externo de un pago de multa', () => {
    it.each(['cash', 'cheque'] as const)(
        'no se ofrece para un cobro ordinario por %s',
        (medium) => {
            expect(acceptsPaymentEvidence({ medium, appealKind: null })).toBe(
                false,
            );
        },
    );

    it.each(['bank_transfer', 'direct_deposit', 'echeq'] as const)(
        'se ofrece para un pago bancario por %s',
        (medium) => {
            expect(acceptsPaymentEvidence({ medium, appealKind: null })).toBe(
                true,
            );
        },
    );

    it('se ofrece para un recurso cobrado en efectivo', () => {
        expect(
            acceptsPaymentEvidence({
                medium: 'cash',
                appealKind: 'hierarchical',
            }),
        ).toBe(true);
    });
});
