import { describe, expect, it } from 'vitest';
import {
    blocksResolution,
    linkableDocuments,
    linkableKinds,
} from './link-options';

type Rol = App.Modules.Multas.Enums.FineDocumentRole;

const doc = (
    id: number,
    kind: App.Modules.Multas.Enums.FineDocumentKind,
    roles: Rol[] = [],
) => ({ id, kind, roles });

const resolucion649 = doc(1, 'resolution');
const resolucion800 = doc(2, 'resolution');
const acta = doc(3, 'act');
const expediente = [resolucion649, resolucion800, acta];

describe('lo que ofrece «Vincular un documento»', () => {
    const conOrigen = [doc(1, 'resolution', ['origin'])];

    it('como origen no ofrece otra resolución si la multa ya tiene la suya', () => {
        expect(blocksResolution(conOrigen, 'origin')).toBe(true);
        expect(
            linkableDocuments(expediente, conOrigen, 'origin').map((d) => d.id),
        ).toEqual([3]);
        expect(linkableKinds(conOrigen, 'origin')).toEqual(['act', 'other']);
    });

    it('como ratificación sigue ofreciendo resoluciones', () => {
        expect(
            linkableDocuments(expediente, conOrigen, 'ratification').map(
                (d) => d.id,
            ),
        ).toEqual([1, 2, 3]);
        expect(linkableKinds(conOrigen, 'ratification')).toContain(
            'resolution',
        );
    });

    it('sin resolución de origen, como origen se ofrece cualquiera', () => {
        const soloActa = [doc(3, 'act', ['origin'])];

        expect(blocksResolution(soloActa, 'origin')).toBe(false);
        expect(
            linkableDocuments(expediente, soloActa, 'origin').map((d) => d.id),
        ).toEqual([1, 2]);
    });

    it('no repite un documento en el papel que ya cumple', () => {
        const ratificada = [doc(2, 'resolution', ['ratification'])];

        expect(
            linkableDocuments(expediente, ratificada, 'ratification').map(
                (d) => d.id,
            ),
        ).toEqual([1, 3]);
    });
});
