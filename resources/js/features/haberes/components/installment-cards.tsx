import { Plus } from 'lucide-react';
import { Fragment, useState } from 'react';
import { Button } from '@/components/ui/button';
import { compareAmounts, money, sumAmounts } from '@/lib/format';
import type { EgresoProps, EtiquetaOption, OrdenDePagoProps } from '../types';
import InstallmentCard from './installment-card';
import InstallmentEditor from './installment-editor';
import type { Firmante } from './issue-receipt-dialog';

type Cuota = App.Modules.Haberes.Data.InstallmentListItemData;

/**
 * El plan de cuotas en la pantalla del haber.
 *
 * Misma orquestación que la lista compacta —qué editor está abierto, cuál
 * es la próxima cuota, cuánto saldo queda— con las cuotas dibujadas como
 * tarjetas. Son dos presentaciones del mismo dato: la compacta vive en el
 * acordeón del expediente, donde la cuota es contexto; esta vive donde la
 * cuota es lo que se vino a mirar.
 */
export default function InstallmentCards({
    expedienteId,
    haberNumber,
    cuentas,
    puedeEditarTicket,
    puedeEmitirRecibo,
    puedeRegistrarPago,
    puedeTrasladar,
    puedeAcreditar,
    puedeDesasignar,
    puedeAnular,
    firmantes,
    orden,
    egreso,
    cuotas,
    previstas,
    totalReconocido,
    etiquetas,
    editable,
}: {
    /** Lo necesita el enlace de carga del comprobante y el de la cuota. */
    expedienteId: number;
    /** El ordinal del haber: es lo que va en la direccion. */
    haberNumber: number;
    /** Para el modal del comprobante, que deja corregir la cuenta. */
    cuentas: { id: number; label: string }[];
    puedeEditarTicket: boolean;
    puedeEmitirRecibo: boolean;
    puedeRegistrarPago: boolean;
    puedeTrasladar: boolean;
    puedeAcreditar: boolean;
    puedeDesasignar: boolean;
    puedeAnular: boolean;
    firmantes: Firmante[];
    /** Todo lo de la Orden de Pago, que la tarjeta pasa hacia abajo. */
    orden: OrdenDePagoProps;
    /** Y lo del egreso, por el mismo camino. */
    egreso: EgresoProps;
    cuotas: Cuota[];
    previstas: number;
    totalReconocido: string;
    etiquetas: EtiquetaOption[];
    editable: boolean;
}) {
    const [editor, setEditor] = useState<
        { mode: 'add' } | { mode: 'edit'; id: number } | null
    >(null);

    const faltan = Math.max(0, previstas - cuotas.length);
    const enEdicion = editor?.mode === 'edit' ? editor.id : null;
    const hayEditor = editor !== null;

    const numerosUsados = new Set(cuotas.map((cuota) => cuota.number));
    let proximoNumero = 1;

    while (numerosUsados.has(proximoNumero)) {
        proximoNumero++;
    }

    /*
     * Con una sola cuota por cargar, el importe que falta es el que va: no
     * hay nada que decidir, y tipearlo a mano es donde entra el error.
     */
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
        <ol className="grid gap-4">
            {cuotas.map((cuota) => (
                <Fragment key={cuota.id}>
                    {enEdicion === cuota.id ? (
                        <li>
                            <InstallmentEditor
                                key={`edit-${cuota.id}-${cuota.updatedAt}`}
                                expedienteId={expedienteId}
                                haberNumber={haberNumber}
                                numero={cuota.number}
                                cuota={cuota}
                                etiquetas={etiquetas}
                                completaPlan={faltan === 0}
                                returnTo="haber"
                                onClose={() => setEditor(null)}
                            />
                        </li>
                    ) : (
                        <InstallmentCard
                            cuota={cuota}
                            total={previstas}
                            editable={editable}
                            cuentas={cuentas}
                            puedeEditarTicket={puedeEditarTicket}
                            puedeEmitirRecibo={puedeEmitirRecibo}
                            puedeRegistrarPago={puedeRegistrarPago}
                            puedeTrasladar={puedeTrasladar}
                            puedeAcreditar={puedeAcreditar}
                            puedeDesasignar={puedeDesasignar}
                            puedeAnular={puedeAnular}
                            firmantes={firmantes}
                            orden={orden}
                            egreso={egreso}
                            // Con el editor abierto el resto se apaga, pero
                            // no se oculta: los importes de las otras cuotas
                            // son contra lo que se controla el que se edita.
                            atenuada={hayEditor}
                            onEditar={() =>
                                setEditor({ mode: 'edit', id: cuota.id })
                            }
                        />
                    )}
                </Fragment>
            ))}

            {faltan > 0 &&
                (editor?.mode === 'add' ? (
                    <li>
                        <InstallmentEditor
                            key={`add-${proximoNumero}`}
                            expedienteId={expedienteId}
                            haberNumber={haberNumber}
                            numero={proximoNumero}
                            etiquetas={etiquetas}
                            importeSugerido={importeSugerido}
                            completaPlan={faltan === 1}
                            returnTo="haber"
                            onClose={() => setEditor(null)}
                        />
                    </li>
                ) : (
                    <li
                        className={`flex flex-wrap items-center gap-3 rounded-lg border border-dashed px-4 py-4 text-sm text-muted-foreground transition-opacity duration-200 sm:px-5 ${
                            hayEditor ? 'opacity-40' : ''
                        }`}
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
                                className="ml-auto bg-card"
                                onClick={() => setEditor({ mode: 'add' })}
                            >
                                <Plus className="size-4" aria-hidden="true" />
                                Cargar la cuota {proximoNumero}
                            </Button>
                        )}
                    </li>
                ))}
        </ol>
    );
}
