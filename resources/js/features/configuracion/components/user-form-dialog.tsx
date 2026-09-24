import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import FormError from '@/components/form-error';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { store, update } from '@/routes/configuracion/usuarios';

type Usuario = App.Modules.Shared.Data.UserListItemData;

export type UserFormMode = { kind: 'create' } | { kind: 'edit'; user: Usuario };

/**
 * Alta y corrección de un usuario, en el mismo formulario.
 *
 * La única diferencia entre los dos modos es el nombre de usuario: se pide
 * en el alta y desaparece en la edición. No es una omisión — es con lo que
 * quedó registrado cada intento de acceso en el historial, y renombrarlo
 * volvería ilegible lo que ya pasó.
 *
 * Tampoco hay campo de contraseña: en el alta la genera el sistema y en la
 * edición no se toca. Para eso está «Restablecer contraseña», que además
 * cierra las sesiones abiertas.
 */
export default function UserFormDialog({
    mode,
    roles,
    onClose,
}: {
    mode: UserFormMode | null;
    roles: Record<string, string>;
    onClose: () => void;
}) {
    const esAlta = mode?.kind === 'create';

    const {
        data,
        setData,
        post,
        patch,
        processing,
        errors,
        reset,
        clearErrors,
    } = useForm({
        firstName: '',
        lastName: '',
        username: '',
        documentNumber: '',
        email: '',
        position: '',
        role: 'administrativo',
    });

    useEffect(() => {
        if (!mode) {
            return;
        }

        clearErrors();

        if (mode.kind === 'edit') {
            setData({
                firstName: mode.user.firstName,
                lastName: mode.user.lastName,
                username: mode.user.username,
                documentNumber: mode.user.documentNumber,
                email: mode.user.email ?? '',
                position: mode.user.position ?? '',
                role: mode.user.role ?? 'administrativo',
            });

            return;
        }

        reset();
        // `setData`, `reset` y `clearErrors` cambian de identidad en cada
        // render de useForm; incluirlos repoblaría el formulario mientras
        // se escribe y pisaría lo tipeado.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [mode]);

    if (!mode) {
        return null;
    }

    /*
     * Lo que el servidor no va a dejar cambiar llega con el motivo, desde
     * UserManagementGuard: el campo se deshabilita y lo dice, en vez de
     * aceptar la edición y rechazarla al guardar.
     */
    const bloqueoCorreo =
        mode.kind === 'edit' ? mode.user.credentialsLockedReason : null;
    const bloqueoRol = mode.kind === 'edit' ? mode.user.roleLockedReason : null;

    const enviar = (e: React.FormEvent) => {
        e.preventDefault();

        const opciones = {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        };

        if (mode.kind === 'create') {
            post(store().url, opciones);

            return;
        }

        patch(update(mode.user.id).url, opciones);
    };

    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {esAlta ? 'Nuevo usuario' : 'Corregir el usuario'}
                    </DialogTitle>
                    <DialogDescription>
                        {esAlta
                            ? 'El sistema genera la contraseña y te la muestra una sola vez.'
                            : `Nombre de usuario: ${mode.kind === 'edit' ? mode.user.username : ''}. No se puede cambiar.`}
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={enviar} className="grid gap-5">
                    <FormError
                        message={(errors as Record<string, string>).user}
                    />

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="lastName">Apellido</Label>
                            <Input
                                id="lastName"
                                value={data.lastName}
                                onChange={(e) =>
                                    setData('lastName', e.target.value)
                                }
                                autoComplete="off"
                                maxLength={80}
                                aria-invalid={Boolean(errors.lastName)}
                            />
                            <InputError message={errors.lastName} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="firstName">Nombre</Label>
                            <Input
                                id="firstName"
                                value={data.firstName}
                                onChange={(e) =>
                                    setData('firstName', e.target.value)
                                }
                                autoComplete="off"
                                maxLength={80}
                                aria-invalid={Boolean(errors.firstName)}
                            />
                            <InputError message={errors.firstName} />
                        </div>
                    </div>

                    {esAlta && (
                        <div className="grid gap-2">
                            <Label htmlFor="username">Nombre de usuario</Label>
                            <Input
                                id="username"
                                value={data.username}
                                onChange={(e) =>
                                    setData('username', e.target.value)
                                }
                                className="font-mono"
                                autoComplete="off"
                                maxLength={60}
                                placeholder="g.sosa"
                                aria-invalid={Boolean(errors.username)}
                            />
                            <InputError message={errors.username} />
                            <p className="text-xs text-muted-foreground">
                                Minúsculas, sin espacios. No se puede cambiar
                                después.
                            </p>
                        </div>
                    )}

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="documentNumber">DNI</Label>
                            <Input
                                id="documentNumber"
                                value={data.documentNumber}
                                onChange={(e) =>
                                    setData('documentNumber', e.target.value)
                                }
                                className="font-mono tabular-nums"
                                inputMode="numeric"
                                autoComplete="off"
                                maxLength={9}
                                aria-invalid={Boolean(errors.documentNumber)}
                            />
                            <InputError message={errors.documentNumber} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="role">Rol</Label>
                            <Select
                                value={data.role}
                                disabled={bloqueoRol !== null}
                                onValueChange={(valor) =>
                                    setData('role', valor)
                                }
                            >
                                <SelectTrigger id="role">
                                    <SelectValue placeholder="Elegí un rol" />
                                </SelectTrigger>
                                <SelectContent>
                                    {Object.entries(roles).map(
                                        ([nombre, descripcion]) => (
                                            <SelectItem
                                                key={nombre}
                                                value={nombre}
                                            >
                                                <span className="capitalize">
                                                    {nombre}
                                                </span>
                                                <span className="block text-xs text-muted-foreground">
                                                    {descripcion}
                                                </span>
                                            </SelectItem>
                                        ),
                                    )}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.role} />
                            {bloqueoRol !== null && (
                                <p className="text-xs text-muted-foreground">
                                    {bloqueoRol}
                                </p>
                            )}
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="email">Correo electrónico</Label>
                        <Input
                            id="email"
                            type="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            disabled={bloqueoCorreo !== null}
                            autoComplete="off"
                            placeholder="nombre@salta.gob.ar"
                            aria-invalid={Boolean(errors.email)}
                        />
                        <InputError message={errors.email} />
                        {bloqueoCorreo !== null && (
                            <p className="text-xs text-muted-foreground">
                                {bloqueoCorreo}
                            </p>
                        )}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="position">
                            Cargo
                            <span className="ml-1 font-normal text-muted-foreground">
                                (opcional)
                            </span>
                        </Label>
                        <Input
                            id="position"
                            value={data.position}
                            onChange={(e) =>
                                setData('position', e.target.value)
                            }
                            autoComplete="off"
                            maxLength={120}
                            placeholder="Asesor Contable"
                            aria-invalid={Boolean(errors.position)}
                        />
                        <InputError message={errors.position} />
                        <p className="text-xs text-muted-foreground">
                            Se imprime debajo de la firma en los comprobantes
                            que emita.
                        </p>
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={onClose}
                            disabled={processing}
                        >
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing
                                ? 'Guardando…'
                                : esAlta
                                  ? 'Crear usuario'
                                  : 'Guardar cambios'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
