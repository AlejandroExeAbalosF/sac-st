import { describe, expect, it } from 'vitest';
import { salidaDe } from './installment-channel';

type Cuota = App.Modules.Haberes.Data.InstallmentListItemData;
type Traslado = App.Modules.Haberes.Data.InstallmentTransferData;

/** Lo mínimo que `salidaDe` mira, sin armar una cuota entera. */
const cuotaCon = (cashTransfer: Traslado | null): Cuota =>
    ({ cashTransfer }) as Cuota;

const traslado = (status: string): Traslado =>
    ({
        id: 1,
        amount: '240000.00',
        depositDate: '2026-09-16',
        status,
        operationNumber: '995979830',
        bankTransactionId: null,
        creditedDate: null,
    }) as Traslado;

describe('salidaDe', () => {
    it('no dice nada mientras el efectivo sigue en la caja', () => {
        expect(salidaDe(cuotaCon(null))).toBeNull();
    });

    it('marca el depósito cuando el extracto ya lo confirmó', () => {
        expect(salidaDe(cuotaCon(traslado('bank_confirmed')))).toBe(
            '(→ Depósito)',
        );
    });

    /*
     * El estado intermedio se dice: entre el depósito y su acreditación el
     * dinero no está ni en el cajón ni en la cuenta, y ahí no se puede pagar
     * de ninguna de las dos formas. Mostrar «(→ Depósito)» a secas haría
     * creer que el circuito bancario ya está disponible.
     */
    it('avisa cuando el depósito todavía está en tránsito', () => {
        expect(salidaDe(cuotaCon(traslado('deposited')))).toBe(
            '(→ Depósito, en tránsito)',
        );
    });
});
