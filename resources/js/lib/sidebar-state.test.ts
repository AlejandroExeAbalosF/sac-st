import { describe, expect, it } from 'vitest';
import {
    parseOpenSections,
    SECTIONS_COOKIE,
    withSection,
} from './sidebar-state';

const cookie = (valor: string) => `${SECTIONS_COOKIE}=${valor}`;
const guardado = (secciones: unknown) =>
    cookie(encodeURIComponent(JSON.stringify(secciones)));

describe('los acordeones abiertos de la barra', () => {
    it('suma los que se abren y quita los que se cierran', () => {
        let abiertos = withSection([], 'Haberes', true);
        abiertos = withSection(abiertos, 'Multas', true);
        abiertos = withSection(abiertos, 'Haberes', false);

        expect(abiertos).toEqual(['Multas']);
    });

    it('no repite un acordeón que se abre dos veces', () => {
        expect(withSection(['Haberes'], 'Haberes', true)).toEqual(['Haberes']);
    });

    it('lee su cookie entre las demás, con tildes', () => {
        expect(
            parseOpenSections(
                `sidebar_state=true; ${guardado(['Configuración', 'Multas'])}; appearance=light`,
            ),
        ).toEqual(['Configuración', 'Multas']);
    });

    it('arranca sin nada si no hay cookie o no se entiende', () => {
        expect(parseOpenSections('')).toEqual([]);
        expect(parseOpenSections('sidebar_state=true')).toEqual([]);
        expect(parseOpenSections(cookie('%7Broto'))).toEqual([]);
        expect(parseOpenSections(cookie('%E0%A4%A'))).toEqual([]);
        expect(parseOpenSections(guardado({ Haberes: true }))).toEqual([]);
        expect(parseOpenSections(guardado(['Haberes', 3]))).toEqual([
            'Haberes',
        ]);
    });
});
