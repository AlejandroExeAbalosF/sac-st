import { describe, expect, it } from 'vitest';
import { cuentasDelDia } from './cuentas-del-dia';
import type { PeriodoDeCuenta } from './cuentas-del-dia';

const periodo = (
    id: number,
    label: string,
    validFrom: string | null,
    validUntil: string | null,
    isDefault = false,
): PeriodoDeCuenta => ({ id, label, isDefault, validFrom, validUntil });

// A operó hasta el 11/09 y volvió el 20/09; B entró el 10/09 y es la predeterminada.
const periodos = [
    periodo(1, 'A', null, '2026-09-11'),
    periodo(1, 'A', '2026-09-20', null),
    periodo(2, 'B', '2026-09-10', null, true),
];

const ids = (fecha: string) => cuentasDelDia(periodos, fecha).map((c) => c.id);

describe('cuentasDelDia', () => {
    it('ofrece la dada de baja para un depósito de antes de la baja', () => {
        expect(ids('2026-09-05')).toEqual([1]);
        expect(ids('2026-09-11')).toEqual([2, 1]);
    });

    it('deja afuera el tiempo en que la cuenta no correspondía', () => {
        expect(ids('2026-09-15')).toEqual([2]);
    });

    it('muestra cada cuenta una vez, la predeterminada primero', () => {
        expect(ids('2026-09-25')).toEqual([2, 1]);
    });
});
