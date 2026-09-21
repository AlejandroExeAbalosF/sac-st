import { Form, Head } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import FormError from '@/components/form-error';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
};

export default function Login({ status, canResetPassword }: Props) {
    return (
        <>
            <Head title="Ingresar" />

            {/*
             * Sin ingreso por passkey mientras el área no decida usarlo. La
             * feature sigue habilitada en Fortify y el alta de credenciales
             * vive en Configuración → Seguridad: para reponer el botón alcanza
             * con volver a montar <PasskeyVerify /> acá.
             */}
            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        {/*
                         * El rechazo del ingreso no pertenece al campo
                         * Usuario: pertenece al intento. Va arriba, donde el
                         * ojo vuelve despues de apretar el boton, y asi los
                         * dos campos no se corren de lugar.
                         */}
                        <FormError message={errors.username} />

                        <div className="grid gap-6">
                            <div className="grid gap-2">
                                <Label htmlFor="username">Usuario</Label>
                                <Input
                                    id="username"
                                    type="text"
                                    name="username"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="username"
                                    autoCapitalize="none"
                                    spellCheck={false}
                                    placeholder="ej: g.sosa"
                                    aria-invalid={Boolean(errors.username)}
                                />
                            </div>

                            <div className="grid gap-2">
                                <div className="flex items-center">
                                    <Label htmlFor="password">Contraseña</Label>
                                    {canResetPassword && (
                                        <TextLink
                                            href={request()}
                                            className="ml-auto text-sm"
                                            tabIndex={5}
                                        >
                                            ¿Olvidaste tu contraseña?
                                        </TextLink>
                                    )}
                                </div>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    tabIndex={2}
                                    autoComplete="current-password"
                                    placeholder="Contraseña"
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="flex items-center space-x-3">
                                <Checkbox
                                    id="remember"
                                    name="remember"
                                    tabIndex={3}
                                />
                                <Label htmlFor="remember">
                                    Recordar sesión
                                </Label>
                            </div>

                            <Button
                                type="submit"
                                className="mt-4 w-full"
                                tabIndex={4}
                                disabled={processing}
                                data-test="login-button"
                            >
                                {processing && <Spinner />}
                                Iniciar sesión
                            </Button>
                        </div>

                        {/*
                         * No hay registro público: las altas las hace un
                         * administrador desde el módulo de usuarios.
                         */}
                        <p className="flex items-center justify-center gap-2 border-t pt-4 text-center text-sm text-muted-foreground">
                            <ShieldAlert
                                className="size-4 shrink-0"
                                aria-hidden="true"
                            />
                            Acceso exclusivo para personal autorizado.
                        </p>
                    </>
                )}
            </Form>

            {status && (
                <div className="mb-4 text-center text-sm font-medium text-green-600">
                    {status}
                </div>
            )}
        </>
    );
}

Login.layout = {
    title: 'Bienvenido',
    description: 'Ingrese sus credenciales para acceder al sistema.',
};
