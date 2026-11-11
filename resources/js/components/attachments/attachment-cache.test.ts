import { beforeEach, describe, expect, it, vi } from 'vitest';
import { pdfThumbnail } from './attachment-preview';
import { localItem } from './types';

const render = vi.fn<(source: string | Blob) => Promise<Blob | null>>();

vi.mock('@/lib/pdf', () => ({
    renderPdfFirstPage: (source: string | Blob) => render(source),
}));

const pdf = (contenido: string) =>
    new File([contenido], 'ticket.pdf', {
        type: 'application/pdf',
        lastModified: 1_700_000_000_000,
    });

describe('la miniatura de un archivo local', () => {
    beforeEach(() => render.mockReset());

    /*
     * Mismo nombre, mismo tamaño y misma fecha, distinto contenido: al
     * reemplazar uno por el otro aparecía la hoja del primero.
     */
    it('no confunde dos archivos que coinciden en nombre, tamaño y fecha', async () => {
        const primero = pdf('aaaa');
        const segundo = pdf('bbbb');

        expect(localItem(primero).key).not.toBe(localItem(segundo).key);
        // El mismo archivo conserva su clave entre renders.
        expect(localItem(primero).key).toBe(localItem(primero).key);

        render.mockResolvedValue(new Blob(['hoja']));
        await pdfThumbnail(localItem(primero));
        await pdfThumbnail(localItem(segundo));

        expect(render).toHaveBeenCalledTimes(2);
        expect(render).toHaveBeenNthCalledWith(2, segundo);
    });

    /*
     * Un 503 pasajero vuelve como `null`. Guardado, dejaba el ícono roto
     * hasta recargar la aplicación.
     */
    it('vuelve a intentar una miniatura que falló', async () => {
        const item = localItem(pdf('cccc'));
        const hoja = new Blob(['hoja']);

        render.mockResolvedValueOnce(null).mockResolvedValueOnce(hoja);

        expect(await pdfThumbnail(item)).toBeNull();
        expect(await pdfThumbnail(item)).toBe(hoja);
        // Y la lograda sí queda: no se vuelve a dibujar.
        expect(await pdfThumbnail(item)).toBe(hoja);
        expect(render).toHaveBeenCalledTimes(2);
    });

    it('trata un error al dibujar como una miniatura que falló', async () => {
        const item = localItem(pdf('dddd'));
        const hoja = new Blob(['hoja']);

        render
            .mockRejectedValueOnce(new Error('sin red'))
            .mockResolvedValueOnce(hoja);

        expect(await pdfThumbnail(item)).toBeNull();
        expect(await pdfThumbnail(item)).toBe(hoja);
    });
});
