import { describe, expect, it } from 'vitest';
import { conLineaNueva, lineaVacia } from './fee-form';

describe('las claves de los renglones del cobro', () => {
    it('dan lo mismo en cada render: el servidor y el navegador coinciden', () => {
        lineaVacia();
        lineaVacia();

        expect(lineaVacia().key).toBe(1);
    });

    it('no repiten una clave en uso al agregar después de quitar', () => {
        const tres = conLineaNueva(conLineaNueva([lineaVacia()]));
        const sinLaPrimera = tres.slice(1);

        expect(conLineaNueva(sinLaPrimera).map((l) => l.key)).toEqual([
            2, 3, 4,
        ]);
    });
});
