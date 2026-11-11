import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import { AttachmentCard } from './attachment-card';
import type { Attachment } from './types';

const adjunto = (cambios: Partial<Attachment> = {}): Attachment => ({
    id: 9,
    title: 'Resolución 1234/25',
    originalFilename: 'resolucion.pdf',
    kind: 'pdf',
    sizeBytes: 120_000,
    canOpen: true,
    canRename: true,
    createdByName: 'Operador',
    createdAt: '2026-11-10T13:00:00Z',
    ...cambios,
});

const html = (a: Attachment) =>
    renderToStaticMarkup(<AttachmentCard attachment={a} />);

describe('la tarjeta de un adjunto', () => {
    it('ofrece el menú de acciones a quien puede abrir el archivo', () => {
        expect(html(adjunto())).toContain(
            'aria-label="Acciones de Resolución 1234/25"',
        );
    });

    /* Sin permiso no hay nada que ofrecer: ni descargar ni corregir. */
    it('sin permiso no muestra acciones, solo el candado', () => {
        const markup = html(adjunto({ canOpen: false, canRename: false }));

        expect(markup).not.toContain('Acciones de');
        expect(markup).not.toContain('/adjuntos/');
        expect(markup).toContain('Sin permiso para abrirlo');
    });
});
