/**
 * Consultas JSON al servidor, fuera del ciclo de Inertia.
 *
 * Casi todo en este sistema viaja por Inertia y los errores de validación
 * llegan solos a `useForm().errors`. La excepción son los buscadores que
 * consultan mientras se escribe: navegar por cada tecla volvería a montar
 * la página y el formulario abierto perdería lo que se venía cargando.
 *
 * Este módulo existe para que esa excepción se escriba una sola vez. El
 * token CSRF, el 419 de sesión vencida y el mapeo del 422 son los mismos
 * en todos los casos.
 */

/** Error del servidor, con los mensajes por campo si vinieron. */
export class ApiError extends Error {
    constructor(
        message: string,
        readonly fieldErrors: Record<string, string> = {},
    ) {
        super(message);
        this.name = 'ApiError';
    }

    get hasFieldErrors(): boolean {
        return Object.keys(this.fieldErrors).length > 0;
    }
}

type ValidationPayload = {
    message?: string;
    errors?: Record<string, string[]>;
};

const csrfToken = (): string =>
    document
        .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.getAttribute('content') ?? '';

/** Distingue la cancelación deliberada de una falla real. */
export const isAbort = (error: unknown): boolean =>
    error instanceof DOMException && error.name === 'AbortError';

async function request<T>(
    method: 'GET' | 'POST',
    url: string,
    { body, signal }: { body?: unknown; signal?: AbortSignal } = {},
): Promise<T> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        signal,
        headers: {
            Accept: 'application/json',
            ...(body === undefined
                ? {}
                : {
                      'Content-Type': 'application/json',
                      'X-CSRF-TOKEN': csrfToken(),
                  }),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    if (response.ok) {
        return (await response.json()) as T;
    }

    // 419 es la sesión vencida, no un error de datos: no hay nada que
    // corregir en el formulario y el único camino es recargar.
    if (response.status === 419) {
        throw new ApiError(
            'La sesión venció. Recargá la página para continuar.',
        );
    }

    if (response.status === 403) {
        throw new ApiError('No tenés permiso para hacer esto.');
    }

    let payload: ValidationPayload = {};

    try {
        payload = (await response.json()) as ValidationPayload;
    } catch {
        // Un 500 puede devolver HTML. El mensaje genérico alcanza.
    }

    if (response.status === 422) {
        throw new ApiError(
            payload.message ?? 'Revisá los datos ingresados.',
            Object.fromEntries(
                Object.entries(payload.errors ?? {}).map(
                    ([field, messages]) => [field, messages[0]],
                ),
            ),
        );
    }

    throw new ApiError(payload.message || 'No pudimos completar la operación.');
}

export const apiGet = <T>(url: string, signal?: AbortSignal): Promise<T> =>
    request<T>('GET', url, { signal });

export const apiPost = <T>(
    url: string,
    body: unknown,
    signal?: AbortSignal,
): Promise<T> => request<T>('POST', url, { body, signal });
