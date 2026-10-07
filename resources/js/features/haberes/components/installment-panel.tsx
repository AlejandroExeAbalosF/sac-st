import { History, TriangleAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
import Money from '@/components/money';
import StatusBadge from '@/components/status-badge';
import {
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { useDrawer } from '@/features/drawer/drawer-context';
import { Bloque, Dato, Enlace } from '@/features/drawer/panel-blocks';
import { ETAPA, TONO_ETAPA } from '@/features/haberes/installment-stage';
import { date, dateTime } from '@/lib/format';
import { show as expedienteShow } from '@/routes/expedientes';
import { show as haberShow } from '@/routes/haberes/haber';
import {
    history as historialDeCuota,
    panel,
} from '@/routes/haberes/installments';

type Panel = App.Modules.Haberes.Data.InstallmentPanelData;

const MEDIO: Record<string, string> = {
    cash: 'Efectivo',
    cheque: 'Cheque',
    bank: 'Depósito directo',
};

/**
 * Una cuota, vista desde otra pantalla.
 *
 * Contesta «¿qué cuota es esta?» sin salir de donde se la nombró —la
 * cartera de cheques, lo reservado del sistema anterior—: de quién es, de
 * qué expediente, cuánto, y en qué punto del circuito está. Para todo lo
 * demás, el enlace a la ficha del haber.
 *
 * Pide los datos cada vez que se abre, como el panel de un recibo: el
 * panel sobrevive a las navegaciones y lo que guardara envejecería sin
 * aviso.
 */
export default function InstallmentPanel({
    installmentId,
}: {
    installmentId: number;
}) {
    const { openDrawer } = useDrawer();
    const [datos, setDatos] = useState<Panel | null>(null);
    const [falló, setFalló] = useState(false);
    const [consultadoA, setConsultadoA] = useState<string | null>(null);

    // El host remonta este componente por `key` cuando cambia la cuota.
    useEffect(() => {
        let vigente = true;

        fetch(panel(installmentId).url, {
            headers: { Accept: 'application/json' },
        })
            .then((r) => {
                if (!r.ok) {
                    throw new Error(String(r.status));
                }

                return r.json();
            })
            .then((d: Panel) => {
                if (!vigente) {
                    return;
                }

                setDatos(d);
                setConsultadoA(new Date().toISOString());
            })
            .catch(() => vigente && setFalló(true));

        return () => {
            vigente = false;
        };
    }, [installmentId]);

    const sujeto = datos?.subject;

    return (
        <>
            <SheetHeader className="border-b">
                <SheetTitle>
                    {datos && sujeto
                        ? `Cuota ${sujeto.installmentNumber} de ${datos.installmentsTotal}`
                        : 'Cuota'}
                </SheetTitle>
                <SheetDescription>
                    {sujeto
                        ? `Expediente ${sujeto.expedienteNumber} · ${sujeto.beneficiaryName}`
                        : 'Buscando la cuota…'}
                </SheetDescription>
            </SheetHeader>

            <div className="flex-1 space-y-5 px-4 py-4">
                {falló && (
                    <p className="flex items-start gap-2 rounded-md border border-current bg-destructive-soft p-3 text-sm text-destructive-strong">
                        <TriangleAlert
                            className="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        No se pudo traer la cuota. Cerrá el panel y volvé a
                        intentarlo.
                    </p>
                )}

                {!falló && datos === null && (
                    <div className="space-y-3">
                        <Skeleton className="h-24 w-full" />
                        <Skeleton className="h-28 w-full" />
                    </div>
                )}

                {datos && sujeto && (
                    <>
                        <Bloque titulo="La cuota">
                            <Dato etiqueta="Importe">
                                <Money value={datos.expectedAmount} />
                            </Dato>
                            <Dato etiqueta="Etapa">
                                <StatusBadge
                                    label={ETAPA[datos.stage]}
                                    tone={TONO_ETAPA[datos.stage]}
                                />
                            </Dato>
                            <Dato etiqueta="Financiado">
                                <Money value={datos.fundedAmount} dimWhenZero />
                                {datos.fundedFromLegacy && (
                                    <span className="mt-0.5 block text-xs text-muted-foreground">
                                        Reservado del sistema anterior
                                    </span>
                                )}
                            </Dato>
                            <Dato etiqueta="Medio previsto">
                                {MEDIO[datos.expectedMedium] ??
                                    datos.expectedMedium}
                            </Dato>
                            {datos.dueDate && (
                                <Dato etiqueta="Vence">
                                    {date(datos.dueDate)}
                                </Dato>
                            )}
                            {sujeto.concept && (
                                <Dato etiqueta="Concepto">
                                    {sujeto.concept}
                                </Dato>
                            )}
                        </Bloque>

                        <Bloque titulo="De quién es">
                            <Dato etiqueta="Beneficiario">
                                {sujeto.beneficiaryName}
                                {sujeto.beneficiaryDocument && (
                                    <span className="mt-0.5 block font-mono text-xs text-muted-foreground tabular-nums">
                                        {sujeto.beneficiaryDocument}
                                    </span>
                                )}
                            </Dato>
                            <Dato etiqueta="Empleador">
                                {sujeto.employerName}
                            </Dato>

                            <div className="col-span-2 flex flex-wrap gap-2 pt-1">
                                <Enlace
                                    href={
                                        haberShow([
                                            sujeto.expedienteId,
                                            sujeto.haberNumber,
                                        ]).url
                                    }
                                >
                                    Ver el haber
                                </Enlace>
                                <Enlace
                                    href={
                                        expedienteShow(sujeto.expedienteId).url
                                    }
                                >
                                    Expediente {sujeto.expedienteNumber}
                                </Enlace>
                                <button
                                    type="button"
                                    onClick={() =>
                                        openDrawer({
                                            kind: 'history',
                                            subject: 'installment',
                                            url: historialDeCuota(
                                                sujeto.installmentId,
                                            ).url,
                                            label: `de la cuota ${sujeto.installmentNumber}`,
                                        })
                                    }
                                    className="inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-xs font-medium transition-colors hover:bg-muted"
                                >
                                    <History
                                        className="size-3.5"
                                        aria-hidden="true"
                                    />
                                    Historial de la cuota
                                </button>
                            </div>
                        </Bloque>
                    </>
                )}
            </div>

            {consultadoA && (
                <p className="border-t px-4 py-3 text-xs text-muted-foreground">
                    Consultado {dateTime(consultadoA)}
                </p>
            )}
        </>
    );
}
