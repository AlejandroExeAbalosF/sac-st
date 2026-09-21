import { Link } from '@inertiajs/react';
import { Activity, ShieldCheck, UserRound } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import { actividad, perfil, seguridad } from '@/routes/mi-cuenta';

type Seccion = {
    title: string;
    href: ReturnType<typeof perfil>;
    icon: LucideIcon;
};

const SECCIONES: Seccion[] = [
    { title: 'Perfil', href: perfil(), icon: UserRound },
    { title: 'Seguridad', href: seguridad(), icon: ShieldCheck },
    { title: 'Actividad', href: actividad(), icon: Activity },
];

/**
 * Las tres secciones de la cuenta propia.
 *
 * Horizontal y bajo el encabezado, no en una columna al costado: así la
 * pantalla se lee igual que Caja o Personas, con el contenido ocupando todo
 * el ancho. La barra lateral izquierda ya es la navegación del sistema, y
 * una segunda columna de enlaces al lado hacía que ninguna de las dos se
 * leyera como la principal.
 *
 * La comparación de la URL es exacta y no por prefijo: «Perfil» vive en
 * `/mi-cuenta`, que es prefijo de las otras dos, y con `startsWith` quedaría
 * marcado siempre.
 */
export default function AccountNav() {
    const { isCurrentUrl } = useCurrentUrl();

    return (
        <nav
            className="flex flex-wrap gap-1 border-b"
            aria-label="Secciones de mi cuenta"
        >
            {SECCIONES.map((seccion) => {
                const activa = isCurrentUrl(seccion.href);

                return (
                    <Link
                        key={toUrl(seccion.href)}
                        href={seccion.href}
                        aria-current={activa ? 'page' : undefined}
                        className={cn(
                            'flex items-center gap-2 rounded-t-md px-3 py-2 text-sm font-medium transition-colors',
                            // El subrayado se solapa con el borde del
                            // contenedor: es lo que hace que la pestaña
                            // activa se lea pegada al contenido de abajo.
                            '-mb-px border-b-2',
                            activa
                                ? 'border-primary text-foreground'
                                : 'border-transparent text-muted-foreground hover:border-border hover:text-foreground',
                        )}
                    >
                        <seccion.icon className="size-4" aria-hidden="true" />
                        {seccion.title}
                    </Link>
                );
            })}
        </nav>
    );
}
