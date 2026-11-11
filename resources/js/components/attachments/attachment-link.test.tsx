import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import { AttachmentLink } from './attachment-link';
import type { Attachment } from './types';

const adjunto = (cambios: Partial<Attachment> = {}): Attachment => ({
    id: 7,
    title: 'Foto del talonario N.º 00686701',
    originalFilename: 'hoja.webp',
    kind: 'image',
    sizeBytes: 90_000,
    canOpen: true,
    canRename: false,
    createdByName: 'Operador',
    createdAt: '2026-11-10T13:00:00Z',
    ...cambios,
});

const html = (a: Attachment | null) =>
    renderToStaticMarkup(
        <AttachmentLink attachment={a}>Ver foto</AttachmentLink>,
    );

describe('el enlace a un adjunto', () => {
    it('no aparece si no hay archivo', () => {
        expect(html(null)).toBe('');
    });

    it('no aparece si quien mira no lo puede abrir', () => {
        expect(html(adjunto({ canOpen: false }))).toBe('');
    });

    it('una foto o un PDF abren el visor, sin salir de la pantalla', () => {
        const markup = html(adjunto({ kind: 'pdf' }));

        expect(markup).toContain('<button');
        expect(markup).not.toContain('href=');
    });

    it('otro tipo de archivo se descarga', () => {
        expect(html(adjunto({ kind: 'other' }))).toContain(
            'href="/adjuntos/7"',
        );
    });
});
