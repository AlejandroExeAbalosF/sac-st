import { describe, expect, it } from 'vitest';
import { MAX_REMEMBERED, parseIds, toggledIds } from './use-remembered-open';

describe('las filas que quedan abiertas en un listado', () => {
    it('suma las que se abren y quita las que se cierran', () => {
        let abiertas = toggledIds([], 12);
        abiertas = toggledIds(abiertas, 7);
        abiertas = toggledIds(abiertas, 12);

        expect(abiertas).toEqual([7]);
    });

    it('olvida las más viejas al pasar el tope', () => {
        const llenas = Array.from({ length: MAX_REMEMBERED }, (_, i) => i);

        const abiertas = toggledIds(llenas, 999);

        expect(abiertas).toHaveLength(MAX_REMEMBERED);
        expect(abiertas[0]).toBe(1);
        expect(abiertas.at(-1)).toBe(999);
    });

    it('descarta lo guardado que no es una lista de ids', () => {
        expect(parseIds(null)).toEqual([]);
        expect(parseIds('no es json')).toEqual([]);
        expect(parseIds('{"id":3}')).toEqual([]);
        expect(parseIds('[3,"4",5.5,8]')).toEqual([3, 8]);
    });
});
