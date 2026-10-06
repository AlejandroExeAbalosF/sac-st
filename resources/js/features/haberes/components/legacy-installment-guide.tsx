import { ArrowRight, Lock } from 'lucide-react';
import { useState } from 'react';
import Money from '@/components/money';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import type {
    CaminoHistorico,
    OpcionHistorica,
} from '@/features/haberes/legacy-options';
import { cn } from '@/lib/utils';

type Cuota = App.Modules.Haberes.Data.InstallmentListItemData;

/** Cada respuesta, con lo que significa elegirla. */
const RESPUESTAS: {
    camino: CaminoHistorico;
    titulo: string;
    explicacion: string;
}[] = [
    {
        camino: 'pagada',
        titulo: 'Sí, ya cobró',
        explicacion:
            'Antes de usar el sistema o desde Pagos anteriores. Se cargan los papeles como registro histórico: no mueve plata de la caja.',
    },
    {
        camino: 'pendiente',
        titulo: 'No, todavía no cobró',
        explicacion:
            'Su plata está en la caja desde antes del sistema: en efectivo, en un cheque o en la cuenta. Se reserva para esta cuota y se le paga por el circuito normal.',
    },
];

/**
 * El primer paso de una cuota del sistema anterior: una sola pregunta.
 *
 * Los dos caminos —dar la cuota por pagada o reservarle plata— se parecen
 * en la pantalla y no en el efecto, y elegir mal no se nota: una cuota
 * registrada como pagada cuya plata seguía en la caja deja esa plata sin
 * dueño en el saldo del sistema anterior. Por eso no se elige entre dos
 * botones sino que se responde algo que el operador sí sabe: si el
 * beneficiario cobró. El segundo paso es el formulario que corresponde.
 *
 * Una respuesta que no se puede tomar se muestra apagada con su motivo, en
 * vez de esconderla: un camino que desaparece hace pensar que el sistema
 * no contempla el caso.
 */
export default function LegacyInstallmentGuide({
    cuota,
    opciones,
    elegidoAntes = null,
    abierto,
    onCerrar,
    onContinuar,
}: {
    cuota: Cuota;
    opciones: Record<CaminoHistorico, OpcionHistorica>;
    /** Lo que se había respondido, al volver desde el segundo paso. */
    elegidoAntes?: CaminoHistorico | null;
    abierto: boolean;
    onCerrar: () => void;
    onContinuar: (camino: CaminoHistorico) => void;
}) {
    // Si queda un solo camino, ya viene elegido: la pregunta igual se lee.
    const unico = RESPUESTAS.filter((r) => opciones[r.camino].disponible);
    const [elegido, setElegido] = useState<CaminoHistorico | null>(() => {
        if (elegidoAntes !== null && opciones[elegidoAntes].disponible) {
            return elegidoAntes;
        }

        return unico.length === 1 ? unico[0].camino : null;
    });

    const ninguno = unico.length === 0;

    return (
        <Dialog open={abierto} onOpenChange={(v) => !v && onCerrar()}>
            <DialogContent className="sm:max-w-xl">
                <DialogHeader>
                    <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        Paso 1 de 2
                    </p>
                    <DialogTitle>
                        Cuota {cuota.number} del sistema anterior
                    </DialogTitle>
                    <DialogDescription>
                        La cuota es de <Money value={cuota.expectedAmount} />.
                        Respondé una pregunta y se abre el formulario que
                        corresponde.
                    </DialogDescription>
                </DialogHeader>

                <fieldset className="grid gap-3">
                    <legend
                        id={`pregunta-historica-${cuota.id}`}
                        className="mb-3 text-sm font-medium"
                    >
                        ¿El beneficiario ya cobró esta cuota?
                    </legend>

                    <RadioGroup
                        value={elegido ?? ''}
                        onValueChange={(valor) =>
                            setElegido(valor as CaminoHistorico)
                        }
                        aria-labelledby={`pregunta-historica-${cuota.id}`}
                    >
                        {RESPUESTAS.map((respuesta) => {
                            const opcion = opciones[respuesta.camino];
                            const id = `historica-${respuesta.camino}-${cuota.id}`;
                            const marcada = elegido === respuesta.camino;

                            return (
                                <label
                                    key={respuesta.camino}
                                    htmlFor={id}
                                    className={cn(
                                        'flex items-start gap-3 rounded-lg border p-4 transition-colors',
                                        opcion.disponible
                                            ? 'cursor-pointer hover:bg-muted/40'
                                            : 'cursor-not-allowed bg-muted/30',
                                        marcada &&
                                            'border-primary bg-primary/5 hover:bg-primary/5',
                                    )}
                                >
                                    <RadioGroupItem
                                        id={id}
                                        value={respuesta.camino}
                                        disabled={!opcion.disponible}
                                        aria-describedby={`${id}-explicacion`}
                                        className="mt-0.5"
                                    />
                                    <span className="grid gap-1">
                                        <span
                                            className={cn(
                                                'text-sm font-medium',
                                                !opcion.disponible &&
                                                    'text-muted-foreground',
                                            )}
                                        >
                                            {respuesta.titulo}
                                        </span>
                                        <span
                                            id={`${id}-explicacion`}
                                            className="text-xs leading-relaxed text-muted-foreground"
                                        >
                                            {respuesta.explicacion}
                                            {opcion.motivo !== null && (
                                                <span className="mt-2 flex items-start gap-1.5 text-foreground">
                                                    <Lock
                                                        className="mt-0.5 size-3 shrink-0"
                                                        aria-hidden="true"
                                                    />
                                                    <span>
                                                        <span className="sr-only">
                                                            No disponible:{' '}
                                                        </span>
                                                        {opcion.motivo}
                                                    </span>
                                                </span>
                                            )}
                                        </span>
                                    </span>
                                </label>
                            );
                        })}
                    </RadioGroup>
                </fieldset>

                <p className="text-xs text-muted-foreground">
                    ¿No estás seguro? Si su plata todavía está en el cajón, en
                    un cheque de la cartera o en la cuenta, no cobró.
                </p>

                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={onCerrar}>
                        {ninguno ? 'Cerrar' : 'Cancelar'}
                    </Button>
                    {!ninguno && (
                        <Button
                            type="button"
                            disabled={elegido === null}
                            onClick={() =>
                                elegido !== null && onContinuar(elegido)
                            }
                        >
                            Continuar
                            <ArrowRight className="size-4" aria-hidden="true" />
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
