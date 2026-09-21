import { Head } from '@inertiajs/react';
import { TriangleAlert } from 'lucide-react';
import Money from '@/components/money';
import PageHeader from '@/components/page-header';
import type { PersonOption } from '@/components/person-picker';
import HaberForm from '@/features/haberes/components/haber-form';
import type { EtiquetaOption } from '@/features/haberes/types';
import { compareAmounts, isNonZero } from '@/lib/format';
import { show } from '@/routes/expedientes';

type Props = {
    expediente: App.Modules.Haberes.Data.ExpedienteListItemData;
    beneficiarios: PersonOption[];
    etiquetas: EtiquetaOption[];
};

/**
 * Alta de un haber, en su propia pantalla.
 *
 * Estaba dentro del detalle, debajo de los haberes ya cargados, y ahí el
 * formulario se leía como uno más de la lista. Separarlo visualmente no
 * alcanzaba: con cinco haberes desplegados arriba, cada uno con sus
 * cuotas, el formulario quedaba a dos pantallas de distancia.
 *
 * Lo que no se pierde al mudarlo es el contexto. Arriba va el expediente
 * con lo que ya reconoce y a quiénes, porque es contra eso que se controla
 * el importe nuevo y que se evita cargar dos veces al mismo beneficiario
 * —el error que el servidor rechaza, pero que conviene no cometer—.
 */
export default function CrearHaber({
    expediente,
    beneficiarios,
    etiquetas,
}: Props) {
    const yaCargados = expediente.haberes.length;

    // Solo se compara contra el declarado cuando lo hay: avisar contra un
    // cero que nadie cargó es ruido.
    const difiereDelDeclarado =
        isNonZero(expediente.declaredTotalAmount) &&
        compareAmounts(
            expediente.recognizedTotalAmount,
            expediente.declaredTotalAmount,
        ) !== 0;

    return (
        <>
            <Head title={`Nuevo haber · ${expediente.displayNumber}`} />

            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Nuevo haber"
                    eyebrow={
                        <span className="font-mono">
                            {expediente.canonicalNumber}
                        </span>
                    }
                    description={
                        expediente.subject ?? (
                            <span className="italic">Sin carátula</span>
                        )
                    }
                />

                {/*
                 * El contexto en una tira, no en una tarjeta: tiene que
                 * poder consultarse sin competir con el formulario, que es
                 * lo que se vino a hacer.
                 */}
                <div className="rounded-lg border bg-muted/30 px-5 py-4">
                    <dl className="grid gap-x-8 gap-y-3 sm:grid-cols-3">
                        <div>
                            <dt className="text-xs tracking-wide text-field-label uppercase">
                                Empleador
                            </dt>
                            <dd className="mt-0.5 text-sm">
                                {expediente.employerName}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs tracking-wide text-field-label uppercase">
                                Ya reconocido
                            </dt>
                            <dd className="mt-0.5 text-sm">
                                <Money
                                    value={expediente.recognizedTotalAmount}
                                    dimWhenZero
                                />
                                {isNonZero(expediente.declaredTotalAmount) && (
                                    <span className="text-muted-foreground">
                                        {' de '}
                                        <Money
                                            value={
                                                expediente.declaredTotalAmount
                                            }
                                        />
                                        {' declarados'}
                                    </span>
                                )}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs tracking-wide text-field-label uppercase">
                                Beneficiarios cargados
                            </dt>
                            <dd className="mt-0.5 text-sm">
                                {yaCargados === 0 ? (
                                    <span className="text-muted-foreground">
                                        Ninguno todavía
                                    </span>
                                ) : (
                                    /*
                                     * Los nombres, no la cuenta: están para
                                     * que se vea de un vistazo si el
                                     * beneficiario que se va a cargar ya
                                     * está, que es el error más fácil de
                                     * cometer con un acta de cinco personas.
                                     */
                                    <span className="text-muted-foreground">
                                        {expediente.haberes
                                            .map(
                                                (haber) =>
                                                    haber.beneficiaryName,
                                            )
                                            .join(' · ')}
                                    </span>
                                )}
                            </dd>
                        </div>
                    </dl>

                    {difiereDelDeclarado && yaCargados > 0 && (
                        <p className="mt-3 flex items-center gap-2 border-t pt-3 text-xs text-warning-strong">
                            <TriangleAlert
                                className="size-4 shrink-0"
                                aria-hidden="true"
                            />
                            Lo reconocido todavía no coincide con el total
                            declarado.
                        </p>
                    )}
                </div>

                <HaberForm
                    expedienteId={expediente.id}
                    beneficiarios={beneficiarios}
                    etiquetas={etiquetas}
                    volverA={show(expediente.id).url}
                />
            </div>
        </>
    );
}
