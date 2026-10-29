import { describe, expect, it } from 'vitest';
import { navGroups, visiblesPara } from './app-sidebar';

/** Los títulos del acordeón `titulo` que ve alguien con esos permisos. */
function hijosPara(
    titulo: string,
    can: Record<string, boolean>,
): string[] | undefined {
    return visiblesPara(navGroups, can)
        .flatMap((grupo) => grupo.items)
        .find((item) => item.title === titulo)
        ?.children?.map((hijo) => hijo.title);
}

const multasPara = (can: Record<string, boolean>) => hijosPara('Multas', can);

describe('el grupo Haberes de la barra', () => {
    it('reúne los expedientes y la caja, sin la planilla para el consultor', () => {
        expect(hijosPara('Haberes', {})).toEqual([
            'Expedientes',
            'Caja del día',
            'Arqueos',
            'Cierres de caja',
            'Calendario',
        ]);
    });

    it('suma la planilla de egresos a quien los registra', () => {
        expect(hijosPara('Haberes', { 'egresos.registrar': true })).toEqual([
            'Expedientes',
            'Planillas',
            'Caja del día',
            'Arqueos',
            'Cierres de caja',
            'Calendario',
        ]);
    });
});

describe('la sección Operación', () => {
    it('pone el banco en su propio acordeón, aparte de los circuitos', () => {
        const operacion = visiblesPara(navGroups, {
            'banco.extractos.ver': true,
        }).find((grupo) => grupo.label === 'Operación');

        expect(operacion?.items.map((item) => item.title)).toEqual([
            'Inicio',
            'Haberes',
            'Banco',
        ]);
        expect(
            hijosPara('Banco', {
                'banco.extractos.ver': true,
                'banco.cuentas.ver': true,
            }),
        ).toEqual(['Movimientos', 'Extractos', 'Cuentas']);
    });

    it('muestra las cuentas a quien solo puede verlas', () => {
        expect(hijosPara('Banco', { 'banco.cuentas.ver': true })).toEqual([
            'Cuentas',
        ]);
    });

    it('no ofrece el banco a quien no puede verlo', () => {
        expect(hijosPara('Banco', {})).toBeUndefined();
    });
});

describe('el grupo Multas de la barra', () => {
    it('aparece con la caja del día a quien solo opera la caja', () => {
        expect(multasPara({ 'multas.caja.ver': true })).toEqual([
            'Caja del día',
            'Calendario',
        ]);
    });

    it('muestra los expedientes y los pendientes a quien ve multas', () => {
        expect(multasPara({ 'multas.ver': true })).toEqual([
            'Expedientes',
            'Pendientes',
        ]);
    });

    it('queda en cuatro entradas para quien ve todo', () => {
        expect(
            multasPara({ 'multas.ver': true, 'multas.caja.ver': true }),
        ).toEqual(['Expedientes', 'Caja del día', 'Pendientes', 'Calendario']);
    });

    it('no aparece si no queda ninguna pantalla autorizada', () => {
        expect(multasPara({})).toBeUndefined();
    });
});
