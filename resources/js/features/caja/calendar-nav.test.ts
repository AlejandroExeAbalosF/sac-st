import { describe, expect, it } from 'vitest';
import { pasoDeCalendario, yaEmpezo } from './calendar-nav';

/*
 * Estos tests existen por un error concreto: dentro de la grilla de un mes
 * las flechas movían el **año** y conservaban el mes. Estando en septiembre
 * de 2026, la flecha de la derecha llevaba a septiembre de 2027 —que ni
 * siquiera existe todavía— y llegar a octubre pedía volver a la vista del
 * año y elegirlo a mano.
 */

describe('pasoDeCalendario', () => {
    it('dentro de un mes se mueve de mes en mes', () => {
        expect(pasoDeCalendario(2026, 9, 1)).toEqual({ anio: 2026, mes: 10 });
        expect(pasoDeCalendario(2026, 9, -1)).toEqual({ anio: 2026, mes: 8 });
    });

    it('cruza el año por los bordes', () => {
        expect(pasoDeCalendario(2026, 12, 1)).toEqual({ anio: 2027, mes: 1 });
        expect(pasoDeCalendario(2026, 1, -1)).toEqual({ anio: 2025, mes: 12 });
    });

    /* Sin mes la vista es la del año, y ahí las flechas mueven el año. */
    it('en la vista del año se mueve de año en año', () => {
        expect(pasoDeCalendario(2026, null, 1)).toEqual({ anio: 2027 });
        expect(pasoDeCalendario(2026, null, -1)).toEqual({ anio: 2025 });
    });
});

describe('yaEmpezo', () => {
    const hoy = new Date(2026, 8, 3); // 3 de septiembre de 2026

    it('el mes que viene todavía no', () => {
        expect(yaEmpezo({ anio: 2026, mes: 10 }, hoy)).toBe(false);
    });

    it('el mes corriente sí, aunque no haya terminado', () => {
        expect(yaEmpezo({ anio: 2026, mes: 9 }, hoy)).toBe(true);
    });

    it('los meses pasados y los años anteriores, siempre', () => {
        expect(yaEmpezo({ anio: 2026, mes: 8 }, hoy)).toBe(true);
        expect(yaEmpezo({ anio: 2025, mes: 12 }, hoy)).toBe(true);
        expect(yaEmpezo({ anio: 2025 }, hoy)).toBe(true);
    });

    it('el año que viene no, ni siquiera su enero', () => {
        expect(yaEmpezo({ anio: 2027 }, hoy)).toBe(false);
        expect(yaEmpezo({ anio: 2027, mes: 1 }, hoy)).toBe(false);
    });

    /* El año corriente empezó, aunque se lo mire sin mes. */
    it('el año corriente ya empezó', () => {
        expect(yaEmpezo({ anio: 2026 }, hoy)).toBe(true);
    });
});
