import { router } from '@inertiajs/react';
import { KeyRound } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { contrasena } from '@/routes/configuracion/usuarios';

type Usuario = App.Modules.Shared.Data.UserListItemData;

/**
 * La pregunta antes de restablecer una contraseña.
 *
 * Antes el ícono de la llave lo hacía en el acto, y no es una acción que se
 * deshaga: la clave que el usuario venía usando deja de servir y se le
 * cierran todas las sesiones, a mitad de lo que esté cargando. Un clic
 * errado en la fila de al lado dejaba a otra persona afuera del sistema.
 */
export default function ResetPasswordDialog({
    user,
    onClose,
}: {
    user: Usuario | null;
    onClose: () => void;
}) {
    const [processing, setProcessing] = useState(false);

    if (user === null) {
        return null;
    }

    const restablecer = () =>
        router.post(
            contrasena(user.id).url,
            {},
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => {
                    setProcessing(false);
                    onClose();
                },
            },
        );

    return (
        <Dialog
            open
            onOpenChange={(abierto) => !abierto && !processing && onClose()}
        >
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>¿Restablecer la contraseña?</DialogTitle>
                    <DialogDescription>
                        Vas a generar una contraseña temporal nueva para{' '}
                        <strong className="font-medium text-foreground">
                            {user.name}
                        </strong>{' '}
                        ({user.username}).
                    </DialogDescription>
                </DialogHeader>

                <ul className="grid list-disc gap-1.5 pl-5 text-sm text-muted-foreground">
                    <li>La que usa ahora deja de servir.</li>
                    <li>
                        Se cierran todas sus sesiones abiertas, aunque esté
                        trabajando.
                    </li>
                    <li>
                        Al entrar con la temporal, va a tener que elegir una
                        propia.
                    </li>
                </ul>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={onClose}
                        disabled={processing}
                    >
                        Cancelar
                    </Button>
                    <Button
                        type="button"
                        onClick={restablecer}
                        disabled={processing}
                        data-test="confirm-reset-password"
                    >
                        {processing ? (
                            <Spinner />
                        ) : (
                            <KeyRound className="size-4" aria-hidden="true" />
                        )}
                        Restablecer
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
