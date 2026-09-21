import { Form, Head } from '@inertiajs/react';
import { KeyRound, TriangleAlert } from 'lucide-react';
import { useRef } from 'react';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import InputError from '@/components/input-error';
import type { Props as ManagePasskeysProps } from '@/components/manage-passkeys';
import ManagePasskeys from '@/components/manage-passkeys';
import type { Props as ManageTwoFactorProps } from '@/components/manage-two-factor';
import ManageTwoFactor from '@/components/manage-two-factor';
import PageHeader from '@/components/page-header';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AccountNav from '@/features/cuenta/components/account-nav';

type Props = {
    passwordRules: string;
    mustChangePassword: boolean;
} & ManagePasskeysProps &
    ManageTwoFactorProps;

/**
 * Contraseña, segundo factor y passkeys.
 *
 * Con `mustChangePassword` puesto, la pantalla se reduce a una sola cosa.
 * El usuario llegó acá porque el sistema lo trajo —está usando la clave que
 * le dictó un administrador— y ofrecerle registrar una passkey antes de
 * tener una contraseña propia sería ofrecerle asegurar una puerta que
 * todavía tiene la llave repartida.
 */
export default function Seguridad(props: Props) {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

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

                {props.mustChangePassword && (
                    <p className="flex items-start gap-2 rounded-lg border border-warning bg-warning-soft px-4 py-3 text-sm text-warning-strong">
                        <TriangleAlert
                            className="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span>
                            <strong className="font-semibold">
                                Cambiá la contraseña para seguir.
                            </strong>{' '}
                            La que estás usando te la entregó un administrador,
                            así que hoy la conocen dos personas. Hasta que la
                            cambies, el resto del sistema queda cerrado.
                        </span>
                    </p>
                )}

                <div className="grid gap-6 lg:grid-cols-[minmax(0,32rem)_minmax(0,1fr)]">
                    <section className="rounded-lg border bg-card p-5 shadow-raised">
                        <h2 className="flex items-center gap-2 text-sm font-semibold">
                            <KeyRound
                                className="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                            Contraseña
                        </h2>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Larga y única. No la reutilices de otro sistema.
                        </p>

                        <Form
                            {...SecurityController.update.form()}
                            options={{ preserveScroll: true }}
                            resetOnError={[
                                'password',
                                'password_confirmation',
                                'current_password',
                            ]}
                            resetOnSuccess
                            onError={(errors) => {
                                if (errors.password) {
                                    passwordInput.current?.focus();
                                }

                                if (errors.current_password) {
                                    currentPasswordInput.current?.focus();
                                }
                            }}
                            className="mt-4 grid gap-5"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="current_password">
                                            Contraseña actual
                                        </Label>

                                        <PasswordInput
                                            id="current_password"
                                            ref={currentPasswordInput}
                                            name="current_password"
                                            autoComplete="current-password"
                                            placeholder="Contraseña actual"
                                        />

                                        <InputError
                                            message={errors.current_password}
                                        />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="password">
                                            Nueva contraseña
                                        </Label>

                                        <PasswordInput
                                            id="password"
                                            ref={passwordInput}
                                            name="password"
                                            autoComplete="new-password"
                                            placeholder="Nueva contraseña"
                                            passwordrules={props.passwordRules}
                                        />

                                        <InputError message={errors.password} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="password_confirmation">
                                            Confirmar contraseña
                                        </Label>

                                        <PasswordInput
                                            id="password_confirmation"
                                            name="password_confirmation"
                                            autoComplete="new-password"
                                            placeholder="Repetí la contraseña"
                                            passwordrules={props.passwordRules}
                                        />

                                        <InputError
                                            message={
                                                errors.password_confirmation
                                            }
                                        />
                                    </div>

                                    <div>
                                        <Button
                                            disabled={processing}
                                            data-test="update-password-button"
                                        >
                                            {processing
                                                ? 'Guardando…'
                                                : 'Guardar cambios'}
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </section>

                    {!props.mustChangePassword && (
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
                                        twoFactorEnabled={
                                            props.twoFactorEnabled
                                        }
                                    />
                                </section>
                            )}

                            {props.canManagePasskeys && (
                                <section className="rounded-lg border bg-card p-5">
                                    <ManagePasskeys
                                        canManagePasskeys={
                                            props.canManagePasskeys
                                        }
                                        passkeys={props.passkeys}
                                    />
                                </section>
                            )}
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}
