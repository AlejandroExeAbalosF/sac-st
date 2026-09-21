import { Head, Link, useForm } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import AmountInput from '@/components/amount-input';
import PageHeader from '@/components/page-header';
import PersonPicker from '@/components/person-picker';
import type { PersonOption } from '@/components/person-picker';
import PersonSuggestInput from '@/components/person-suggest-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { money, parseAmount } from '@/lib/format';
import { show, update } from '@/routes/expedientes';

type Expediente = {
    id: number;
    displayNumber: string;
    canonicalNumber: string | null;
    subject: string | null;
    receivedDate: string | null;
    employerId: number | null;
    employerRepresentative: string | null;
    declaredTotalAmount: string | null;
    externalReference: string | null;
    notes: string | null;
};

type Props = {
    expediente: Expediente;
    empleadores: PersonOption[];
};

/**
 * Corrección de la ficha del expediente.
 *
 * Existe por el caso concreto que la destapó: un total declarado cargado
 * mal deja al expediente avisando para siempre que lo reconocido no
 * coincide, sin forma de resolverlo salvo tocando la base.
 *
 * El número no está entre los campos, y es deliberado. El número es la
 * identidad del expediente: por él lo busca el operador, sobre él hay un
 * índice único y a él apuntan los eventos de auditoría. Un número
 * equivocado no es una ficha con un error —es otro expediente—, y eso se
 * resuelve anulando y cargando el correcto.
 */
