import { describe, expect, it } from 'vitest';
import { readCaseNumber, readDocumentNumber } from './number-reading';

const en2026 = new Date('2026-10-28T12:00:00Z');

describe('cómo se lee el número de un expediente', () => {
    it('completa el año con el siglo del año de hoy', () => {
        expect(readCaseNumber('169607/24-0', en2026)).toBe('169607/2024-0');
        expect(readCaseNumber('120276/25', en2026)).toBe('120276/2025');
        expect(readCaseNumber('82394/98', en2026)).toBe('82394/1998');
    });

    it('respeta un año escrito con cuatro cifras', () => {
        expect(readCaseNumber('169607/1924-0', en2026)).toBe('169607/1924-0');
    });

    it('no lee un número sin año', () => {
        expect(readCaseNumber('169607', en2026)).toBeNull();
    });
});

describe('cómo se lee el número de un documento', () => {
    it('toma el siglo de la fecha del papel', () => {
        expect(readDocumentNumber('12312/29', 'resolution', '2026-05-07')).toBe(
            'Resolución 12312/2029',
        );
        expect(readDocumentNumber('N°0649/98', 'resolution', '2026-05')).toBe(
            'Resolución 649/1998',
        );
    });

    it('sin fecha, usa la de referencia o el año de hoy', () => {
        expect(
            readDocumentNumber('N°845729/98', 'act', '', 'HYS', 2026, en2026),
        ).toBe('Acta HYS 845729/1998');
        expect(
            readDocumentNumber(
                'N°0649/26',
                'resolution',
                '',
                null,
                null,
                en2026,
            ),
        ).toBe('Resolución 649/2026');
    });

    it('el tipo del formulario manda sobre el del texto, y un acta puede no tener año', () => {
        expect(readDocumentNumber('HYS N°1025288/25', 'act', '', 'LAB')).toBe(
            'Acta LAB 1025288/2025',
        );
        expect(readDocumentNumber('H Y S N°1025288', 'act')).toBe(
            'Acta HYS 1025288',
        );
    });

    it('no lee otro documento ni un texto que no es un número', () => {
        expect(readDocumentNumber('Nota del empleador', 'other')).toBeNull();
        expect(readDocumentNumber('Resolución', 'resolution')).toBeNull();
    });
});
