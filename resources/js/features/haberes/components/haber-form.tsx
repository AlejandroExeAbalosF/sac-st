import { Link, router, usePage } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { useState } from 'react';
import AmountInput from '@/components/amount-input';
import CollapsibleSection from '@/components/collapsible-section';
import PersonPicker from '@/components/person-picker';
import type {
    PersonBankAccountOption,
    PersonOption,
} from '@/components/person-picker';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { parseAmount } from '@/lib/format';
import { cn } from '@/lib/utils';
import haber from '@/routes/haberes/haber';
import { nuevaCuota } from '../types';
import type { EtiquetaOption, HaberDraft } from '../types';
import InstallmentTable from './installment-table';

type Props = {
    expedienteId: number;
    beneficiarios: PersonOption[];
    etiquetas: EtiquetaOption[];
    /** Adónde vuelve el botón de cancelar. */
    volverA: string;
};

const nuevoHaber = (): HaberDraft => ({
    key: crypto.randomUUID(),
    beneficiaryId: null,
    defaultBankAccountId: null,
    assignedAmount: '',
    expectedInstallmentCount: 1,
    concept: '',
    legalDate: '',
    resolutionReference: '',
    notes: '',
    installments: [nuevaCuota(1)],
});

/**
 * Alta de un haber dentro de un expediente ya cargado.
 *
 * El concepto vive en el haber y las cuotas lo heredan: el área confirmó
 * que normalmente todas comparten el mismo, y pedirlo cuota por cuota
 * sería hacer tipear lo mismo N veces. Cada cuota puede sobrescribirlo
 * cuando difiere, que es el caso Tinte.
 */
