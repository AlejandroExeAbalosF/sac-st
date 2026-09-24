import { Form, Head, Link, usePage } from '@inertiajs/react';
import { CircleCheck } from 'lucide-react';
import { useState } from 'react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import InputError from '@/components/input-error';
import PageHeader from '@/components/page-header';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AccountNav from '@/features/cuenta/components/account-nav';
import { send } from '@/routes/verification';
import type { Auth } from '@/types';

type Asignado = {
    username: string;
    documentNumber: string;
    position: string | null;
    role: string | null;
};

type Props = {
    mustVerifyEmail: boolean;
    status?: string;
    assigned: Asignado;
};

type PageProps = {
    auth: Auth;
};

/**
 * Los datos propios.
 *
 * Solo se editan dos: el nombre y el correo. Los otros cuatro se muestran
 * igual, en solo lectura, porque hasta ahora el usuario no tenía dónde
 * verlos y son los que explican cómo opera: el rol es lo que le habilita o
 * le niega cada pantalla, y el cargo es lo que se imprime al pie de los
 * comprobantes que firma.
 */
export default function Perfil({ mustVerifyEmail, status, assigned }: Props) {
    const { auth } = usePage<PageProps>().props;
    const [correo, setCorreo] = useState(auth.user.email);

    /*
     * El correo es a donde llega la recuperación de la contraseña: cambiarlo
     * pide la contraseña actual. El campo aparece solo cuando hace falta,
     * para no pedirla al corregir un apellido.
     */
    const cambiaCorreo =
        correo.trim().toLowerCase() !== auth.user.email.toLowerCase();

    return (
        <>
            <Head title="Mi cuenta" />

            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Mi cuenta"
                    title="Perfil"
                    description="Tus datos de contacto dentro del sistema."
                />

                <AccountNav />

                <div className="grid gap-6 lg:grid-cols-[minmax(0,32rem)_minmax(0,1fr)]">
                    <section className="rounded-lg border bg-card p-5 shadow-raised">
                        <h2 className="text-sm font-semibold">
                            Datos editables
                        </h2>

                        <Form
                            {...ProfileController.update.form()}
                            options={{ preserveScroll: true }}
                            className="mt-4 grid gap-5"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-5 sm:grid-cols-2">
                                        <div className="grid gap-2">
                                            <Label htmlFor="lastName">
                                                Apellido
                                            </Label>

                                            <Input
                                                id="lastName"
                                                name="lastName"
                                                defaultValue={
                                                    auth.user.last_name
                                                }
                                                required
                                                autoComplete="family-name"
                                                placeholder="Apellido"
                                                aria-invalid={Boolean(
                                                    errors.lastName,
                                                )}
                                            />

                                            <InputError
                                                message={errors.lastName}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="firstName">
                                                Nombre
                                            </Label>

                                            <Input
                                                id="firstName"
                                                name="firstName"
                                                defaultValue={
                                                    auth.user.first_name
                                                }
                                                required
                                                autoComplete="given-name"
                                                placeholder="Nombre"
                                                aria-invalid={Boolean(
                                                    errors.firstName,
                                                )}
                                            />

                                            <InputError
                                                message={errors.firstName}
                                            />
                                        </div>
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="email">
                                            Correo electrónico
                                        </Label>

                                        <Input
                                            id="email"
                                            type="email"
                                            name="email"
                                            value={correo}
                                            onChange={(event) =>
                                                setCorreo(event.target.value)
                                            }
                                            required
                                            autoComplete="email"
                                            placeholder="nombre@salta.gob.ar"
                                            aria-invalid={Boolean(errors.email)}
                                        />

                                        <InputError message={errors.email} />

                                        <p className="text-xs text-muted-foreground">
                                            Es a donde llega el enlace para
                                            recuperar la contraseña.
                                        </p>
                                    </div>

                                    {cambiaCorreo && (
                                        <div className="grid gap-2">
                                            <Label htmlFor="currentPassword">
                                                Contraseña actual
                                            </Label>

                                            <PasswordInput
                                                id="currentPassword"
                                                name="currentPassword"
                                                required
                                                autoComplete="current-password"
                                                aria-invalid={Boolean(
                                                    errors.currentPassword,
                                                )}
                                            />

                                            <InputError
                                                message={errors.currentPassword}
                                            />

                                            <p className="text-xs text-muted-foreground">
                                                Para cambiar el correo hace
                                                falta confirmar que sos vos.
                                            </p>
                                        </div>
                                    )}

                                    {mustVerifyEmail &&
                                        auth.user.email_verified_at ===
                                            null && (
                                            <div className="rounded-lg border border-warning bg-warning-soft px-3 py-2 text-sm text-warning-strong">
                                                <p>
                                                    El correo todavía no está
                                                    verificado.{' '}
                                                    <Link
                                                        href={send()}
                                                        as="button"
                                                        className="underline underline-offset-4"
                                                    >
                                                        Reenviar el mensaje de
                                                        verificación.
                                                    </Link>
                                                </p>

                                                {status ===
                                                    'verification-link-sent' && (
                                                    <p className="mt-2 flex items-center gap-2 font-medium text-success-strong">
                                                        <CircleCheck
                                                            className="size-4"
                                                            aria-hidden="true"
                                                        />
                                                        Se envió un mensaje
                                                        nuevo a tu casilla.
                                                    </p>
                                                )}
                                            </div>
                                        )}

                                    <div>
                                        <Button
                                            disabled={processing}
                                            data-test="update-profile-button"
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

                    <section className="h-fit rounded-lg border bg-card p-5">
                        <h2 className="text-sm font-semibold">
                            Datos que asigna un administrador
                        </h2>
                        <p className="mt-1 text-xs text-muted-foreground">
                            El nombre de usuario no cambia nunca: es con lo que
                            queda registrado cada acceso en el historial.
                        </p>

                        <dl className="mt-4 grid gap-3 text-sm">
                            <Dato
                                termino="Nombre de usuario"
                                valor={assigned.username}
                                monoespaciado
                            />
                            <Dato
                                termino="DNI"
                                valor={assigned.documentNumber}
                                monoespaciado
                            />
                            <Dato
                                termino="Cargo"
                                valor={assigned.position}
                                ausente="Sin cargo cargado"
                            />
                            <Dato
                                termino="Rol"
                                valor={assigned.role}
                                ausente="Sin rol asignado"
                            />
                        </dl>

                        <p className="mt-4 border-t pt-4 text-xs text-muted-foreground">
                            El cargo es lo que se imprime debajo de tu firma en
                            los comprobantes que emitís, y queda congelado en
                            cada uno tal como estaba el día en que se emitió.
                        </p>
                    </section>
                </div>
            </div>
        </>
    );
}

function Dato({
    termino,
    valor,
    ausente = '—',
    monoespaciado = false,
}: {
    termino: string;
    valor: string | null;
    ausente?: string;
    monoespaciado?: boolean;
}) {
    return (
        <div className="flex items-baseline justify-between gap-4 border-b pb-3 last:border-0 last:pb-0">
            <dt className="text-xs tracking-wide text-field-label uppercase">
                {termino}
            </dt>
            <dd
                className={
                    valor === null
                        ? 'text-right text-xs text-muted-foreground'
                        : monoespaciado
                          ? 'text-right font-mono tabular-nums'
                          : 'text-right'
                }
            >
                {valor ?? ausente}
            </dd>
        </div>
    );
}
