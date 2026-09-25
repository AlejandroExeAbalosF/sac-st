import { setNonce } from 'get-nonce';

/**
 * El nonce de CSP de esta página.
 *
 * La política no admite `<style>` en línea sin él. Lo que se dibuja en el
 * servidor ya lo trae; esto es para lo que inyecta estilos desde
 * JavaScript. El servidor lo publica en `<meta property="csp-nonce">`
 * (app.blade.php), y el navegador oculta el valor del atributo: se lee por
 * la propiedad `nonce` del elemento.
 */
export function cspNonce(): string | undefined {
    if (typeof document === 'undefined') {
        return undefined;
    }

    const meta = document.querySelector<HTMLMetaElement>(
        'meta[property="csp-nonce"]',
    );

    return meta?.nonce || undefined;
}

/**
 * Se lo pasa al bloqueo de scroll de los diálogos de Radix
 * (`react-style-singleton`), que lo busca con `get-nonce`. Tiene que
 * correr antes de montar la aplicación.
 */
export function applyCspNonce(): string | undefined {
    const nonce = cspNonce();

    if (nonce) {
        setNonce(nonce);
    }

    return nonce;
}
