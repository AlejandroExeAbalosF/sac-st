import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import { AttachmentInlinePreview } from './attachment-inline-preview';
import { nextZoom } from './attachment-viewer';
import type { Attachment } from './types';

const adjunto = (cambios: Partial<Attachment> = {}): Attachment => ({
    id: 42,
    title: 'Comprobante del depósito',
    originalFilename: 'ticket.pdf',
    kind: 'pdf',
    sizeBytes: 250_000,
    canOpen: true,
    canRename: false,
    canVoid: false,
    voided: null,
    createdByName: 'Operador',
    createdAt: '2026-11-10T13:00:00Z',
    ...cambios,
});

const html = (a: Attachment) =>
    renderToStaticMarkup(<AttachmentInlinePreview attachment={a} />);

describe('la vista previa de un adjunto', () => {
    /*
     * Sin permiso no se pide nada: ni la foto, ni la miniatura, ni la
     * descarga. El servidor respondería 403, y la pantalla lo mostraría
     * como una imagen rota.
     */
    it('sin permiso no apunta a ninguna dirección del archivo', () => {
        const markup = html(adjunto({ canOpen: false, kind: 'image' }));

        expect(markup).not.toContain('/adjuntos/');
        expect(markup).toContain('no tenés permiso para abrirlo');
    });

    it('una foto con permiso se muestra desde la vista previa', () => {
        expect(html(adjunto({ kind: 'image' }))).toContain('/adjuntos/42/ver');
    });

    it('lo que no es foto ni PDF solo se ofrece para descargar', () => {
        const markup = html(
            adjunto({ kind: 'other', originalFilename: 'extracto.csv' }),
        );

        expect(markup).toContain('href="/adjuntos/42"');
        expect(markup).not.toContain('/adjuntos/42/ver');
    });
});

describe('el zoom del visor', () => {
    it('avanza y retrocede por pasos fijos, sin pasarse de los topes', () => {
        expect(nextZoom(1, 1)).toBe(1.25);
        expect(nextZoom(1, -1)).toBe(0.75);
        expect(nextZoom(4, 1)).toBe(4);
        expect(nextZoom(0.5, -1)).toBe(0.5);
        // Después de la rueda el zoom queda entre pasos: va al siguiente.
        expect(nextZoom(1.1, 1)).toBe(1.25);
    });
});
