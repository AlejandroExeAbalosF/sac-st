import { describe, expect, it } from 'vitest';
import { caseSheets } from './case-sheets';

type Document = App.Modules.Multas.Data.FineDocumentData;
type Fine = App.Modules.Multas.Data.FineCaseFineData;

const document = (
    id: number,
    kind: Document['kind'],
    roles: Document['roles'] = ['origin'],
): Document => ({
    id,
    kind,
    roles,
    requiredRoles: [],
    actType: null,
    number: 100,
    year: 2026,
    originalText: '100/2026',
    label: '100/2026',
    issuedOn: '',
    issuedPrecision: 'unknown',
    provenance: 'manual',
});
const fine = (id: number, documents: Document[]): Fine => ({
    number: id,
    documents,
    fine: {
        id,
        href: `/multas/expedientes/1/100-2026/multa/${id}`,
        caseId: 1,
        caseRouteKey: '1/100-2026',
        caseLabel: '100/2026',
        caption: `Empresa ${id}`,
        personName: null,
        registeredOn: '',
        registeredPrecision: 'unknown',
        originDocuments: [],
        provenance: 'manual',
        balance: {
            obligation: '100.00',
            paid: '0.00',
            historicalPaid: '0.00',
            debt: '100.00',
            excess: '0.00',
            status: 'pending',
            pendingRegularization: '0.00',
        },
    },
});

describe('hojas del expediente', () => {
    it('reúne varias actas y multas compartidas sin duplicar la multa por acta', () => {
        const r = document(1, 'resolution');
        const a = document(2, 'act');
        const b = document(3, 'act');
        const result = caseSheets(
            [fine(1, [r, a, b]), fine(2, [b, r, a])],
            [r, a, b],
        );
        expect(result.resolutions).toHaveLength(1);
        expect(result.resolutions[0].acts).toHaveLength(1);
        expect(
            result.resolutions[0].acts[0].documents.map(({ id }) => id),
        ).toEqual([2, 3]);
        expect(
            result.resolutions[0].acts[0].fines.map(({ number }) => number),
        ).toEqual([1, 2]);
        expect(result.unlinked).toEqual([]);
    });

    it('separa actas distintas y documentos con igual nombre pero distinto id', () => {
        const r = document(1, 'resolution');
        const other = document(4, 'resolution');
        const a = document(2, 'act');
        const b = document(3, 'act');
        const result = caseSheets(
            [fine(1, [r, a]), fine(2, [r, b]), fine(3, [other, a])],
            [r, other, a, b],
        );
        expect(result.resolutions).toHaveLength(2);
        expect(result.resolutions[0].acts).toHaveLength(2);
        expect(result.resolutions[1].documents[0].id).toBe(4);
    });

    it('no usa una ratificación como resolución de origen ni la trata como huérfana', () => {
        const r = document(1, 'resolution');
        const later = document(2, 'resolution', ['ratification']);
        const unlinked = document(3, 'act', []);
        const result = caseSheets([fine(1, [r, later])], [r, later, unlinked]);
        expect(result.resolutions[0].documents.map(({ id }) => id)).toEqual([
            1,
        ]);
        expect(result.resolutions[0].acts[0].fines[0].documents).toContain(
            later,
        );
        expect(result.unlinked).toEqual([unlinked]);
    });

    it('mantiene multas sin documentos y agrupa varias resoluciones sin repetir saldos', () => {
        const a = document(1, 'resolution');
        const b = document(2, 'resolution');
        const result = caseSheets(
            [fine(1, []), fine(2, [a, b]), fine(3, [a])],
            [a, b],
        );
        expect(result.resolutions[0].documents).toEqual([]);
        expect(result.resolutions[1].documents).toEqual([a, b]);
        expect(
            result.resolutions.flatMap(({ acts }) =>
                acts.flatMap(({ fines }) => fines.map(({ number }) => number)),
            ),
        ).toEqual([1, 2, 3]);
    });

    it('mantiene todos los documentos visibles cuando aún no hay multas', () => {
        const docs = [document(1, 'resolution', []), document(2, 'other', [])];
        expect(caseSheets([], docs)).toEqual({
            resolutions: [],
            unlinked: docs,
        });
    });
});
