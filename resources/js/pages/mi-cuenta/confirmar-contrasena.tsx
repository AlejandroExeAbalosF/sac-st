import { Form, Head } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import {
    index as confirmOptions,
    store as confirmStore,
} from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyConfirmationController';
import InputError from '@/components/input-error';
import PageHeader from '@/components/page-header';
import PasskeyVerify from '@/components/passkey-verify';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/password/confirm';

/**
 * La puerta de la sección de seguridad.
 *
 * Vive dentro del sistema —con la barra lateral y el camino de migas— y no
 * con la portada partida de las pantallas de acceso. Es la corrección de un
 * problema concreto: al usarse el layout de autenticación, esta pantalla era
 * indistinguible del login, y quien ya había entrado creía que lo habían
 * echado.
 *
 * Lo que protege es un caso puntual: la sesión que quedó abierta y alguien
 * más se sienta en esa máquina. A quien acaba de entrar con su contraseña ya
 * no se le pide —ver `ConfirmPasswordOnLogin`—, así que llegar hasta acá
 * significa que la sesión lleva horas abierta, y el texto lo dice.
 */
export default function ConfirmarContrasena() {
    return (
        <>
            <Head title="Confirmar la contraseña" />

            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Mi cuenta"
                    title="Confirmá tu contraseña"
                    description="Seguís dentro del sistema. Antes de tocar la seguridad de la cuenta hay que probar de nuevo quién sos."
                />

                <section className="max-w-md rounded-lg border bg-card p-5 shadow-raised">
                    <h2 className="flex items-center gap-2 text-sm font-semibold">
                        <ShieldCheck
                            className="size-4 text-muted-foreground"
                            aria-hidden="true"
                        />
                        Verificación
                    </h2>
                    <p className="mt-1 text-xs text-muted-foreground">
                        Tu sesión lleva un rato abierta. Es la forma de que
                        nadie que se encuentre esta pantalla pueda cambiar tu
                        contraseña.
                    </p>

                    <div className="mt-4">
                        <PasskeyVerify
                            routes={{
                                options: confirmOptions(),
                                submit: confirmStore(),
                            }}
                            label="Confirmar con passkey"
                            loadingLabel="Confirmando…"
                            separator="O confirmar con la contraseña"
                        />
                    </div>

                    <Form
                        {...store.form()}
                        resetOnSuccess={['password']}
                        className="mt-4"
                    >
                        {({ processing, errors }) => (
                            <div className="grid gap-5">
                                <div className="grid gap-2">
                                    <Label htmlFor="password">Contraseña</Label>
                                    <PasswordInput
                                        id="password"
                                        name="password"
                                        placeholder="Contraseña"
                                        autoComplete="current-password"
                                        autoFocus
                                    />

                                    <InputError message={errors.password} />
                                </div>

                                <div>
                                    <Button
                                        disabled={processing}
                                        data-test="confirm-password-button"
                                    >
                                        {processing && <Spinner />}
                                        Confirmar
                                    </Button>
                                </div>
                            </div>
                        )}
                    </Form>
                </section>
            </div>
        </>
    );
}
