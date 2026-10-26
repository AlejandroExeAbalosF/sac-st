import { Link } from '@inertiajs/react';
import { ArrowRight, Check, Lock } from 'lucide-react';
import { cn } from '@/lib/utils';

type Paso = App.Modules.Shared.Data.SetupStepData;

/**
 * La puesta en marcha: lo que falta para que el sistema pueda operar.
 *
 * Aparece mientras falte un paso obligatorio y desaparece sola cuando el
 * último se completa; no hay un «ya lo vi» por usuario, porque lo que se
 * mira es el sistema y no a quien entra. El orden es el de las
 * dependencias —primero las cuentas, después las aperturas que las usan—
 * y por eso va numerado.
 *
 * Un paso sin enlace lo hace otro: quien no tiene el permiso ve qué falta
 * y a quién esperar, en vez de un botón que termina en un 403.
 */
export default function SetupChecklist({ steps }: { steps: Paso[] }) {
    const obligatorios = steps.filter((paso) => !paso.optional);
    const hechos = obligatorios.filter((paso) => paso.done).length;
    // El primero sin hacer es el que corresponde ahora: se destaca solo ese.
    const siguiente = obligatorios.find((paso) => !paso.done)?.key;

    return (
        <section
            aria-labelledby="puesta-en-marcha"
            className="overflow-hidden rounded-lg border bg-card shadow-raised"
        >
            <div className="flex flex-wrap items-start gap-x-4 gap-y-1 border-b px-5 py-4">
                <div className="min-w-0 flex-1">
                    <h2 id="puesta-en-marcha" className="text-sm font-semibold">
                        Puesta en marcha
                    </h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Antes de operar, el sistema necesita esto. Se hace una
                        sola vez, en este orden.
                    </p>
                </div>
                <p className="text-xs font-medium text-muted-foreground tabular-nums">
                    {hechos} de {obligatorios.length} listos
                </p>
            </div>

            <ol className="divide-y">
                {steps.map((paso, indice) => (
                    <PasoDeLaLista
                        key={paso.key}
                        paso={paso}
                        numero={indice + 1}
                        actual={paso.key === siguiente}
                    />
                ))}
            </ol>
        </section>
    );
}

function PasoDeLaLista({
    paso,
    numero,
    actual,
}: {
    paso: Paso;
    numero: number;
    actual: boolean;
}) {
    return (
        <li
            className={cn(
                'flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-4',
                actual && 'bg-info-soft/40',
            )}
        >
            <span
                aria-hidden="true"
                className={cn(
                    'flex size-7 shrink-0 items-center justify-center rounded-full border text-xs font-semibold tabular-nums',
                    paso.done
                        ? 'border-success bg-success text-success-foreground'
                        : actual
                          ? 'border-primary text-primary'
                          : 'text-muted-foreground',
                )}
            >
                {paso.done ? <Check className="size-4" /> : numero}
            </span>

            <div className="min-w-0 flex-1 basis-60">
                <p
                    className={cn(
                        'text-sm font-medium',
                        paso.done && 'text-muted-foreground',
                    )}
                >
                    {paso.title}
                    {paso.optional && (
                        <span className="ml-2 rounded-sm bg-muted px-1.5 py-0.5 text-xs font-normal text-muted-foreground">
                            Opcional
                        </span>
                    )}
                    <span className="sr-only">
                        {paso.done ? ' (hecho)' : ' (pendiente)'}
                    </span>
                </p>
                {!paso.done && (
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        {paso.description}
                    </p>
                )}
            </div>

            {/* En el celular baja al renglón siguiente, alineada con el texto. */}
            <div className="max-sm:basis-full max-sm:pl-11">
                <Accion paso={paso} actual={actual} />
            </div>
        </li>
    );
}

function Accion({ paso, actual }: { paso: Paso; actual: boolean }) {
    if (paso.done) {
        return (
            <span className="text-xs font-medium text-success-strong">
                Hecho
            </span>
        );
    }

    if (paso.blockedBy !== null) {
        return (
            <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                <Lock className="size-3.5" aria-hidden="true" />
                {paso.blockedBy}
            </span>
        );
    }

    if (paso.href === null) {
        return (
            <span className="text-xs text-muted-foreground">
                Necesita un permiso que no tenés
            </span>
        );
    }

    return (
        <Link
            href={paso.href}
            className={cn(
                'group inline-flex h-8 items-center gap-1 rounded-md px-3 text-xs font-medium focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                actual
                    ? 'bg-primary text-primary-foreground hover:bg-primary/90'
                    : 'border text-foreground hover:bg-muted',
            )}
        >
            {paso.key === 'cuentas' ? 'Cargar' : 'Abrir'}
            <ArrowRight
                className="size-3.5 transition-transform group-hover:translate-x-0.5"
                aria-hidden="true"
            />
        </Link>
    );
}
