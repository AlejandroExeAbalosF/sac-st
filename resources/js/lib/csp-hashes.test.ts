import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';

/*
 * Los `<style>` de librerías sin soporte de nonce se permiten en la CSP por
 * su hash (`config/security.php`, `csp.style_hashes`). El hash es el de su
 * contenido exacto: si una versión nueva de la librería cambia el CSS, el
 * navegador lo bloquea en silencio y los avisos salen sin estilo.
 *
 * Estos tests recalculan el hash desde la librería instalada y lo comparan
 * con el de la configuración. Actualizar `sonner` sin actualizar el hash
 * rompe acá, no en la pantalla.
 */

function configuredHash(library: string): string | undefined {
    const config = readFileSync(resolve('config/security.php'), 'utf8');
    const match = config.match(
        new RegExp(`'${library}'\\s*=>\\s*"'(sha256-[^']+)'"`),
    );

    return match?.[1];
}

function sha256(content: string): string {
    return `sha256-${createHash('sha256').update(content, 'utf8').digest('base64')}`;
}

describe('hashes de la CSP para estilos inyectados', () => {
    afterEach(() => {
        Reflect.deleteProperty(globalThis, 'document');
    });

    it('coincide con el CSS que inyecta sonner', async () => {
        const inyectados: string[] = [];

        // Lo mínimo que usa `__insertCSS` de sonner, que corre al cargar el
        // módulo: así se captura el CSS tal cual lo pone en la página.
        Reflect.set(globalThis, 'document', {
            head: { appendChild: () => undefined },
            getElementsByTagName: () => [{ appendChild: () => undefined }],
            createElement: () => ({
                appendChild: (node: { text: string }) =>
                    inyectados.push(node.text),
            }),
            createTextNode: (text: string) => ({ text }),
        });

        await import('sonner');

        expect(inyectados).toHaveLength(1);
        expect(configuredHash('sonner')).toBe(sha256(inyectados[0]));
    });

    it('coincide con el style vacío de input-otp', () => {
        expect(configuredHash('input-otp')).toBe(sha256(''));
    });
});