export default function EditarExpediente({ expediente, empleadores }: Props) {
    const { data, setData, patch, transform, processing, errors } = useForm({
        subject: expediente.subject ?? '',
        receivedDate: expediente.receivedDate ?? '',
        employerId: expediente.employerId,
        employerRepresentative: expediente.employerRepresentative ?? '',
        declaredTotalAmount: expediente.declaredTotalAmount
            ? money(expediente.declaredTotalAmount, { symbol: false })
            : '',
        externalReference: expediente.externalReference ?? '',
        notes: expediente.notes ?? '',
    });

    const enviar = (e: React.FormEvent) => {
        e.preventDefault();

        /*
         * El importe se normaliza recién acá: durante la carga vive como lo
         * tipeó el operador, con coma y puntos de miles. Es el mismo camino
         * que usa el alta de un haber.
         */
        transform((datos) => ({
            ...datos,
            declaredTotalAmount: parseAmount(datos.declaredTotalAmount),
        }));

        patch(update(expediente.id).url);
    };

    return (
        <>
            <Head title={`Editar expediente ${expediente.displayNumber}`} />

            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title={`Editar el expediente ${expediente.displayNumber}`}
                    eyebrow={
                        <span className="font-mono">
                            {expediente.canonicalNumber}
                        </span>
                    }
                    description="Corregí lo que el papel trajo mal o incompleto."
                />

                <form onSubmit={enviar}>
                    <div className="rounded-lg border bg-card shadow-raised">
                        <div className="grid gap-x-8 gap-y-6 p-5 sm:grid-cols-12">
                            {/*
                             * El número se muestra y no se edita. Está para
                             * confirmar que se está corrigiendo el
                             * expediente correcto, no para cambiarlo.
                             */}
                            <div className="sm:col-span-12 lg:col-span-5">
                                <Label>Número de expediente</Label>
                                <p className="mt-1 flex h-9 items-center gap-2 rounded-md border border-dashed bg-muted/40 px-3 font-mono text-sm">
                                    <Lock
                                        className="size-3.5 shrink-0 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    {expediente.canonicalNumber ??
                                        expediente.displayNumber}
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Identifica al expediente y no se corrige. Si
                                    el número está equivocado, anulá este y
                                    cargá el correcto.
                                </p>
                            </div>

                            <div className="sm:col-span-6 lg:col-span-3">
                                <Label htmlFor="receivedDate">Recibido</Label>
                                <Input
                                    id="receivedDate"
                                    type="date"
                                    className="mt-1"
                                    value={data.receivedDate}
                                    onChange={(e) =>
                                        setData('receivedDate', e.target.value)
                                    }
                                    aria-invalid={Boolean(errors.receivedDate)}
                                />
                                {errors.receivedDate && (
                                    <p className="mt-1 text-xs text-destructive-strong">
                                        {errors.receivedDate}
                                    </p>
                                )}
                            </div>

                            <div className="sm:col-span-6 lg:col-span-4">
                                <Label htmlFor="declaredTotalAmount">
                                    Total declarado
                                    <span className="ml-1 font-normal text-muted-foreground">
                                        (opcional)
                                    </span>
                                </Label>
                                <div className="mt-1">
                                    <AmountInput
                                        id="declaredTotalAmount"
                                        value={data.declaredTotalAmount}
                                        onChange={(valor) =>
                                            setData(
                                                'declaredTotalAmount',
                                                valor,
                                            )
                                        }
                                        words="below"
                                        placeholder="0,00"
                                        aria-invalid={Boolean(
                                            errors.declaredTotalAmount,
                                        )}
                                    />
                                </div>
                                {errors.declaredTotalAmount ? (
                                    <p className="text-xs text-destructive-strong">
                                        {errors.declaredTotalAmount}
                                    </p>
                                ) : (
                                    <p className="text-xs text-muted-foreground">
                                        Es el total que declara el acta. Dejalo
                                        vacío si el papel no lo trae: lo
                                        reconocido se calcula igual sumando los
                                        haberes, pero sin este número no hay
                                        contra qué compararlo.
                                    </p>
                                )}
                            </div>

                            <div className="sm:col-span-7 lg:col-span-5">
                                <Label htmlFor="employerId">Empleador</Label>
                                <div className="mt-1">
                                    <PersonPicker
                                        id="employerId"
                                        value={data.employerId}
                                        onChange={(employerId) =>
                                            setData('employerId', employerId)
                                        }
                                        options={empleadores}
                                        role="employer"
                                        invalid={Boolean(errors.employerId)}
                                        placeholder="Empleador u organismo…"
                                    />
                                </div>
                                {errors.employerId && (
                                    <p className="mt-1 text-xs text-destructive-strong">
                                        {errors.employerId}
                                    </p>
                                )}
                            </div>

                            <div className="sm:col-span-5 lg:col-span-4">
                                <Label htmlFor="employerRepresentative">
                                    Representante
                                    <span className="ml-1 font-normal text-muted-foreground">
                                        (opcional)
                                    </span>
                                </Label>
                                <PersonSuggestInput
                                    id="employerRepresentative"
                                    value={data.employerRepresentative}
                                    onChange={(employerRepresentative) =>
                                        setData(
                                            'employerRepresentative',
                                            employerRepresentative,
                                        )
                                    }
                                    preferido={
                                        empleadores.find(
                                            (e) => e.id === data.employerId,
                                        )?.ownerName
                                    }
                                    placeholder="Si el expediente trae también un nombre"
                                    invalid={Boolean(
                                        errors.employerRepresentative,
                                    )}
                                />
                            </div>

                            <div className="sm:col-span-12">
                                <Label htmlFor="subject">
                                    Carátula
                                    <span className="ml-1 font-normal text-muted-foreground">
                                        (opcional)
                                    </span>
                                </Label>
                                <Input
                                    id="subject"
                                    className="mt-1 max-w-4xl"
                                    value={data.subject}
                                    onChange={(e) =>
                                        setData('subject', e.target.value)
                                    }
                                    maxLength={255}
                                    aria-invalid={Boolean(errors.subject)}
                                />
                                {errors.subject && (
                                    <p className="mt-1 text-xs text-destructive-strong">
                                        {errors.subject}
                                    </p>
                                )}
                            </div>

                            <div className="sm:col-span-12">
                                <Label htmlFor="notes">
                                    Observaciones
                                    <span className="ml-1 font-normal text-muted-foreground">
                                        (opcional)
                                    </span>
                                </Label>
                                <Textarea
                                    id="notes"
                                    className="mt-1 max-w-4xl"
                                    rows={3}
                                    maxLength={2000}
                                    value={data.notes}
                                    onChange={(e) =>
                                        setData('notes', e.target.value)
                                    }
                                />
                            </div>
                        </div>

                        <div className="flex flex-wrap justify-end gap-2 border-t bg-muted/40 px-5 py-4">
                            <Button
                                variant="ghost"
                                asChild
                                disabled={processing}
                            >
                                <Link href={show(expediente.id)}>Cancelar</Link>
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {processing ? 'Guardando…' : 'Guardar cambios'}
                            </Button>
                        </div>
                    </div>
                </form>
            </div>
        </>
    );
}
