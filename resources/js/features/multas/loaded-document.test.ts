import { describe, expect, it } from 'vitest';
import { findLoadedDocument } from './loaded-document';

type Doc = Parameters<typeof findLoadedDocument>[0][number];

const doc = (
    id: number,
    kind: Doc['kind'],
    number: number | null,
    year: number | null,
    actType: Doc['actType'] = null,
): Doc => ({ id, kind, number, year, actType, label: `${number}/${year}` });

const expediente = [
    doc(1, 'resolution', 1312, 2026),
    doc(2, 'act', 845729, 2023, 'HYS'),
    doc(3, 'act', 18505, 2014, 'LAB'),
    doc(4, 'act', 25045, null, 'LAB'),
];

describe('el documento ya cargado en el expediente', () => {
    it('reconoce el mismo número escrito de otra forma', () => {
        expect(
            findLoadedDocument(expediente, 'resolution', 'N°1312/26')?.id,
        ).toBe(1);
        expect(
            findLoadedDocument(expediente, 'resolution', '1312/2026')?.id,
        ).toBe(1);
        expect(
            findLoadedDocument(expediente, 'resolution', 'Nº 01312/26')?.id,
        ).toBe(1);
        expect(
            findLoadedDocument(expediente, 'act', 'H Y S N°845729/23')?.id,
        ).toBe(2);
    });

    it('no confunde otro número, otro año u otro tipo', () => {
        expect(
            findLoadedDocument(expediente, 'resolution', 'N°1313/26'),
        ).toBeNull();
        expect(
            findLoadedDocument(expediente, 'resolution', 'N°1312/25'),
        ).toBeNull();
        expect(findLoadedDocument(expediente, 'act', 'N°1312/26')).toBeNull();
        expect(
            findLoadedDocument(expediente, 'act', 'HYS N°18505/14'),
        ).toBeNull();
        expect(
            findLoadedDocument(expediente, 'act', 'N°18505/14', 'HYS'),
        ).toBeNull();
        expect(
            findLoadedDocument(expediente, 'act', 'N°18505/14', 'LAB')?.id,
        ).toBe(3);
    });

    it('un acta sin año coincide solo con otra sin año', () => {
        expect(findLoadedDocument(expediente, 'act', 'LAB N°25045')?.id).toBe(
            4,
        );
        expect(
            findLoadedDocument(expediente, 'act', 'LAB N°25045/25'),
        ).toBeNull();
    });

    it('no avisa con un texto que no se lee ni con otro documento', () => {
        expect(
            findLoadedDocument(expediente, 'resolution', 'Resolución'),
        ).toBeNull();
        expect(findLoadedDocument(expediente, 'other', '1312/26')).toBeNull();
    });
});
