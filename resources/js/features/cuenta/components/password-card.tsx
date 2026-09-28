import { useForm } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import type { FormEvent } from 'react';
import { useRef } from 'react';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import NewPasswordFields from '@/features/cuenta/components/new-password-fields';
import type { PasswordPolicy } from '@/features/cuenta/password-policy';
import { date } from '@/lib/format';

type Props = {
    policy: PasswordPolicy;
    passwordRules: string;
    /** ISO 8601; `null` si el historial no registra ningún cambio. */
    changedAt: string | null;
};

/**
 * El cambio de contraseña de todos los días, en «Mi cuenta › Seguridad».
 *
 * A diferencia del primer ingreso, acá sí se pide la actual: esta pantalla
 * se abre en cualquier momento de una sesión, y es la pregunta que separa
 * al titular de quien encontró la máquina desbloqueada.
 *
 * Si el servidor rechaza la actual, se borra solo ese campo: la nueva ya
 * cumplía los requisitos, y hacerla tipear de nuevo es castigar al usuario
 * por un error que no estaba ahí.
 */
export default function PasswordCard({
    policy,
    passwordRules,
    changedAt,
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

        form.put(SecurityController.update().url, {
            preserveScroll: true,
            onSuccess: () => form.reset(),
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
        <section
            aria-labelledby="password-card-title"
            className="rounded-lg border bg-card shadow-raised"
        >
            <header className="flex items-start gap-3 border-b px-5 py-4">
                <span
                    className="flex size-9 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground"
                    aria-hidden="true"
                >
                    <KeyRound className="size-4" />
                </span>
                <div className="min-w-0">
                    <h2
                        id="password-card-title"
                        className="text-sm font-semibold"
                    >
                        Contraseña
                    </h2>
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {changedAt
                            ? `Última modificación: ${date(changedAt)}.`
                            : 'No hay cambios registrados desde el alta.'}{' '}
                        Larga y única: no la reutilices de otro sistema.
                    </p>
                </div>
            </header>

            <form onSubmit={submit} noValidate className="grid gap-5 p-5">
                <div className="grid gap-2">
                    <Label htmlFor="current_password">Contraseña actual</Label>
                    <PasswordInput
                        id="current_password"
                        ref={currentPasswordInput}
                        name="current_password"
                        value={form.data.current_password}
                        onChange={(e) =>
                            form.setData('current_password', e.target.value)
                        }
                        autoComplete="current-password"
                        aria-invalid={Boolean(form.errors.current_password)}
                    />
                    <InputError message={form.errors.current_password} />
                </div>

                <div className="border-t" aria-hidden="true" />

                <NewPasswordFields
                    policy={policy}
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
                />

                <div className="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <p className="text-xs text-muted-foreground">
                        Al cambiarla se cierran tus otras sesiones abiertas.
                    </p>
                    <Button
                        type="submit"
                        disabled={form.processing}
                        data-test="update-password-button"
                        className="sm:shrink-0"
                    >
                        {form.processing && <Spinner />}
                        Cambiar contraseña
                    </Button>
                </div>
            </form>
        </section>
    );
}
