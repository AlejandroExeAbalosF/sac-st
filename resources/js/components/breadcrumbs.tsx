import { Link, usePage } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';
import { Fragment } from 'react';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';

/**
 * El camino de migas del encabezado.
 *
 * No recibe props: el camino llega como prop compartida desde
 * `HandleInertiaRequests`, armado en el servidor con los modelos ya vinculados
 * a la ruta. El árbol completo está en `routes/breadcrumbs.php`.
 *
 * **Este componente es el «volver» del sistema.** El penúltimo eslabón apunta
 * siempre a la ruta padre concreta, nunca al historial, así que se comporta
 * igual se haya llegado por el menú, por un enlace directo o después de
 * guardar. Por eso no hay botones de «Volver» sueltos en las pantallas.
 */
export function Breadcrumbs() {
    const { breadcrumbs } = usePage().props;

    if (!breadcrumbs || breadcrumbs.length === 0) {
        return null;
    }

    const actual = breadcrumbs[breadcrumbs.length - 1];

    /*
     * El padre es el último eslabón con destino antes del actual. Se busca
     * desde atrás porque puede haber eslabones sin enlace en el medio, como
     * «Configuración», que agrupa pantallas pero no es ninguna.
     */
    const padre = breadcrumbs
        .slice(0, -1)
        .reverse()
        .find((eslabon) => eslabon.href !== null);

    return (
        <>
            {/*
             * En pantallas angostas el camino entero no entra, y recortarlo
             * con puntos suspensivos deja migas que no se pueden tocar. Se
             * muestra solo el padre: es la única que el operador va a usar, y
             * así el camino se convierte en el botón de volver sin agregar un
             * segundo control que diga lo mismo.
             */}
            <div className="flex min-w-0 items-center text-sm sm:hidden">
                {padre ? (
                    <Link
                        href={padre.href ?? '#'}
                        className="inline-flex min-w-0 items-center gap-1 text-muted-foreground transition-colors hover:text-foreground"
                        aria-label={`Volver a ${padre.title}`}
                    >
                        <ChevronLeft
                            className="size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span className="truncate">{padre.title}</span>
                    </Link>
                ) : (
                    <span className="truncate font-medium">{actual.title}</span>
                )}
            </div>

            <Breadcrumb className="hidden min-w-0 sm:block">
                {/*
                 * `flex-nowrap`: el encabezado tiene alto fijo y un camino de
                 * cuatro niveles envolvería a dos renglones, empujando el
                 * contenido. Se recorta el eslabón largo, que siempre es el
                 * último, y los de arriba quedan enteros porque son los que
                 * se usan para navegar.
                 */}
                <BreadcrumbList className="flex-nowrap">
                    {breadcrumbs.map((eslabon, indice) => {
                        const esUltimo = indice === breadcrumbs.length - 1;

                        return (
                            <Fragment key={`${eslabon.title}-${indice}`}>
                                <BreadcrumbItem className="min-w-0">
                                    {eslabon.href === null ? (
                                        esUltimo ? (
                                            <BreadcrumbPage className="truncate font-medium">
                                                {eslabon.title}
                                            </BreadcrumbPage>
                                        ) : (
                                            <span className="whitespace-nowrap">
                                                {eslabon.title}
                                            </span>
                                        )
                                    ) : (
                                        <BreadcrumbLink asChild>
                                            <Link
                                                href={eslabon.href}
                                                className="whitespace-nowrap"
                                            >
                                                {eslabon.title}
                                            </Link>
                                        </BreadcrumbLink>
                                    )}
                                </BreadcrumbItem>
                                {!esUltimo && (
                                    <BreadcrumbSeparator className="shrink-0" />
                                )}
                            </Fragment>
                        );
                    })}
                </BreadcrumbList>
            </Breadcrumb>
        </>
    );
}
