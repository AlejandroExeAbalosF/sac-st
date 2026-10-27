import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowRight,
    FolderOpen,
    Plus,
    Trash2,
    TriangleAlert,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import AmountInput from '@/components/amount-input';
import DateInput from '@/components/date-input';
import PageHeader from '@/components/page-header';
import PaperPhotoDropzone from '@/components/paper-photo-dropzone';
import PersonPicker from '@/components/person-picker';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { ACT_TYPE, partialDate } from '@/features/multas/labels';
import { businessToday, parseAmount } from '@/lib/format';
import { create, index, store } from '@/routes/multas';
import { show as showCase } from '@/routes/multas/expedientes';

type Expediente = App.Modules.Multas.Data.FineCaseData;
type ActType = App.Modules.Multas.Enums.FineActType;

type NuevaActa = {
    actType: ActType;
    text: string;
    issuedOn: string;
    file: File | null;
};

type Props = {
    /** El expediente con ese número exacto, si ya está cargado. */
    existente: Expediente | null;
    /** Otros con el mismo número y año y distinto sufijo (P03). */
    similares: { id: number; label: string }[];
    /**
     * Se entró desde la ficha del expediente: el expediente no se escribe
     * ni se cambia, y el alta lo identifica por id.
     */
    fijo: boolean;
    /**
     * Los documentos fijos del «+» de la ficha: una resolución, la resolución
     * y las actas de un grupo, o un documento sin multa. Con `fixed`, la
     * multa se carga con exactamente esos: las actas no se cambian ni se
     * agregan, y la resolución tampoco si vino una.
     */
    preseleccion: {
        fixed: boolean;
        resolutionId: number | null;
        actIds: number[];
    };
};

function FieldError({ message }: { message?: string }) {
    return message ? (
        <p className="mt-1 text-xs text-destructive-strong">{message}</p>
    ) : null;
}

/**
 * Los documentos que vienen fijos desde un «+» de la ficha: se muestran como
 * el número del expediente, sin nada que elegir.
 */
