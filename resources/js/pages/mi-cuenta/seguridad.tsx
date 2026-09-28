import { Head } from '@inertiajs/react';
import type { Props as ManagePasskeysProps } from '@/components/manage-passkeys';
import ManagePasskeys from '@/components/manage-passkeys';
import type { Props as ManageTwoFactorProps } from '@/components/manage-two-factor';
import ManageTwoFactor from '@/components/manage-two-factor';
import PageHeader from '@/components/page-header';
import AccountNav from '@/features/cuenta/components/account-nav';
import PasswordCard from '@/features/cuenta/components/password-card';
import type { PasswordPolicy } from '@/features/cuenta/password-policy';

type Props = {
    passwordRules: string;
    passwordPolicy: PasswordPolicy;
    /** ISO 8601; `null` si el historial no registra ningún cambio. */
    passwordChangedAt: string | null;
} & ManagePasskeysProps &
    ManageTwoFactorProps;

/**
 * Contraseña, segundo factor y passkeys.
 *
 * Quien todavía usa la clave temporal no llega acá: `ForcePasswordChange`
 * lo lleva al primer ingreso. Esta pantalla es solo la de todos los días.
 */
export default function Seguridad(props: Props) {
    return (
        <>
            <Head title="Seguridad" />

            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Mi cuenta"
                    title="Seguridad"
                    description="Con qué entrás al sistema."
                />

                <AccountNav />

                <div className="grid gap-6 lg:grid-cols-[minmax(0,32rem)_minmax(0,1fr)]">
                    <PasswordCard
                        policy={props.passwordPolicy}
                        passwordRules={props.passwordRules}
                        changedAt={props.passwordChangedAt}
                    />

                    <div className="flex flex-col gap-6">
                        {/*
                         * Las tarjetas se dibujan solo si la funcion
                         * esta habilitada: los dos componentes
                         * devuelven `null` cuando no lo esta, y
                         * envolverlos siempre dejaria un recuadro
                         * vacio sin nada que explique que hace ahi.
                         */}
                        {props.canManageTwoFactor && (
                            <section className="rounded-lg border bg-card p-5">
                                <ManageTwoFactor
                                    canManageTwoFactor={
                                        props.canManageTwoFactor
                                    }
                                    requiresConfirmation={
                                        props.requiresConfirmation
                                    }
                                    twoFactorEnabled={props.twoFactorEnabled}
                                />
                            </section>
                        )}

                        {props.canManagePasskeys && (
                            <section className="rounded-lg border bg-card p-5">
                                <ManagePasskeys
                                    canManagePasskeys={props.canManagePasskeys}
                                    passkeys={props.passkeys}
                                />
                            </section>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}
