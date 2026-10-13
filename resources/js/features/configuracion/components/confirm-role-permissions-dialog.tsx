import { Minus, Plus, ShieldAlert } from 'lucide-react';
import AlertError from '@/components/alert-error';
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
import { cn } from '@/lib/utils';
import type { CambiosDePermisos, Permiso } from '../role-permissions';
import { plural } from '../role-permissions';
import { MarcaSensible } from './permission-matrix';

type Props = {
    abierto: boolean;
    rol: string;
    usuarios: number;
    cambios: CambiosDePermisos;
    guardando: boolean;
    error: string | null;
    onConfirmar: () => void;
    onCerrar: () => void;
};

/**
 * El resumen antes de guardar la matriz de un rol.
 *
 * El cambio se aplica en el acto a todos los usuarios del rol y queda en
 * la auditoría como operación crítica. Antes salía con un clic en
 * «Guardar», sin que nadie viera junto todo lo que estaba por otorgar: con
 * sesenta casillas, un clic de más en el grupo equivocado pasaba
 * desapercibido hasta que alguien lo usaba.
 *
 * Mientras se guarda el diálogo no se cierra, y si el servidor lo rechaza
 * el motivo se muestra acá mismo, donde está la decisión.
 */
export default function ConfirmRolePermissionsDialog({
    abierto,
    rol,
    usuarios,
    cambios,
    guardando,
    error,
    onConfirmar,
    onCerrar,
}: Props) {
    const otorgaSensibles = cambios.otorgados.some((p) => p.sensitive);

    return (
        <Dialog
            open={abierto}
            onOpenChange={(open) => !open && !guardando && onCerrar()}
        >
            <DialogContent className="sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>
                        ¿Guardar los permisos de{' '}
                        <span className="capitalize">{rol}</span>?
                    </DialogTitle>
                    <DialogDescription>
                        {usuarios === 0
                            ? 'Ningún usuario tiene este rol todavía. '
                            : `Se aplica en el acto a ${plural(usuarios, 'usuario', 'usuarios')} con este rol. `}
                        El cambio queda registrado en la auditoría.
                    </DialogDescription>
                </DialogHeader>

                {otorgaSensibles && (
                    <p className="flex items-start gap-2 rounded-md bg-warning-soft px-3 py-2.5 text-sm text-warning-strong">
                        <ShieldAlert
                            className="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        Se otorgan permisos sensibles: revisá que este rol los
                        necesite.
                    </p>
                )}

                <div className="grid gap-4">
                    <ListaDeCambios
                        titulo="Se otorgan"
                        tipo="otorga"
                        permisos={cambios.otorgados}
                    />
                    <ListaDeCambios
                        titulo="Se retiran"
                        tipo="retira"
                        permisos={cambios.retirados}
                    />
                </div>

                {error !== null && (
                    <AlertError
                        title="No se guardaron los permisos."
                        errors={[error]}
                    />
                )}

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        disabled={guardando}
                        onClick={onCerrar}
                    >
                        Volver a la matriz
                    </Button>
                    <Button
                        type="button"
                        disabled={guardando}
                        onClick={onConfirmar}
                        data-test="confirm-role-permissions"
                    >
                        {guardando && <Spinner />}
                        {guardando ? 'Guardando…' : 'Guardar cambios'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function ListaDeCambios({
    titulo,
    tipo,
    permisos,
}: {
    titulo: string;
    tipo: 'otorga' | 'retira';
    permisos: Permiso[];
}) {
    if (permisos.length === 0) {
        return null;
    }

    const Icono = tipo === 'otorga' ? Plus : Minus;

    return (
        <section>
            <h3 className="text-xs font-semibold tracking-wide text-field-label uppercase">
                {titulo}{' '}
                <span className="font-normal text-muted-foreground tabular-nums">
                    ({permisos.length})
                </span>
            </h3>
            <ul className="mt-2 max-h-56 divide-y overflow-y-auto rounded-md border">
                {permisos.map((permiso) => (
                    <li
                        key={permiso.name}
                        className="flex items-start gap-2.5 px-3 py-2"
                    >
                        <Icono
                            className={cn(
                                'mt-0.5 size-4 shrink-0',
                                tipo === 'otorga'
                                    ? 'text-success-strong'
                                    : 'text-destructive-strong',
                            )}
                            aria-hidden="true"
                        />
                        <span className="min-w-0 flex-1">
                            <span className="flex flex-wrap items-center gap-2 text-sm">
                                {permiso.label}
                                {permiso.sensitive && <MarcaSensible />}
                            </span>
                            <span className="block font-mono text-[0.68rem] text-muted-foreground">
                                {permiso.name}
                            </span>
                        </span>
                    </li>
                ))}
            </ul>
        </section>
    );
}
