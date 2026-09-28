import { Head, useForm } from '@inertiajs/react';
import { Clock, KeyRound } from 'lucide-react';
import type { FormEvent } from 'react';
import { useRef } from 'react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import NewPasswordFields from '@/features/cuenta/components/new-password-fields';
import type { PasswordPolicy } from '@/features/cuenta/password-policy';
import { logout } from '@/routes';
import { update } from '@/routes/primer-ingreso';

type Props = {
    firstName: string;
    /** Si la marca la puso un restablecimiento y no el alta. */
    wasReset: boolean;
    /** Si pasó demasiado desde el login y hay que volver a probar la temporal. */
    requiresCurrentPassword: boolean;
    passwordPolicy: PasswordPolicy;
    passwordRules: string;
};

/**
 * Donde el usuario deja la contraseña temporal y elige la suya.
 *
 * Es la continuación del login, y se dibuja con su mismo layout: sin barra
 * lateral ni secciones de la cuenta, que con la marca puesta eran enlaces
 * que lo devolvían siempre acá. Una sola cosa para hacer, y la salida.
 *
 * No pide la contraseña actual: la tipeó hace segundos para llegar. Si pasó
 * un rato —la sesión que quedó abierta y encontró otro—, el servidor lo
 * avisa con `requiresCurrentPassword` y el campo aparece acá mismo, sin
 * mandarlo a otra pantalla.
 */
export default function PrimerIngreso({
    firstName,
    wasReset,
    requiresCurrentPassword,
    passwordPolicy,
    passwordRules,
}: Props) {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    const form = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();

        form.put(update().url, {
            onError: (errors) => {
                if (errors.current_password) {
                    form.reset('current_password');
                    currentPasswordInput.current?.focus();

                    return;
                }

                if (errors.password) {
                    passwordInput.current?.focus();
                }
            },
        });
    }

    return (
        <>
            <Head title="Elegí tu contraseña" />

            <form onSubmit={submit} className="flex flex-col gap-6" noValidate>
                <div className="flex items-start gap-3 rounded-lg border border-info/40 bg-info-soft px-4 py-3 text-sm text-info-strong">
                    <KeyRound
                        className="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    <p className="text-pretty">
                        <strong className="font-semibold">
                            Hola, {firstName}.
                        </strong>{' '}
                        {wasReset
                            ? 'Un administrador restableció tu contraseña y la que usaste para entrar es temporal. Elegí una nueva para seguir trabajando.'
                            : 'Es tu primer ingreso. La contraseña que usaste te la entregó un administrador, así que hoy la conocen dos personas. Elegí una propia para empezar.'}
                    </p>
                </div>

                {requiresCurrentPassword && (
                    <div className="grid gap-2">
                        <Label htmlFor="current_password">
                            Contraseña temporal
                        </Label>
                        <p className="flex items-start gap-1.5 text-xs text-muted-foreground">
                            <Clock
                                className="mt-px size-3.5 shrink-0"
                                aria-hidden="true"
                            />
                            Pasó un rato desde que entraste: para confirmar que
                            seguís siendo vos, escribí la contraseña con la que
                            ingresaste.
                        </p>
                        <PasswordInput
                            id="current_password"
                            ref={currentPasswordInput}
                            name="current_password"
                            value={form.data.current_password}
                            onChange={(e) =>
                                form.setData('current_password', e.target.value)
                            }
                            autoComplete="current-password"
                            autoFocus
                            aria-invalid={Boolean(form.errors.current_password)}
                        />
                        <InputError message={form.errors.current_password} />
                    </div>
                )}

                <NewPasswordFields
                    policy={passwordPolicy}
                    passwordRules={passwordRules}
                    password={form.data.password}
                    confirmation={form.data.password_confirmation}
                    onPasswordChange={(value) =>
                        form.setData('password', value)
                    }
                    onConfirmationChange={(value) =>
                        form.setData('password_confirmation', value)
                    }
                    errors={form.errors}
                    passwordRef={passwordInput}
                    autoFocus={!requiresCurrentPassword}
                />

                <Button
                    type="submit"
                    className="w-full"
                    disabled={form.processing}
                    data-test="initial-password-button"
                >
                    {form.processing && <Spinner />}
                    Guardar y entrar al sistema
                </Button>

                <p className="border-t pt-4 text-center text-sm text-muted-foreground">
                    ¿No sos {firstName}?{' '}
                    <TextLink href={logout()} as="button">
                        Cerrar sesión
                    </TextLink>
                </p>
            </form>
        </>
    );
}

PrimerIngreso.layout = {
    title: 'Elegí tu contraseña',
};