function DocumentosFijos({
    titulo,
    documentos,
    vacio,
}: {
    titulo: string;
    documentos: App.Modules.Multas.Data.FineDocumentData[];
    vacio?: string;
}) {
    return (
        <div className="grid gap-1.5">
            <p className="text-sm font-medium">{titulo}</p>
            {documentos.length === 0 ? (
                <p className="text-sm text-muted-foreground">{vacio}</p>
            ) : (
                <ul className="grid gap-1.5">
                    {documentos.map((documento) => (
                        <li
                            key={documento.id}
                            className="max-w-md rounded-md border bg-muted/50 px-3 py-2 text-sm text-muted-foreground"
                        >
                            <span className="font-medium text-foreground">
                                {documento.label}
                            </span>{' '}
                            ·{' '}
                            {partialDate(
                                documento.issuedOn,
                                documento.issuedPrecision,
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/**
 * Alta de una multa con todo lo que trae el papel.
 *
 * Toda multa se origina en una resolución y sus actas, de un expediente. Si
 * el expediente ya está cargado, la multa se le agrega y se ofrecen sus
 * documentos: así un acta compartida por dos multas no se carga dos veces.
 *
 * Cada papel puede traer su foto o su PDF, en su propio bloque y opcional:
 * el del expediente va al expediente; el de la resolución y el de cada acta
 * nueva, a ese documento. Las actas ya cargadas reciben el suyo desde la
 * ficha del expediente.
 */
export default function NuevaMulta({
    existente,
    similares,
    fijo,
    preseleccion,
}: Props) {
    const { data, setData, post, transform, processing, errors } = useForm<{
        caseNumber: string;
        caseNotes: string;
        caseFile: File | null;
        resolutionId: number | null;
        resolution: { text: string; issuedOn: string };
        resolutionFile: File | null;
        actIds: number[];
        acts: NuevaActa[];
        caption: string;
        personId: number | null;
        registeredOn: string;
        amount: string;
    }>({
        caseNumber: existente?.originalText ?? '',
        caseNotes: '',
        caseFile: null,
        resolutionId: preseleccion.resolutionId,
        resolution: { text: '', issuedOn: '' },
        resolutionFile: null,
        actIds: preseleccion.actIds,
        acts: [],
        caption: '',
        personId: null,
        registeredOn: businessToday(),
        amount: '',
    });

    const errores = errors as Record<string, string | undefined>;

    const resoluciones =
        existente?.documents.filter((d) => d.kind === 'resolution') ?? [];
    const actas = existente?.documents.filter((d) => d.kind === 'act') ?? [];

    /*
     * Primero se elige: si el expediente tiene resoluciones, ninguna viene
     * elegida de antemano —nadie atribuye la multa a una resolución por
     * descuido— y escribir una nueva es la opción del final. Sin
     * resoluciones cargadas no hay nada que elegir y se escribe directo.
     */
    const [cargarNueva, setCargarNueva] = useState(false);
    const resolucionNueva = resoluciones.length === 0 || cargarNueva;

    // Desde un «+»: lo fijo se muestra, no se elige. Con un acta sola, la resolución se elige igual.
    const actasFijas = preseleccion.fixed;
    const resolucionFija =
        preseleccion.fixed && preseleccion.resolutionId !== null
            ? resoluciones.find((r) => r.id === preseleccion.resolutionId)
            : undefined;

    /*
     * Mientras se escribe el número se pregunta si el expediente existe, con
     * una recarga parcial: no hace falta una API aparte para una pregunta que
     * el controlador ya sabe contestar. Se pregunta al dejar de tipear y al
     * salir del campo, una sola vez por número.
     */
    const consultado = useRef(data.caseNumber);

    const consultarExpediente = () => {
        const numero = data.caseNumber.trim();

        if (fijo || numero === consultado.current) {
            return;
        }

        consultado.current = numero;

        router.reload({
            only: ['existente', 'similares'],
            data: { expediente: numero },
            onSuccess: () => {
                setCargarNueva(false);
                setData((actual) => ({
                    ...actual,
                    resolutionId: null,
                    actIds: [],
                }));
            },
        });
    };

    useEffect(() => {
        const espera = window.setTimeout(consultarExpediente, 500);

        return () => window.clearTimeout(espera);
        // Solo el número dispara la consulta; la función cambia en cada render.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data.caseNumber]);

    const enviar = (e: React.FormEvent) => {
        e.preventDefault();

        transform((datos) => ({
            ...(fijo && existente
                ? { fineCaseId: existente.id }
                : {
                      caseNumber: datos.caseNumber,
                      caseNotes:
                          datos.caseNotes === '' ? null : datos.caseNotes,
                  }),
            // Sin elegir ni escribir no viaja nada: el servidor pide elegir.
            ...(datos.resolutionId !== null
                ? { resolutionId: datos.resolutionId }
                : resolucionNueva && { resolution: datos.resolution }),
            actIds: datos.actIds,
            // Un archivo que no se eligió no viaja: sin él, la carga es la de siempre.
            acts: datos.acts.map(({ file, ...acta }) =>
                file === null ? acta : { ...acta, file },
            ),
            ...(datos.caseFile !== null && { caseFile: datos.caseFile }),
            ...(datos.resolutionFile !== null && {
                resolutionFile: datos.resolutionFile,
            }),
            // Lo que la pantalla mostró fijo: el servidor rechaza un alta que no lo respete.
            ...(preseleccion.fixed && {
                fixedDocuments: {
                    resolutionId: preseleccion.resolutionId,
                    actIds: preseleccion.actIds,
                },
            }),
            caption: datos.caption,
            personId: datos.personId,
            registeredOn: datos.registeredOn,
            amount: parseAmount(datos.amount),
        }));

        post(store().url);
    };

    const cambiarActa = (indice: number, cambios: Partial<NuevaActa>) =>
        setData(
            'acts',
            data.acts.map((acta, i) =>
                i === indice ? { ...acta, ...cambios } : acta,
            ),
        );

    return (
        <>
            <Head title={fijo ? 'Agregar multa' : 'Nueva multa'} />

            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title={fijo ? 'Agregar multa' : 'Nueva multa'}
                    eyebrow="Multas"
                    description={
                        fijo
                            ? 'Agregá al expediente la multa tal como la trae la resolución.'
                            : 'Cargá la multa tal como la trae la resolución.'
                    }
                />

                <form onSubmit={enviar} className="grid gap-6">
                    <section className="rounded-lg border bg-card p-5 shadow-raised">
                        <h2 className="text-sm font-semibold">Expediente</h2>
                        <div className="mt-4 grid gap-x-8 gap-y-5 sm:grid-cols-12">
                            <div className="sm:col-span-5">
                                <Label htmlFor="caseNumber">
                                    Número de expediente
                                </Label>
                                {fijo && existente ? (
                                    /*
                                     * Desde la ficha el expediente ya está
                                     * elegido: se muestra, no se escribe.
                                     */
                                    <Input
                                        id="caseNumber"
                                        className="mt-1 bg-muted/50 font-mono text-muted-foreground"
                                        value={existente.label}
                                        readOnly
                                        aria-readonly="true"
                                    />
                                ) : (
                                    <Input
                                        id="caseNumber"
                                        className="mt-1 font-mono"
                                        value={data.caseNumber}
                                        onChange={(e) =>
                                            setData(
                                                'caseNumber',
                                                e.target.value,
                                            )
                                        }
                                        onBlur={consultarExpediente}
                                        placeholder="169607/24-0"
                                        maxLength={60}
                                        autoFocus
                                        aria-invalid={Boolean(
                                            errors.caseNumber,
                                        )}
                                    />
                                )}
                                <FieldError
                                    message={
                                        errors.caseNumber ?? errores.fineCaseId
                                    }
                                />
                                {!existente && (
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Como en el papel. Si no está cargado, se
                                        crea con la multa.
                                    </p>
                                )}
                            </div>

                            {existente && !fijo && (
                                /*
                                 * Un expediente ya cargado no es un error: la
                                 * multa se le agrega. Pero conviene mirarlo
                                 * antes —qué multas tiene, qué documentos—, y
                                 * el aviso entero lleva a su ficha.
                                 */
                                <Link
                                    href={showCase(existente.routeKey)}
                                    className="group flex gap-2 self-start rounded-md border border-info-strong/30 bg-info-soft p-3 text-xs text-info-strong hover:border-info-strong/60 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:col-span-7"
                                >
                                    <FolderOpen
                                        className="size-4 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <span className="grid flex-1 gap-0.5">
                                        <span className="font-semibold">
                                            El expediente{' '}
                                            <span className="font-mono">
                                                {existente.label}
                                            </span>{' '}
                                            ya está cargado
                                        </span>
                                        <span>
                                            La multa se le va a agregar, y abajo
                                            podés usar su resolución y sus
                                            actas. Hacé clic para ver el
                                            expediente.
                                        </span>
                                    </span>
                                    <ArrowRight
                                        className="size-4 shrink-0 self-center transition-transform group-hover:translate-x-0.5"
                                        aria-hidden="true"
                                    />
                                </Link>
                            )}

                            {similares.length > 0 && (
                                <div className="flex gap-2 rounded-md border border-warning-strong/30 bg-warning-soft p-3 text-xs text-warning-strong sm:col-span-7">
                                    <TriangleAlert
                                        className="size-4 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <p>
                                        Hay otro expediente con el mismo número
                                        y año y distinto sufijo:{' '}
                                        {similares.map((otro, i) => (
                                            <span key={otro.id}>
                                                {i > 0 && ', '}
                                                <Link
                                                    href={create({
                                                        query: {
                                                            expediente:
                                                                otro.label,
                                                        },
                                                    })}
                                                    className="font-mono underline"
                                                >
                                                    {otro.label}
                                                </Link>
                                            </span>
                                        ))}
                                        . No se fusionan solos: revisá cuál
                                        corresponde.
                                    </p>
                                </div>
                            )}

                            {!existente && (
                                <div className="sm:col-span-12">
                                    <Label htmlFor="caseNotes">
                                        Observaciones del expediente
                                        <span className="ml-1 font-normal text-muted-foreground">
                                            (opcional)
                                        </span>
                                    </Label>
                                    <Textarea
                                        id="caseNotes"
                                        className="mt-1 max-w-3xl"
                                        rows={2}
                                        maxLength={500}
                                        value={data.caseNotes}
                                        onChange={(e) =>
                                            setData('caseNotes', e.target.value)
                                        }
                                    />
                                </div>
                            )}

                            {/* Desde la ficha, el archivo del expediente se adjunta en la ficha. */}
                            {!fijo && (
                                <div className="sm:col-span-12 lg:col-span-7">
                                    <PaperPhotoDropzone
                                        id="caseFile"
                                        label="Archivo del expediente"
                                        foto={data.caseFile}
                                        onElegir={(archivo) =>
                                            setData('caseFile', archivo)
                                        }
                                        error={errores.caseFile}
                                    />
                                </div>
                            )}
                        </div>
                    </section>

                    <section className="rounded-lg border bg-card p-5 shadow-raised">
                        <h2 className="text-sm font-semibold">
                            Resolución y actas
                        </h2>
                        <p className="mt-1 text-xs text-muted-foreground">
                            {actasFijas
                                ? 'Vienen fijos desde la ficha del expediente. Para otra combinación, usá «Agregar multa».'
                                : 'La resolución impone la multa: su fecha es la del importe original.'}
                        </p>
                        <FieldError message={errores.fixedDocuments} />

                        <div className="mt-4 grid gap-5">
                            {resolucionFija ? (
                                <DocumentosFijos
                                    titulo="Resolución"
                                    documentos={[resolucionFija]}
                                />
                            ) : (
                                resoluciones.length > 0 && (
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="resolutionId">
                                            Resolución
                                        </Label>
                                        <Select
                                            value={
                                                data.resolutionId !== null
                                                    ? String(data.resolutionId)
                                                    : cargarNueva
                                                      ? 'nueva'
                                                      : ''
                                            }
                                            onValueChange={(valor) => {
                                                setCargarNueva(
                                                    valor === 'nueva',
                                                );
                                                setData(
                                                    'resolutionId',
                                                    valor === 'nueva'
                                                        ? null
                                                        : Number(valor),
                                                );
                                            }}
                                        >
                                            <SelectTrigger
                                                id="resolutionId"
                                                className="max-w-md"
                                                aria-invalid={Boolean(
                                                    errors.resolutionId ??
                                                    (!resolucionNueva &&
                                                        errores[
                                                            'resolution.text'
                                                        ]),
                                                )}
                                            >
                                                <SelectValue placeholder="Elegí la resolución" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {resoluciones.map((r) => (
                                                    <SelectItem
                                                        key={r.id}
                                                        value={String(r.id)}
                                                    >
                                                        {r.label} ·{' '}
                                                        {partialDate(
                                                            r.issuedOn,
                                                            r.issuedPrecision,
                                                        )}
                                                    </SelectItem>
                                                ))}
                                                <SelectItem value="nueva">
                                                    Otra resolución, que no está
                                                    cargada
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                        <FieldError
                                            message={
                                                errors.resolutionId ??
                                                (resolucionNueva
                                                    ? undefined
                                                    : errores[
                                                          'resolution.text'
                                                      ])
                                            }
                                        />
                                    </div>
                                )
                            )}

                            {data.resolutionId === null && resolucionNueva && (
                                <div className="grid gap-x-8 gap-y-5 sm:grid-cols-12">
                                    <div className="sm:col-span-5">
                                        <Label htmlFor="resolutionText">
                                            Resolución
                                        </Label>
                                        <Input
                                            id="resolutionText"
                                            className="mt-1 font-mono"
                                            value={data.resolution.text}
                                            onChange={(e) =>
                                                setData('resolution', {
                                                    ...data.resolution,
                                                    text: e.target.value,
                                                })
                                            }
                                            placeholder="N°0649/26"
                                            maxLength={120}
                                            aria-invalid={Boolean(
                                                errores['resolution.text'],
                                            )}
                                        />
                                        <FieldError
                                            message={errores['resolution.text']}
                                        />
                                    </div>
                                    <div className="sm:col-span-3">
                                        <Label htmlFor="resolutionDate">
                                            Fecha de la resolución
                                        </Label>
                                        <DateInput
                                            id="resolutionDate"
                                            className="mt-1"
                                            value={data.resolution.issuedOn}
                                            onChange={(e) =>
                                                setData('resolution', {
                                                    ...data.resolution,
                                                    issuedOn: e.target.value,
                                                })
                                            }
                                            aria-invalid={Boolean(
                                                errores['resolution.issuedOn'],
                                            )}
                                        />
                                        <FieldError
                                            message={
                                                errores['resolution.issuedOn']
                                            }
                                        />
                                    </div>
                                </div>
                            )}

                            {!resolucionFija &&
                                (data.resolutionId !== null ||
                                    resolucionNueva) && (
                                    <div className="lg:max-w-[58%]">
                                        <PaperPhotoDropzone
                                            id="resolutionFile"
                                            label="Archivo de la resolución"
                                            foto={data.resolutionFile}
                                            onElegir={(archivo) =>
                                                setData(
                                                    'resolutionFile',
                                                    archivo,
                                                )
                                            }
                                            error={errores.resolutionFile}
                                        />
                                    </div>
                                )}

                            {actasFijas ? (
                                <DocumentosFijos
                                    titulo="Actas"
                                    documentos={actas.filter((acta) =>
                                        preseleccion.actIds.includes(acta.id),
                                    )}
                                    vacio="Sin actas: la multa se carga solo con la resolución."
                                />
                            ) : (
                                <>
                                    {actas.length > 0 && (
                                        <fieldset>
                                            <legend className="text-sm font-medium">
                                                Actas del expediente
                                            </legend>
                                            <div className="mt-2 grid gap-2">
                                                {actas.map((acta) => (
                                                    <label
                                                        key={acta.id}
                                                        className="flex items-center gap-2 text-sm"
                                                    >
                                                        <Checkbox
                                                            checked={data.actIds.includes(
                                                                acta.id,
                                                            )}
                                                            onCheckedChange={(
                                                                marcada,
                                                            ) =>
                                                                setData(
                                                                    'actIds',
                                                                    marcada ===
                                                                        true
                                                                        ? [
                                                                              ...data.actIds,
                                                                              acta.id,
                                                                          ]
                                                                        : data.actIds.filter(
                                                                              (
                                                                                  id,
                                                                              ) =>
                                                                                  id !==
                                                                                  acta.id,
                                                                          ),
                                                                )
                                                            }
                                                        />
                                                        {acta.label}
                                                    </label>
                                                ))}
                                            </div>
                                        </fieldset>
                                    )}

                                    {data.acts.map((acta, i) => (
                                        <div
                                            key={i}
                                            className="grid items-start gap-x-6 gap-y-3 rounded-md border border-dashed p-3 sm:grid-cols-12"
                                        >
                                            <div className="sm:col-span-4">
                                                <Label
                                                    htmlFor={`acta-tipo-${i}`}
                                                >
                                                    Tipo de acta
                                                </Label>
                                                <Select
                                                    value={acta.actType}
                                                    onValueChange={(valor) =>
                                                        cambiarActa(i, {
                                                            actType:
                                                                valor as ActType,
                                                        })
                                                    }
                                                >
                                                    <SelectTrigger
                                                        id={`acta-tipo-${i}`}
                                                        className="mt-1"
                                                    >
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {(
                                                            Object.keys(
                                                                ACT_TYPE,
                                                            ) as ActType[]
                                                        ).map((tipo) => (
                                                            <SelectItem
                                                                key={tipo}
                                                                value={tipo}
                                                            >
                                                                {tipo} ·{' '}
                                                                {ACT_TYPE[tipo]}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                            <div className="sm:col-span-4">
                                                <Label htmlFor={`acta-${i}`}>
                                                    Acta
                                                </Label>
                                                <Input
                                                    id={`acta-${i}`}
                                                    className="mt-1 font-mono"
                                                    value={acta.text}
                                                    onChange={(e) =>
                                                        cambiarActa(i, {
                                                            text: e.target
                                                                .value,
                                                        })
                                                    }
                                                    placeholder="N°1025288/25"
                                                    maxLength={120}
                                                    aria-invalid={Boolean(
                                                        errores[
                                                            `acts.${i}.text`
                                                        ],
                                                    )}
                                                />
                                                <FieldError
                                                    message={
                                                        errores[
                                                            `acts.${i}.text`
                                                        ]
                                                    }
                                                />
                                            </div>
                                            <div className="sm:col-span-3">
                                                <Label
                                                    htmlFor={`acta-fecha-${i}`}
                                                >
                                                    Fecha
                                                    <span className="ml-1 font-normal text-muted-foreground">
                                                        (opcional)
                                                    </span>
                                                </Label>
                                                <DateInput
                                                    id={`acta-fecha-${i}`}
                                                    className="mt-1"
                                                    value={acta.issuedOn}
                                                    onChange={(e) =>
                                                        cambiarActa(i, {
                                                            issuedOn:
                                                                e.target.value,
                                                        })
                                                    }
                                                />
                                                <FieldError
                                                    message={
                                                        errores[
                                                            `acts.${i}.issuedOn`
                                                        ]
                                                    }
                                                />
                                            </div>
                                            <div className="flex sm:col-span-1 sm:justify-end sm:pt-6">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`Quitar el acta ${i + 1}`}
                                                    onClick={() =>
                                                        setData(
                                                            'acts',
                                                            data.acts.filter(
                                                                (_, j) =>
                                                                    j !== i,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    <Trash2 aria-hidden="true" />
                                                </Button>
                                            </div>
                                            <div className="sm:col-span-11">
                                                <PaperPhotoDropzone
                                                    id={`acta-archivo-${i}`}
                                                    label="Archivo del acta"
                                                    foto={acta.file}
                                                    onElegir={(archivo) =>
                                                        cambiarActa(i, {
                                                            file: archivo,
                                                        })
                                                    }
                                                    error={
                                                        errores[
                                                            `acts.${i}.file`
                                                        ]
                                                    }
                                                />
                                            </div>
                                        </div>
                                    ))}

                                    <div>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                setData('acts', [
                                                    ...data.acts,
                                                    {
                                                        actType: 'HYS',
                                                        text: '',
                                                        issuedOn: '',
                                                        file: null,
                                                    },
                                                ])
                                            }
                                        >
                                            <Plus aria-hidden="true" />
                                            {actas.length > 0
                                                ? 'Agregar un acta que no está cargada'
                                                : 'Agregar un acta'}
                                        </Button>
                                    </div>
                                </>
                            )}
                        </div>
                    </section>

                    <section className="rounded-lg border bg-card shadow-raised">
                        <div className="p-5">
                            <h2 className="text-sm font-semibold">Multa</h2>
                            <div className="mt-4 grid gap-x-8 gap-y-5 sm:grid-cols-12">
                                <div className="sm:col-span-12">
                                    <Label htmlFor="caption">Carátula</Label>
                                    <Input
                                        id="caption"
                                        className="mt-1 max-w-3xl"
                                        value={data.caption}
                                        onChange={(e) =>
                                            setData('caption', e.target.value)
                                        }
                                        maxLength={300}
                                        placeholder="Como figura en la resolución"
                                        aria-invalid={Boolean(errors.caption)}
                                    />
                                    <FieldError message={errors.caption} />
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Es el nombre con que se emiten los
                                        recibos. No hace falta que la persona
                                        esté en el maestro.
                                    </p>
                                </div>

                                <div className="sm:col-span-6 lg:col-span-5">
                                    <Label htmlFor="personId">
                                        Persona
                                        <span className="ml-1 font-normal text-muted-foreground">
                                            (opcional)
                                        </span>
                                    </Label>
                                    <div className="mt-1">
                                        <PersonPicker
                                            id="personId"
                                            value={data.personId}
                                            onChange={(id) =>
                                                setData('personId', id)
                                            }
                                            options={[]}
                                            role={null}
                                            invalid={Boolean(errors.personId)}
                                            placeholder="Buscar en Personas…"
                                        />
                                    </div>
                                    <FieldError message={errors.personId} />
                                </div>

                                <div className="sm:col-span-3">
                                    <Label htmlFor="registeredOn">
                                        Fecha de registro
                                    </Label>
                                    <DateInput
                                        id="registeredOn"
                                        className="mt-1"
                                        value={data.registeredOn}
                                        onChange={(e) =>
                                            setData(
                                                'registeredOn',
                                                e.target.value,
                                            )
                                        }
                                        aria-invalid={Boolean(
                                            errors.registeredOn,
                                        )}
                                    />
                                    <FieldError message={errors.registeredOn} />
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Su mes es el período de origen.
                                    </p>
                                </div>

                                <div className="sm:col-span-3 lg:col-span-4">
                                    <Label htmlFor="amount">Importe</Label>
                                    <div className="mt-1">
                                        <AmountInput
                                            id="amount"
                                            value={data.amount}
                                            onChange={(valor) =>
                                                setData('amount', valor)
                                            }
                                            words="below"
                                            placeholder="0,00"
                                            aria-invalid={Boolean(
                                                errors.amount,
                                            )}
                                        />
                                    </div>
                                    <FieldError message={errors.amount} />
                                </div>
                            </div>
                        </div>

                        <div className="flex flex-wrap justify-end gap-2 border-t bg-muted/40 px-5 py-4">
                            <Button
                                variant="ghost"
                                asChild
                                disabled={processing}
                            >
                                <Link
                                    href={
                                        fijo && existente
                                            ? showCase(existente.routeKey)
                                            : index()
                                    }
                                >
                                    Cancelar
                                </Link>
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {processing
                                    ? 'Guardando…'
                                    : fijo
                                      ? 'Agregar multa'
                                      : 'Registrar multa'}
                            </Button>
                        </div>
                    </section>
                </form>
            </div>
        </>
    );
}
