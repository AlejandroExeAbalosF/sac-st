import { Link } from '@inertiajs/react';
import { FileSpreadsheet, FilePlus2 } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { create as importarExtracto } from '@/routes/banco/extractos';
import { create as nuevoExpediente } from '@/routes/expedientes';

type Permisos = {
    crearExpediente: boolean;
    importarExtracto: boolean;
};

type Accion = {
    title: string;
    description: string;
    href: string;
    icon: LucideIcon;
    permitida: boolean;
};

/**
 * Las tres puertas de entrada del circuito.
 *
 * No son atajos a pantallas —para eso está la barra lateral— sino a las
 * tres **altas** con las que empieza el trabajo: el expediente que llega,
 * el dinero que se recibe y el extracto que hay que importar. La barra
 * lateral navega; esto arranca algo.
 *
 * Lo que el usuario no puede hacer no se muestra apagado, se oculta: es
 * el mismo criterio que la barra lateral usa para las pantallas con
 * permiso, y por la misma razón —una puerta que termina en un 403 es peor
 * que ninguna puerta—.
 */
export default function QuickActions({ can }: { can: Permisos }) {
    const acciones: Accion[] = [
        {
            title: 'Nuevo expediente',
            description: 'Registrar el expediente que acaba de entrar',
            href: nuevoExpediente().url,
            icon: FilePlus2,
            permitida: can.crearExpediente,
        },
        {
            title: 'Importar extracto',
            description: 'Cargar el resumen del banco para identificar fondos',
            href: importarExtracto().url,
            icon: FileSpreadsheet,
            permitida: can.importarExtracto,
        },
    ].filter((accion) => accion.permitida);

    if (acciones.length === 0) {
        return null;
    }

    return (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            {acciones.map((accion) => (
                <Link
                    key={accion.href}
                    href={accion.href}
                    className="group flex items-start gap-3 rounded-lg border bg-card p-4 shadow-raised transition-colors hover:border-primary/40 hover:bg-accent/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <span
                        className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary"
                        aria-hidden="true"
                    >
                        <accion.icon className="size-4" />
                    </span>
                    <span className="min-w-0">
                        <span className="block text-sm font-semibold">
                            {accion.title}
                        </span>
                        <span className="mt-0.5 block text-xs text-muted-foreground">
                            {accion.description}
                        </span>
                    </span>
                </Link>
            ))}
        </div>
    );
}