export default function HaberForm({
    expedienteId,
    beneficiarios,
    etiquetas,
    volverA,
}: Props) {
    const { errors } = usePage().props as unknown as {
        errors: Record<string, string>;
    };

    const [enviando, setEnviando] = useState(false);
    const [datos, setDatos] = useState<HaberDraft>(nuevoHaber());
    const [cuentaPreferida, setCuentaPreferida] =
        useState<PersonBankAccountOption | null>(null);

    const actualizar = (cambios: Partial<HaberDraft>) =>
        setDatos((d) => ({ ...d, ...cambios }));

    const enCuotas = datos.expectedInstallmentCount > 1;
    const minimoDeCuotas = Math.max(2, datos.installments.length);

    /*
     * Volver a «pago único» descarta las cuotas de más en vez de dejarlas
     * escondidas: si quedaran, el total seguiría contra una suma que ya no
     * se ve, y el formulario rechazaría el alta sin mostrar por qué.
     */
    const cambiarFormaDePago = (unico: boolean) => {
        if (unico) {
            actualizar({
                expectedInstallmentCount: 1,
                installments: datos.installments.slice(0, 1),
            });

            return;
        }

        actualizar({ expectedInstallmentCount: 2 });
    };

    const enviar = (e: React.FormEvent, otroDespues = false) => {
        e.preventDefault();
        setEnviando(true);

        // Los importes se normalizan recién acá: durante la carga viven
        // como los tipeó el operador, con coma y puntos de miles.
        router.post(
            haber.store(expedienteId).url,
            {
                ...datos,
                assignedAmount: parseAmount(datos.assignedAmount),
                installments: datos.installments.map((c) => ({
                    ...c,
                    amount: parseAmount(c.amount),
                })),
                // El servidor decide adónde vuelve: al expediente, o a un
                // formulario en blanco para el haber siguiente.
                andAnother: otroDespues,
            },
            {
                onSuccess: () => {
                    setDatos(nuevoHaber());
                    setCuentaPreferida(null);
                },
                onFinish: () => setEnviando(false),
            },
        );
    };

    return (
        <form
            onSubmit={enviar}
            aria-busy={enviando}
            className="rounded-lg border-2 border-primary/30 bg-card p-5 shadow-raised"
        >
            <h3 className="mb-4 text-sm font-semibold text-primary">
                Nuevo haber
            </h3>

            {/*
             * Doce columnas y cada campo se lleva las que su contenido
             * justifica. El importe va solo en su renglón: lleva el eco
             * en letras debajo, que necesita ancho para envolver y que
             * de otro modo estira el alto de todo lo que tenga al lado.
             */}
            <div className="grid gap-x-6 gap-y-5 sm:grid-cols-12">
                <div className="sm:col-span-12">
                    <Label htmlFor="beneficiaryId">Beneficiario</Label>
                    <div className="mt-1">
                        <PersonPicker
                            id="beneficiaryId"
                            value={datos.beneficiaryId}
                            onChange={(
                                beneficiaryId,
                                defaultBankAccount = null,
                            ) => {
                                actualizar({
                                    beneficiaryId,
                                    defaultBankAccountId:
                                        defaultBankAccount?.id ?? null,
                                });
                                setCuentaPreferida(defaultBankAccount);
                            }}
                            options={beneficiarios}
                            role="beneficiary"
                            invalid={Boolean(errors.beneficiaryId)}
                            placeholder="Buscá al trabajador…"
                        />
                    </div>
                    {errors.beneficiaryId && (
                        <p className="mt-1 text-xs text-destructive-strong">
                            {errors.beneficiaryId}
                        </p>
                    )}
                    {datos.defaultBankAccountId !== null && (
                        <p className="mt-1.5 flex items-center gap-1.5 text-xs text-muted-foreground">
                            <span
                                className="size-1.5 rounded-full bg-warning"
                                aria-hidden="true"
                            />
                            {cuentaPreferida?.verificationStatus === 'verified'
                                ? 'CBU verificado · cuenta preferida'
                                : 'CBU cargado · pendiente de verificación'}
                        </p>
                    )}
                    {errors.defaultBankAccountId && (
                        <p className="mt-1 text-xs text-destructive-strong">
                            {errors.defaultBankAccountId}
                        </p>
                    )}
                </div>

                <div className="sm:col-span-6 lg:col-span-4">
                    <Label htmlFor="assignedAmount">
                        Importe total reconocido
                    </Label>
                    <div className="mt-1">
                        <AmountInput
                            id="assignedAmount"
                            value={datos.assignedAmount}
                            onChange={(assignedAmount) =>
                                actualizar({ assignedAmount })
                            }
                            words="below"
                            aria-invalid={Boolean(errors.assignedAmount)}
                            placeholder="0,00"
                            required
                        />
                    </div>
                    {errors.assignedAmount && (
                        <p className="mt-1 text-xs text-destructive-strong">
                            {errors.assignedAmount}
                        </p>
                    )}
                </div>

                {/*
                 * «Pago único» no es un campo aparte: es la cantidad de
                 * cuotas en uno. El servidor deriva `payment_terms` de ese
                 * número, así que no hay dos datos que puedan
                 * contradecirse. Lo que aporta el selector es el
                 * vocabulario: el operador piensa «esto se paga de una
                 * vez», no «cantidad de cuotas = 1».
                 */}
                <div className="sm:col-span-6 lg:col-span-5">
                    <Label htmlFor="formaDePago">Forma de pago</Label>
                    <div
                        id="formaDePago"
                        role="radiogroup"
                        aria-label="Forma de pago"
                        className="mt-1 grid grid-cols-2 rounded-md bg-muted p-1"
                    >
                        {[
                            { unico: true, label: 'Pago único' },
                            { unico: false, label: 'En cuotas' },
                        ].map((opcion) => (
                            <button
                                key={opcion.label}
                                type="button"
                                role="radio"
                                aria-checked={enCuotas !== opcion.unico}
                                onClick={() => cambiarFormaDePago(opcion.unico)}
                                className={cn(
                                    'rounded-sm px-3 py-1.5 text-sm font-medium transition-[color,background-color,box-shadow] duration-150',
                                    enCuotas !== opcion.unico
                                        ? 'bg-card text-foreground shadow-xs'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {opcion.label}
                            </button>
                        ))}
                    </div>

                    {/*
                     * La leyenda está en los dos estados, no solo en uno: si
                     * apareciera al elegir, el renglón cambiaría de alto y
                     * empujaría todo lo de abajo.
                     */}
                    <p className="mt-1.5 text-xs text-muted-foreground">
                        {enCuotas
                            ? 'Los importes se cargan a medida que se conocen.'
                            : 'Se salda de una vez, en una única cuota.'}
                    </p>
                </div>

                {/*
                 * La cantidad va al lado y no debajo. Debajo, elegir «en
                 * cuotas» empujaba el resto del formulario hacia abajo; acá
                 * ocupa una columna que ya estaba, y el alto del renglón lo
                 * fija el selector, que es más alto.
                 */}
                <div className="sm:col-span-6 lg:col-span-3">
                    {enCuotas && (
                        <>
                            <Label htmlFor="expectedInstallmentCount">
                                Cantidad de cuotas
                            </Label>
                            <Input
                                id="expectedInstallmentCount"
                                type="number"
                                min={minimoDeCuotas}
                                max={60}
                                className="mt-1"
                                value={datos.expectedInstallmentCount}
                                aria-invalid={Boolean(
                                    errors.expectedInstallmentCount,
                                )}
                                onChange={(e) =>
                                    actualizar({
                                        expectedInstallmentCount: Math.min(
                                            60,
                                            Math.max(
                                                minimoDeCuotas,
                                                Number(e.target.value) || 2,
                                            ),
                                        ),
                                    })
                                }
                            />
                            {errors.expectedInstallmentCount && (
                                <p className="mt-1 text-xs text-destructive-strong">
                                    {errors.expectedInstallmentCount}
                                </p>
                            )}
                        </>
                    )}
                </div>

                <div className="sm:col-span-12">
                    <Label htmlFor="concept">
                        Concepto
                        <span className="ml-1 font-normal text-muted-foreground">
                            — lo heredan todas las cuotas
                        </span>
                    </Label>
                    <Input
                        id="concept"
                        className="mt-1"
                        value={datos.concept}
                        onChange={(e) =>
                            actualizar({ concept: e.target.value })
                        }
                        aria-invalid={Boolean(errors.concept)}
                        maxLength={255}
                        required
                        placeholder="Pago convenio homologado por Res. N° 3269/2025"
                    />
                    {errors.concept && (
                        <p className="mt-1 text-xs text-destructive-strong">
                            {errors.concept}
                        </p>
                    )}
                </div>
            </div>

            <CollapsibleSection label="Más datos del acuerdo" className="mt-4">
                <div className="grid gap-6 sm:grid-cols-2">
                    <div>
                        <Label htmlFor="legalDate">Fecha del acuerdo</Label>
                        <Input
                            id="legalDate"
                            type="date"
                            className="mt-1"
                            value={datos.legalDate}
                            onChange={(e) =>
                                actualizar({ legalDate: e.target.value })
                            }
                            aria-invalid={Boolean(errors.legalDate)}
                        />
                        {errors.legalDate && (
                            <p className="mt-1 text-xs text-destructive-strong">
                                {errors.legalDate}
                            </p>
                        )}
                    </div>
                    <div>
                        <Label htmlFor="resolutionReference">Resolución</Label>
                        <Input
                            id="resolutionReference"
                            className="mt-1"
                            value={datos.resolutionReference}
                            onChange={(e) =>
                                actualizar({
                                    resolutionReference: e.target.value,
                                })
                            }
                            maxLength={120}
                            aria-invalid={Boolean(errors.resolutionReference)}
                            placeholder="Res. N° 3269/2025"
                        />
                        {errors.resolutionReference && (
                            <p className="mt-1 text-xs text-destructive-strong">
                                {errors.resolutionReference}
                            </p>
                        )}
                    </div>
                    <div className="sm:col-span-2">
                        <Label htmlFor="haberNotes">
                            Observaciones generales
                        </Label>
                        <Textarea
                            id="haberNotes"
                            className="mt-1"
                            value={datos.notes}
                            onChange={(e) =>
                                actualizar({ notes: e.target.value })
                            }
                            maxLength={2000}
                            aria-invalid={Boolean(errors.notes)}
                            placeholder="Datos del acuerdo que no correspondan a una cuota específica"
                        />
                        {errors.notes && (
                            <p className="mt-1 text-xs text-destructive-strong">
                                {errors.notes}
                            </p>
                        )}
                    </div>
                </div>
            </CollapsibleSection>

            <div className="mt-5 border-t pt-4">
                <InstallmentTable
                    haber={datos}
                    etiquetas={etiquetas}
                    onChange={(installments) => actualizar({ installments })}
                    errors={errors}
                />
            </div>

            <div className="mt-4 flex flex-wrap justify-end gap-2 border-t pt-4">
                <Button
                    type="button"
                    variant="ghost"
                    asChild
                    disabled={enviando}
                >
                    <Link href={volverA}>Cancelar</Link>
                </Button>

                {/*
                 * Un acto puede reconocer varios haberes —el expediente de
                 * Bulacio tiene cinco— y volver al detalle entre uno y otro
                 * obliga a arrancar de nuevo cada vez.
                 */}
                <Button
                    type="button"
                    variant="outline"
                    disabled={enviando}
                    onClick={(e) => enviar(e, true)}
                >
                    {enviando && (
                        <LoaderCircle
                            className="animate-spin motion-reduce:animate-none"
                            aria-hidden="true"
                        />
                    )}
                    Guardar y agregar otro
                </Button>

                <Button type="submit" disabled={enviando}>
                    {enviando && (
                        <LoaderCircle
                            className="animate-spin motion-reduce:animate-none"
                            aria-hidden="true"
                        />
                    )}
                    {enviando ? 'Guardando…' : 'Guardar haber'}
                </Button>
            </div>
        </form>
    );
}
