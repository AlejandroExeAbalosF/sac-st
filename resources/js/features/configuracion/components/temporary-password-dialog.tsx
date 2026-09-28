import { Check, ClipboardCopy, Copy, TriangleAlert } from 'lucide-react';
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
import { useClipboard } from '@/hooks/use-clipboard';
import { login } from '@/routes';

export type TemporaryPassword = {
    username: string;
    name: string;
    password: string;
    /** Si viene de «Restablecer contraseña» y no de un alta. */
    isReset: boolean;
};

/**
 * La contraseña temporal, mostrada una única vez.
 *
 * No se guarda en ningún lado y no hay pantalla que permita volver a
 * consultarla: si se pierde, se restablece. Eso no es una limitación del
 * diseño, es la propiedad que hace que la clave la termine conociendo una
 * sola persona.
 *
 * El diálogo no se cierra con Escape ni haciendo clic afuera, y si no se
 * copió nada pregunta antes de cerrar: cerrarlo sin haberla entregado
 * obliga a restablecerla, y ese descuido se comete una vez cada tres altas.
 * La pregunta no bloquea —se la puede haber dictado por teléfono—, solo
 * frena el clic distraído.
 */
export default function TemporaryPasswordDialog({
    credentials,
    onClose,
}: {
    credentials: TemporaryPassword | null;
    onClose: () => void;
}) {
    const [copiado, copiar] = useClipboard();
    const [confirmando, setConfirmando] = useState(false);
    const [fallo, setFallo] = useState(false);

    if (credentials === null) {
        return null;
    }

    const mensaje = accessMessage(credentials);
    const claveCopiada = copiado === credentials.password;
    const mensajeCopiado = copiado === mensaje;
    const algoCopiado = claveCopiada || mensajeCopiado;

    const copiarTexto = async (texto: string) => {
        setFallo(!(await copiar(texto)));
        setConfirmando(false);
    };

    const cerrar = () => {
        if (!algoCopiado && !confirmando) {
            setConfirmando(true);

            return;
        }

        // El componente sigue montado entre una clave y la siguiente.
        setConfirmando(false);
        setFallo(false);
        onClose();
    };

    return (
        <Dialog open onOpenChange={(abierto) => !abierto && cerrar()}>
            <DialogContent
                className="sm:max-w-lg"
                onEscapeKeyDown={(e) => e.preventDefault()}
                onInteractOutside={(e) => e.preventDefault()}
            >
                <DialogHeader>
                    <DialogTitle>
                        {credentials.isReset
                            ? 'Contraseña restablecida'
                            : 'Usuario creado'}
                    </DialogTitle>
                    <DialogDescription>
                        {credentials.isReset
                            ? `La anterior de ${credentials.name} ya no sirve y se cerraron sus sesiones. Entregale esta contraseña temporal: al entrar va a elegir una propia.`
                            : `Entregale estos datos a ${credentials.name}. Al entrar por primera vez va a elegir su propia contraseña.`}
                    </DialogDescription>
                </DialogHeader>

                <dl className="grid gap-3 rounded-lg border bg-muted/30 p-4 text-sm">
                    <div className="flex items-baseline justify-between gap-4">
                        <dt className="text-xs tracking-wide text-field-label uppercase">
                            Usuario
                        </dt>
                        <dd className="font-mono">{credentials.username}</dd>
                    </div>

                    <div className="grid gap-2 border-t pt-3">
                        <dt className="text-xs tracking-wide text-field-label uppercase">
                            Contraseña temporal
                        </dt>
                        <dd className="flex items-center gap-2">
                            <PasswordChunks password={credentials.password} />
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                className="shrink-0"
                                aria-label={
                                    claveCopiada
                                        ? 'Contraseña copiada'
                                        : 'Copiar la contraseña'
                                }
                                onClick={() =>
                                    void copiarTexto(credentials.password)
                                }
                            >
                                {claveCopiada ? (
                                    <Check
                                        className="size-4 text-success-strong"
                                        aria-hidden="true"
                                    />
                                ) : (
                                    <Copy
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                )}
                            </Button>
                        </dd>
                    </div>
                </dl>

                {/*
                 * El mensaje completo es lo que termina pegándose en un
                 * chat o un correo: usuario, contraseña y dónde entrar.
                 * Armarlo a mano era el momento de copiar mal la clave.
                 */}
                <Button
                    type="button"
                    variant="secondary"
                    className="justify-start"
                    onClick={() => void copiarTexto(mensaje)}
                >
                    {mensajeCopiado ? (
                        <Check
                            className="size-4 text-success-strong"
                            aria-hidden="true"
                        />
                    ) : (
                        <ClipboardCopy className="size-4" aria-hidden="true" />
                    )}
                    {mensajeCopiado
                        ? 'Datos de acceso copiados'
                        : 'Copiar los datos de acceso para enviar'}
                </Button>

                <p aria-live="polite" className="sr-only">
                    {mensajeCopiado
                        ? 'Datos de acceso copiados.'
                        : claveCopiada
                          ? 'Contraseña copiada.'
                          : ''}
                </p>

                {fallo && (
                    <p role="alert" className="text-sm text-destructive-strong">
                        El navegador no dejó copiar. Seleccioná la contraseña y
                        copiala a mano.
                    </p>
                )}

                <p
                    role={confirmando ? 'alert' : undefined}
                    className="flex items-start gap-2 rounded-lg border border-warning bg-warning-soft px-3 py-2 text-xs text-warning-strong"
                >
                    <TriangleAlert
                        className="mt-0.5 size-3.5 shrink-0"
                        aria-hidden="true"
                    />
                    {confirmando
                        ? 'Todavía no copiaste nada. Si no la anotaste ni se la dictaste, al cerrar hay que restablecerla.'
                        : 'Es la única vez que se muestra. Si cerrás sin entregarla, hay que restablecerla.'}
                </p>

                <DialogFooter>
                    {confirmando && (
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => setConfirmando(false)}
                        >
                            Volver
                        </Button>
                    )}
                    <Button type="button" onClick={cerrar}>
                        {confirmando ? 'Sí, ya la entregué' : 'Ya la entregué'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/**
 * La clave en grupos de cuatro, para dictarla sin perderse.
 *
 * Los grupos se separan con margen y no con espacios: si alguien la
 * selecciona y la copia a mano, se lleva la clave tal cual, sin espacios
 * que después no la dejen entrar. Tampoco hay una copia oculta para el
 * lector de pantalla, que viajaría en esa misma selección: los grupos se
 * leen de a uno, que es como se dicta.
 */
function PasswordChunks({ password }: { password: string }) {
    const grupos = [...password].reduce<string[]>((acc, char, i) => {
        if (i % 4 === 0) {
            acc.push('');
        }

        acc[acc.length - 1] += char;

        return acc;
    }, []);

    return (
        <code className="min-w-0 flex-1 rounded-md border bg-card px-3 py-2 font-mono text-base tracking-wider select-all">
            {grupos.map((grupo, i) => (
                <span key={i} className="inline-block not-first:ml-2.5">
                    {grupo}
                </span>
            ))}
        </code>
    );
}

function accessMessage({ username, password }: TemporaryPassword): string {
    return [
        'Tus datos para entrar al SAC:',
        `Dirección: ${window.location.origin}${login().url}`,
        `Usuario: ${username}`,
        `Contraseña temporal: ${password}`,
        'Al entrar vas a elegir tu propia contraseña.',
    ].join('\n');
}
