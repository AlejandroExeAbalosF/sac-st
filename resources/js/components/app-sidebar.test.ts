import { describe, expect, it } from 'vitest';
import { navGroups, visiblesPara } from './app-sidebar';

/** Los títulos del grupo Multas que ve alguien con esos permisos. */
function multasPara(can: Record<string, boolean>): string[] | undefined {
    return visiblesPara(navGroups, can)
        .flatMap((grupo) => grupo.items)
        .find((item) => item.title === 'Multas')
        ?.children?.map((hijo) => hijo.title);
}

describe('el grupo Multas de la barra', () => {
    it('aparece con la caja del día a quien solo opera la caja', () => {
        expect(multasPara({ 'multas.caja.ver': true })).toEqual([
            'Caja del día',
            'Cierres de caja',
        ]);
    });

    it('muestra la cuenta corriente y la caja a quien ve multas', () => {
        expect(multasPara({ 'multas.ver': true })).toEqual([
            'Cuenta corriente',
            'Caja de Multas',
            'Movimientos',
        ]);
    });

    it('no aparece si no queda ninguna pantalla autorizada', () => {
        expect(multasPara({})).toBeUndefined();
    });
});
