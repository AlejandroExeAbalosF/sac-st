export type User = {
    id: number;
    /** «Apellido, Nombre». La arma la base sobre los dos campos de abajo. */
    name: string;
    first_name: string;
    last_name: string;
    username: string;
    /** DNI del operador, obligatorio en el alta. */
    document_number: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
    /** Rol principal, para mostrarlo junto al nombre. */
    role: string | null;
    /**
     * Los permisos que la navegación necesita resolver, y solo esos.
     *
     * No es el juego completo de permisos del usuario: la barra lateral es
     * lo único que decide en el cliente si algo se muestra, y mandarle
     * sesenta banderas en cada respuesta para usar tres sería pagar el
     * costo en todas las pantallas.
     */
    can: Record<string, boolean>;
};

/* @chisel-passkeys */
export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};
/* @end-chisel-passkeys */

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
