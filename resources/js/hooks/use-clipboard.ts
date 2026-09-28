// Credit: https://usehooks-ts.com/
import { useState } from 'react';

export type CopiedValue = string | null;
export type CopyFn = (text: string) => Promise<boolean>;
export type UseClipboardReturn = [CopiedValue, CopyFn];

/**
 * Copia un texto al portapapeles y recuerda qué se copió.
 *
 * `navigator.clipboard` solo existe en un contexto seguro —HTTPS o
 * localhost—, y el servidor del data center se sirve por HTTP mientras no
 * haya proxy con certificado. Ahí el botón de copiar no hacía nada: ni la
 * contraseña temporal ni la clave del segundo factor. Por eso, sin la API,
 * se cae al método viejo, `execCommand('copy')` sobre un campo oculto, que
 * sigue funcionando en todos los navegadores aunque esté desaconsejado.
 */
export function useClipboard(): UseClipboardReturn {
    const [copiedText, setCopiedText] = useState<CopiedValue>(null);

    const copy: CopyFn = async (text) => {
        const copied = navigator?.clipboard
            ? await copyWithApi(text)
            : copyWithSelection(text);

        setCopiedText(copied ? text : null);

        return copied;
    };

    return [copiedText, copy];
}

async function copyWithApi(text: string): Promise<boolean> {
    try {
        await navigator.clipboard.writeText(text);

        return true;
    } catch (error) {
        console.warn('Copy failed', error);

        return copyWithSelection(text);
    }
}

function copyWithSelection(text: string): boolean {
    /*
     * El campo va adentro del diálogo abierto, si hay uno: un diálogo de
     * Radix no deja que el foco salga de él, y un campo colgado del body no
     * llegaría a quedar seleccionado.
     */
    const container =
        document.activeElement?.closest('[role="dialog"]') ?? document.body;
    const previousFocus = document.activeElement as HTMLElement | null;

    const field = document.createElement('textarea');
    field.value = text;
    field.setAttribute('readonly', '');
    field.setAttribute('aria-hidden', 'true');
    field.style.position = 'fixed';
    field.style.opacity = '0';
    field.style.pointerEvents = 'none';

    container.appendChild(field);
    field.select();

    let copied = false;

    try {
        copied = document.execCommand('copy');
    } catch (error) {
        console.warn('Copy failed', error);
    }

    field.remove();
    previousFocus?.focus();

    return copied;
}
