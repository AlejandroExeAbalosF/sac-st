import { Lock, Plus } from 'lucide-react';
import { useState } from 'react';
import Money from '@/components/money';
import { Button } from '@/components/ui/button';
import { salidaDe } from '@/features/haberes/installment-channel';
import { ETAPA } from '@/features/haberes/installment-stage';
import type { EtiquetaOption } from '@/features/haberes/types';
import { compareAmounts, date, money, sumAmounts } from '@/lib/format';
import { cn } from '@/lib/utils';
import InstallmentEditor from './installment-editor';

type Cuota = App.Modules.Haberes.Data.InstallmentListItemData;
type CuotaStatus = App.Modules.Haberes.Enums.InstallmentWorkflowStatus;

const MEDIO: Record<
    NonNullable<App.Modules.Haberes.Enums.ExpectedMedium>,
    string
> = {
    cash: 'Efectivo',
    bank: 'Depósito en cuenta',
    cheque: 'Cheque',
};

/**
 * El medio real, solo cuando difiere del previsto.
 *
 * `effectiveMedium` cae en el previsto mientras no haya plata, así que una
 * diferencia significa que ya entró dinero y entró por otro lado. El
 * previsto se edita libremente y puede quedar contradiciendo a un hecho ya
 * asentado (§2.4.6): mostrar solo la expectativa haría que la fila afirme
 * algo que el libro desmiente.
 */
function medioRealDe(cuota: Cuota): string | null {
    if (
        cuota.effectiveMedium === null ||
        cuota.effectiveMedium === cuota.expectedMedium
    ) {
        return null;
    }

    return MEDIO[cuota.effectiveMedium as keyof typeof MEDIO] ?? null;
}

const ETIQUETA: Record<CuotaStatus, string> = {
    active: 'Pendiente',
    suspended: 'Suspendida',
    blocked: 'Bloqueada',
    cancelled: 'Anulada',
    paid: 'Pagada',
};

/**
 * Las cuotas de un haber.
 *
 * Pueden ser menos que las previstas: el expediente llega con la primera y
 * las siguientes van apareciendo con cada ticket. La fila final hace
 * visible esa diferencia y permite cargar la próxima cuando corresponde.
 */
