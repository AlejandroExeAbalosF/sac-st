import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useRef } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import NewPasswordFields from '@/features/cuenta/components/new-password-fields';
import type { PasswordPolicy } from '@/features/cuenta/password-policy';
import { update } from '@/routes/password';

type Props = {
    token: string;
    email: string;
    passwordRules: string;
    passwordPolicy: PasswordPolicy;
};

/**
 * La contraseña nueva de quien la recuperó por correo.
 *
 * Usa los mismos campos que el primer ingreso y «Mi cuenta › Seguridad»:
 * los requisitos se tildan mientras se escribe, con la política que manda
 * el servidor.
 */
export default function ResetPassword({
    token,
    email,
    passwordRules,
    passwordPolicy,
}: Props) {
    const passwordInput = useRef<HTMLInputElement>(null);

    const form = useForm({
        token,
        email,
        password: '',
        password_confirmation: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();

        form.post(update().url, {
            onError: (errors) => {
                if (errors.password) {
                    passwordInput.current?.focus();
                }
            },
        });
    }

    return (
        <>
            <Head title="Restablecer contraseña" />

            <form onSubmit={submit} noValidate className="grid gap-6">
                <div className="grid gap-2">
                    <Label htmlFor="email">Correo electrónico</Label>
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        autoComplete="username"
                        value={email}
                        readOnly
                    />
                    <InputError message={form.errors.email} />
                </div>

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
                    autoFocus
                />

                <Button
                    type="submit"
                    className="w-full"
                    disabled={form.processing}
                    data-test="reset-password-button"
                >
                    {form.processing && <Spinner />}
                    Guardar la contraseña nueva
                </Button>
            </form>
        </>
    );
}

ResetPassword.layout = {
    title: 'Restablecer contraseña',
    description: 'Elegí la contraseña con la que vas a entrar de ahora en más.',
};
