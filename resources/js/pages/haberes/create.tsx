import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowRight, LoaderCircle, TriangleAlert } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import CollapsibleSection from '@/components/collapsible-section';
import Money from '@/components/money';
import PageHeader from '@/components/page-header';
import PersonPicker from '@/components/person-picker';
import type { PersonOption } from '@/components/person-picker';
import PersonSuggestInput from '@/components/person-suggest-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import ExpedienteNumberField, {
    descomponer,
} from '@/features/haberes/components/expediente-number-field';
import type { EstadoNumero } from '@/features/haberes/components/expediente-number-field';
import { businessToday, date, parseAmount } from '@/lib/format';
import { index, show, store } from '@/routes/expedientes';

type Props = {
    empleadores: PersonOption[];
    existente: App.Modules.Haberes.Data.ExpedienteListItemData | null;
};

const hoy = businessToday;

/** Lo que se espera a que el operador deje de escribir antes de consultar. */
const ESPERA_MS = 450;

/**
 * Alta del expediente, sin sus haberes.
 *
 * Es un formulario corto a propósito: se guarda enseguida y se sigue en el
 * detalle. El expediente vuelve con el tiempo trayendo el ticket de la
 * cuota siguiente, así que el sistema tiene que saber abrir uno ya cargado
 * y completarlo; el alta usa ese mismo camino en vez de uno propio.
 *
 * Sobre la disposición: la tarjeta ocupa el ancho disponible, pero los
 * campos NO. Cada uno se lleva las columnas que su contenido justifica —el
 * número de expediente son veintiún caracteres, la carátula es una frase
 * larga—. Un input estirado a mil pixeles para meter una fecha se lee
 * peor, no mejor.
 */