export default function InstallmentList({
    expedienteId,
    haberNumber,
    cuotas,
    previstas,
    totalReconocido,
    etiquetas,
    editable,
    onEditorChange,
}: {
    expedienteId: number;
    haberNumber: number;
    cuotas: Cuota[];
    previstas: number;
    totalReconocido: string;
    etiquetas: EtiquetaOption[];
    editable: boolean;
    /**
     * Avisa cuándo se abre y se cierra el editor.
     *
     * Lo necesita el nivel de arriba para apagar los demás haberes: con
     * cinco en pantalla, un formulario abierto en el tercero se pierde
     * entre todo lo que sigue encendido alrededor.
     */
    onEditorChange?: (abierto: boolean) => void;
}) {
    const faltan = Math.max(0, previstas - cuotas.length);

    /*
     * Acá solo se agrega la cuota que llega con su ticket. Corregir una ya
     * cargada se hace en la pantalla del haber, que es la única que muestra
     * el recibo, las imputaciones y el traslado: son lo que hay que tener a
     * la vista para tocar una cuota, y este renglón no los tiene.
     */
    const [agregando, setAgregando] = useState(false);

    const abrirEditor = (abierto: boolean) => {
        setAgregando(abierto);
        onEditorChange?.(abierto);
    };
    const numerosUsados = new Set(cuotas.map((cuota) => cuota.number));
    let proximoNumero = 1;

    while (numerosUsados.has(proximoNumero)) {
        proximoNumero++;
    }

    const sumaCargada = sumAmounts(
        cuotas
            .filter((cuota) => cuota.status !== 'cancelled')
            .map((cuota) => cuota.expectedAmount),
    );
    const saldo = sumAmounts([
        totalReconocido,
        `-${sumaCargada.replace('-', '')}`,
    ]);
    const importeSugerido =
        faltan === 1 && compareAmounts(saldo, '0.00') === 1
            ? money(saldo, { symbol: false })
            : undefined;

    return (
        <ol className="mt-3 grid gap-1.5">
            {cuotas.map((cuota) => (
                /*
                 * La cuota va rellena y no casi blanca. Estas filas viven
                 * adentro de la tarjeta del haber, que ya es blanca: con un
                 * treinta por ciento de gris quedaban a un punto de
                 * luminosidad del fondo y la lista se leia como un bloque
                 * continuo. El relleno es lo que las separa entre si y del
                 * haber que las contiene.
                 *
                 * El borde queda neutro en los dos estados: lo que distingue
                 * a la pagada es el color de adentro, no el contorno, y asi
                 * todas las filas tienen el mismo canto.
                 *
                 * Con el alta abierta todo lo demas se apaga. No se oculta:
                 * el importe de las otras cuotas es justamente contra lo que
                 * se controla el que se esta cargando, y esconderlo
                 * obligaria a cerrar para consultarlo.
                 */
                <li
                    key={cuota.id}
                    className={cn(
                        'flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md border px-3 py-2 text-xs transition-opacity duration-200',
                        cuota.status === 'paid'
                            ? 'bg-success-soft'
                            : 'bg-muted',
                        agregando && 'opacity-40',
                    )}
                >
                    <span className="font-mono font-medium tabular-nums">
                        {cuota.number}ª
                    </span>

                    <Money value={cuota.expectedAmount} />

                    {cuota.concept && (
                        <span className="text-muted-foreground">
                            {cuota.concept}
                        </span>
                    )}

                    {/*
                     * Con su rotulo: «Efectivo» a secas, entre el concepto y
                     * el vencimiento, no decia si era lo previsto o lo que
                     * pasó. El caso «sin definir» se fue con el NOT NULL de
                     * 09/2026.
                     */}
                    <span className="text-muted-foreground">
                        Medio previsto:{' '}
                        <span className="text-foreground">
                            {MEDIO[cuota.expectedMedium]}
                        </span>
                        {salidaDe(cuota) !== null && (
                            <span className="ml-1 text-warning-strong">
                                {salidaDe(cuota)}
                            </span>
                        )}
                    </span>

                    {medioRealDe(cuota) !== null && (
                        <span className="text-warning-strong">
                            entró por {medioRealDe(cuota)}
                        </span>
                    )}

                    {cuota.dueDate && (
                        <span className="text-muted-foreground">
                            vence {date(cuota.dueDate)}
                        </span>
                    )}

                    <span className="ml-auto flex items-center gap-2">
                        {cuota.managementLabel && (
                            <span
                                className={cn(
                                    'rounded px-1.5 py-0.5 font-mono text-[0.65rem] tracking-tight',
                                    cuota.blocksPayment
                                        ? 'bg-warning-soft text-warning-strong'
                                        : 'bg-muted text-muted-foreground',
                                )}
                                title={
                                    cuota.blocksPayment
                                        ? 'Esta etiqueta impide emitir la Orden de Pago'
                                        : undefined
                                }
                            >
                                {cuota.blocksPayment && (
                                    <Lock
                                        className="mr-1 inline size-2.5"
                                        aria-hidden="true"
                                    />
                                )}
                                {cuota.managementLabel}
                            </span>
                        )}
                        {/*
                         * La etapa y no el estado crudo. `status` tiene tres
                         * respuestas para todo el recorrido, así que una
                         * cuota con la Orden ya emitida decía «Pendiente»,
                         * igual que una que todavía no cobró un peso.
                         *
                         * El estado sigue mandando cuando la cuota está
                         * fuera del circuito —anulada, bloqueada—: ahí la
                         * etapa dice lo mismo y el fallback no se usa.
                         */}
                        <span
                            className={cn(
                                cuota.status === 'paid'
                                    ? 'text-success-strong'
                                    : 'text-muted-foreground',
                            )}
                        >
                            {cuota.stage
                                ? ETAPA[cuota.stage]
                                : ETIQUETA[cuota.status]}
                        </span>
                    </span>

                    {cuota.notes && (
                        <p className="w-full text-muted-foreground italic">
                            {cuota.notes}
                        </p>
                    )}
                </li>
            ))}

            {faltan > 0 && !agregando && (
                <li
                    className={cn(
                        'flex min-h-11 flex-wrap items-center gap-2 rounded-md border border-dashed px-3 py-2 text-xs text-muted-foreground transition-opacity duration-200',
                        agregando && 'opacity-40',
                    )}
                >
                    <span>
                        {faltan === 1
                            ? 'Falta cargar 1 cuota, que llega con su ticket.'
                            : `Faltan cargar ${faltan} cuotas, que llegan con sus tickets.`}
                    </span>
                    {editable && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="ml-auto h-10 bg-card sm:h-8"
                            onClick={() => abrirEditor(true)}
                        >
                            <Plus className="size-3.5" aria-hidden="true" />
                            Cargar próxima cuota
                        </Button>
                    )}
                </li>
            )}

            {faltan > 0 && agregando && (
                <li>
                    <InstallmentEditor
                        key={`add-${proximoNumero}`}
                        expedienteId={expedienteId}
                        haberNumber={haberNumber}
                        numero={proximoNumero}
                        etiquetas={etiquetas}
                        importeSugerido={importeSugerido}
                        completaPlan={faltan === 1}
                        returnTo="expediente"
                        onClose={() => abrirEditor(false)}
                    />
                </li>
            )}
        </ol>
    );
}
