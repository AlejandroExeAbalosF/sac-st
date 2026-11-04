import { describe, expect, it } from 'vitest';
import { coincideTipo } from './fee-type-search';

const homologacion = {
    code: 14,
    decreeLetter: 'n',
    name: 'Homologación de acuerdos individuales',
};

describe('el buscador de tipos de arancel', () => {
    it('encuentra por concepto, sin importar tildes ni mayúsculas', () => {
        expect(coincideTipo(homologacion, 'HOMOLOGACION')).toBe(true);
        expect(coincideTipo(homologacion, 'acuerdos homologación')).toBe(true);
        expect(coincideTipo(homologacion, 'notificaciones')).toBe(false);
    });

    it('busca el código exacto, para que el 14 no traiga el 140', () => {
        expect(coincideTipo(homologacion, '14')).toBe(true);
        expect(coincideTipo(homologacion, '1')).toBe(false);
        expect(coincideTipo({ ...homologacion, code: 140 }, '14')).toBe(false);
    });

    it('busca el inciso por su letra', () => {
        expect(coincideTipo(homologacion, 'n')).toBe(true);
        expect(coincideTipo(homologacion, 'o')).toBe(false);
    });

    it('muestra todo si no se escribió nada', () => {
        expect(coincideTipo(homologacion, '   ')).toBe(true);
    });
});