export default function CrearExpediente({ empleadores, existente }: Props) {
    const { errors } = usePage().props as unknown as {
        errors: Record<string, string>;
    };

    const [enviando, setEnviando] = useState(false);
    const [verificando, setVerificando] = useState(false);
    const [verificado, setVerificado] = useState<string | null>(null);
    const temporizador = useRef<ReturnType<typeof setTimeout> | null>(null);

    const [datos, setDatos] = useState({
        number: '',
        subject: '',
        receivedDate: hoy(),
        employerId: null as number | null,
        employerRepresentative: '',
        declaredTotalAmount: '',
        externalId: '',
        notes: '',
    });

    const actualizar = (cambios: Partial<typeof datos>) =>
        setDatos((d) => ({ ...d, ...cambios }));

    /*
     * Verificación automática, sin botón. Se dispara cuando el operador
     * deja de escribir y el número tiene forma válida: preguntarle al
     * servidor por cada tecla sería ruido, y esperar a que alguien apriete
     * un botón es un control que el día apurado no se hace.
     *
     * Va por recarga parcial de Inertia en lugar de una API aparte: el
     * controlador ya sabe contestar esta pregunta.
     */
    useEffect(() => {
        const numero = datos.number;

        if (temporizador.current) {
            clearTimeout(temporizador.current);
        }

        if (descomponer(numero) === null || numero === verificado) {
            return;
        }

        temporizador.current = setTimeout(() => {
            setVerificando(true);

            router.reload({
                only: ['existente'],
                data: { expediente: numero },
                replace: true,
                onSuccess: () => setVerificado(numero),
                onFinish: () => setVerificando(false),
            });
        }, ESPERA_MS);

        return () => {
            if (temporizador.current) {
                clearTimeout(temporizador.current);
            }
        };
    }, [datos.number, verificado]);

    const yaVerificado = verificado === datos.number;

    const estado: EstadoNumero = (() => {
        if (descomponer(datos.number) === null) {
            return 'vacio';
        }

        if (verificando) {
            return 'verificando';
        }

        if (!yaVerificado) {
            return 'vacio';
        }

        return existente ? 'existente' : 'disponible';
    })();

    const bloqueado = estado === 'existente';

    const enviar = (e: React.FormEvent) => {
        e.preventDefault();
        setEnviando(true);

        router.post(
            store().url,
            {
                ...datos,
                declaredTotalAmount: parseAmount(datos.declaredTotalAmount),
            },
            { onFinish: () => setEnviando(false) },
        );
    };

    return (
        <>
            <Head title="Nuevo expediente" />

            <form
                onSubmit={enviar}
                aria-busy={enviando}
                className="flex flex-col gap-6 p-4 sm:p-6"
            >
                <PageHeader
                    title="Nuevo expediente"
                    eyebrow="Operación · Caja Haberes"
                    description="Primero el expediente. Los haberes se agregan enseguida, en su detalle."
                />

                <section className="rounded-lg border bg-card shadow-raised">
                    <div className="grid gap-x-8 gap-y-6 p-5 md:grid-cols-12">
                        <div className="md:col-span-6 lg:col-span-4">
                            <ExpedienteNumberField
                                value={datos.number}
                                onChange={(number) => actualizar({ number })}
                                estado={estado}
                                error={errors.number}
                            />
                        </div>

                        {/*
                         * Si el expediente ya existe no se autocompleta el
                         * formulario: en ese caso no se está creando nada, y
                         * el lugar donde se continúa —agregar haberes,
                         * corregir la ficha— es el detalle. Autocompletar acá
                         * le daría dos significados a la misma pantalla y un
                         * paso de más para llegar al mismo lugar.
                         */}
                        {existente && bloqueado && (
                            <div className="rounded-lg border border-warning bg-warning-soft p-3 md:col-span-12">
                                <p className="flex items-center gap-2 text-sm font-medium text-warning-strong">
                                    <TriangleAlert
                                        className="size-4 shrink-0"
                                        aria-hidden="true"
                                    />
                                    Este expediente ya está cargado
                                </p>

                                <div className="mt-2 flex flex-wrap items-baseline gap-x-4 gap-y-1 text-sm">
                                    <span className="font-mono font-medium tabular-nums">
                                        {existente.displayNumber}
                                    </span>
                                    <span>
                                        {existente.subject ?? 'Sin carátula'}
                                    </span>
                                </div>

                                <p className="mt-1 text-xs text-muted-foreground">
                                    {existente.employerName} · recibido{' '}
                                    {date(existente.receivedDate)} ·{' '}
                                    {existente.haberes.length === 1
                                        ? '1 haber'
                                        : `${existente.haberes.length} haberes`}{' '}
                                    ·{' '}
                                    <Money
                                        value={existente.recognizedTotalAmount}
                                    />
                                </p>

                                <Button asChild className="mt-3" size="sm">
                                    <Link href={show(existente.id)}>
                                        Abrir el expediente
                                        <ArrowRight
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                    </Link>
                                </Button>
                            </div>
                        )}

                        <div className="md:col-span-6 lg:col-span-3">
                            <Label htmlFor="receivedDate">
                                Fecha de recepción
                            </Label>
                            <Input
                                id="receivedDate"
                                type="date"
                                className="mt-1"
                                value={datos.receivedDate}
                                onChange={(e) =>
                                    actualizar({ receivedDate: e.target.value })
                                }
                                aria-invalid={Boolean(errors.receivedDate)}
                            />
                            {errors.receivedDate && (
                                <p className="mt-1 text-xs text-destructive-strong">
                                    {errors.receivedDate}
                                </p>
                            )}
                        </div>

                        <div className="md:col-span-7 lg:col-span-5">
                            <Label htmlFor="employerId">Empleador</Label>
                            <div className="mt-1">
                                <PersonPicker
                                    id="employerId"
                                    value={datos.employerId}
                                    onChange={(employerId) =>
                                        actualizar({ employerId })
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

                        {/*
                         * El expediente a veces trae la empresa y también el
                         * nombre de una persona. Ese nombre es lo que dice
                         * ese papel —el titular, el apoderado, quien firmó—,
                         * no otra parte del circuito, así que se anota acá y
                         * no se da de alta en el maestro.
                         */}
                        <div className="md:col-span-5 lg:col-span-4">
                            <Label htmlFor="employerRepresentative">
                                Representante
                                <span className="ml-1 font-normal text-muted-foreground">
                                    (opcional)
                                </span>
                            </Label>
                            <PersonSuggestInput
                                id="employerRepresentative"
                                value={datos.employerRepresentative}
                                onChange={(employerRepresentative) =>
                                    actualizar({ employerRepresentative })
                                }
                                preferido={
                                    empleadores.find(
                                        (e) => e.id === datos.employerId,
                                    )?.ownerName
                                }
                                placeholder="Si el expediente trae también un nombre"
                                invalid={Boolean(errors.employerRepresentative)}
                            />
                            {errors.employerRepresentative && (
                                <p className="mt-1 text-xs text-destructive-strong">
                                    {errors.employerRepresentative}
                                </p>
                            )}
                        </div>

                        {/* La carátula sí justifica todo el ancho: es una
                            frase larga y se lee de corrido. */}
                        <div className="md:col-span-12">
                            <Label htmlFor="subject">
                                Carátula
                                <span className="ml-1 font-normal text-muted-foreground">
                                    (opcional)
                                </span>
                            </Label>
                            {/* Tope al campo, no a la tarjeta: en una
                                pantalla de 1920 un input de 1600 px deja de
                                leerse como una línea de texto. */}
                            <Input
                                id="subject"
                                className="mt-1 max-w-4xl"
                                value={datos.subject}
                                onChange={(e) =>
                                    actualizar({ subject: e.target.value })
                                }
                                aria-invalid={Boolean(errors.subject)}
                                placeholder="Acta acuerdo — García Claudio Adrián c/ CIACSA"
                            />
                            {!errors.subject && (
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Ayuda a reconocer el expediente. Si todavía
                                    no la tenés, se puede cargar después.
                                </p>
                            )}
                            {errors.subject && (
                                <p className="mt-1 text-xs text-destructive-strong">
                                    {errors.subject}
                                </p>
                            )}
                        </div>

                        <CollapsibleSection
                            label="Más datos del expediente"
                            className="md:col-span-12"
                        >
                            <div className="grid gap-x-8 gap-y-6 md:grid-cols-12">
                                <div className="md:col-span-4 lg:col-span-3">
                                    <Label htmlFor="declaredTotalAmount">
                                        Total declarado
                                        <span className="ml-1 font-normal text-muted-foreground">
                                            (opcional)
                                        </span>
                                    </Label>
                                    <Input
                                        id="declaredTotalAmount"
                                        className="mt-1 text-right font-mono tabular-nums"
                                        value={datos.declaredTotalAmount}
                                        onChange={(e) =>
                                            actualizar({
                                                declaredTotalAmount:
                                                    e.target.value,
                                            })
                                        }
                                        placeholder="0,00"
                                        inputMode="decimal"
                                        aria-invalid={Boolean(
                                            errors.declaredTotalAmount,
                                        )}
                                    />
                                    {errors.declaredTotalAmount ? (
                                        <p className="mt-1 text-xs text-destructive-strong">
                                            {errors.declaredTotalAmount}
                                        </p>
                                    ) : (
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            Es el total que declara el acta.
                                            Dejalo vacío si el papel no lo trae:
                                            lo reconocido se calcula igual
                                            sumando los haberes, pero sin este
                                            número no hay contra qué compararlo.
                                        </p>
                                    )}
                                </div>
                                <div className="md:col-span-8 lg:col-span-5">
                                    <Label htmlFor="externalId">
                                        Código de SiCE
                                    </Label>
                                    <Input
                                        id="externalId"
                                        className="mt-1 font-mono text-xs"
                                        value={datos.externalId}
                                        onChange={(e) =>
                                            actualizar({
                                                externalId: e.target.value,
                                            })
                                        }
                                        maxLength={120}
                                        aria-invalid={Boolean(
                                            errors.externalId,
                                        )}
                                        placeholder="El «Cod.» del pie de la carátula"
                                    />
                                    {errors.externalId && (
                                        <p className="mt-1 text-xs text-destructive-strong">
                                            {errors.externalId}
                                        </p>
                                    )}
                                </div>
                                <div className="md:col-span-12">
                                    <Label htmlFor="notes">Observaciones</Label>
                                    <Textarea
                                        id="notes"
                                        className="mt-1 max-w-4xl"
                                        value={datos.notes}
                                        onChange={(e) =>
                                            actualizar({
                                                notes: e.target.value,
                                            })
                                        }
                                        maxLength={2000}
                                        aria-invalid={Boolean(errors.notes)}
                                    />
                                    {errors.notes && (
                                        <p className="mt-1 text-xs text-destructive-strong">
                                            {errors.notes}
                                        </p>
                                    )}
                                </div>
                            </div>
                        </CollapsibleSection>
                    </div>

                    {/*
                     * Las acciones cierran la tarjeta, no encabezan la
                     * pantalla: el formulario se completa de arriba hacia
                     * abajo y el botón tiene que estar donde termina la
                     * lectura, no obligando a volver al principio.
                     */}
                    <div className="flex items-center justify-end gap-2 border-t bg-muted/40 px-5 py-4">
                        <Button variant="outline" asChild>
                            <Link href={index()}>Cancelar</Link>
                        </Button>
                        <Button type="submit" disabled={enviando || bloqueado}>
                            {enviando && (
                                <LoaderCircle
                                    className="animate-spin motion-reduce:animate-none"
                                    aria-hidden="true"
                                />
                            )}
                            {enviando ? 'Guardando…' : 'Guardar y continuar'}
                        </Button>
                    </div>
                </section>
            </form>
        </>
    );
}
