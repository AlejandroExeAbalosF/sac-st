import { Head, Link, router, useForm } from '@inertiajs/react';
import { Plus, Trash2, TriangleAlert } from 'lucide-react';
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
import { ACT_TYPE } from '@/features/multas/labels';
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
};

function FieldError({ message }: { message?: string }) {
    return message ? (
        <p className="mt-1 text-xs text-destructive-strong">{message}</p>
    ) : null;
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
export default function NuevaMulta({ existente, similares }: Props) {
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
        resolutionId: null,
        resolution: { text: '', issuedOn: '' },
        resolutionFile: null,
        actIds: [],
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
     * Al terminar de escribir el número se pregunta si el expediente existe,
     * con una recarga parcial: no hace falta una API aparte para una
     * pregunta que el controlador ya sabe contestar.
     */
    const consultarExpediente = () =>
        router.reload({
            only: ['existente', 'similares'],
            data: { expediente: data.caseNumber },
            onSuccess: () => {
                setData((actual) => ({
                    ...actual,
                    resolutionId: null,
                    actIds: [],
                }));
            },
        });

    const enviar = (e: React.FormEvent) => {
        e.preventDefault();

        transform((datos) => ({
            caseNumber: datos.caseNumber,
            caseNotes: datos.caseNotes === '' ? null : datos.caseNotes,
            ...(datos.resolutionId !== null
                ? { resolutionId: datos.resolutionId }
                : { resolution: datos.resolution }),
            actIds: datos.actIds,
            // Un archivo que no se eligió no viaja: sin él, la carga es la de siempre.
            acts: datos.acts.map(({ file, ...acta }) =>
                file === null ? acta : { ...acta, file },
            ),
            ...(datos.caseFile !== null && { caseFile: datos.caseFile }),
            ...(datos.resolutionFile !== null && {
                resolutionFile: datos.resolutionFile,
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
            <Head title="Nueva multa" />

            <div className="flex flex-col gap-6 p-4 sm:p-6">
                <PageHeader
                    title="Nueva multa"
                    eyebrow="Multas"
                    description="Cargá la multa tal como la trae la resolución."
                />

                <form onSubmit={enviar} className="grid gap-6">
                    <section className="rounded-lg border bg-card p-5 shadow-raised">
                        <h2 className="text-sm font-semibold">Expediente</h2>
                        <div className="mt-4 grid gap-x-8 gap-y-5 sm:grid-cols-12">
                            <div className="sm:col-span-5">
                                <Label htmlFor="caseNumber">
                                    Número de expediente
                                </Label>
                                <Input
                                    id="caseNumber"
                                    className="mt-1 font-mono"
                                    value={data.caseNumber}
                                    onChange={(e) =>
                                        setData('caseNumber', e.target.value)
                                    }
                                    onBlur={consultarExpediente}
                                    placeholder="169607/24-0"
                                    maxLength={60}
                                    autoFocus
                                    aria-invalid={Boolean(errors.caseNumber)}
                                />
                                <FieldError message={errors.caseNumber} />
                                {existente ? (
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Ya está cargado: la multa se le agrega.{' '}
                                        <Link
                                            href={showCase(existente.id)}
                                            className="underline"
                                        >
                                            Ver el expediente
                                        </Link>
                                    </p>
                                ) : (
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Como en el papel. Si no está cargado, se
                                        crea con la multa.
                                    </p>
                                )}
                            </div>

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
                        </div>
                    </section>

                    <section className="rounded-lg border bg-card p-5 shadow-raised">
                        <h2 className="text-sm font-semibold">
                            Resolución y actas
                        </h2>
                        <p className="mt-1 text-xs text-muted-foreground">
                            La resolución impone la multa: su fecha es la del
                            importe original.
                        </p>

                        <div className="mt-4 grid gap-5">
                            {resoluciones.length > 0 && (
                                <div className="grid gap-1.5">
                                    <Label htmlFor="resolutionId">
                                        Resolución del expediente
                                    </Label>
                                    <Select
                                        value={
                                            data.resolutionId === null
                                                ? 'nueva'
                                                : String(data.resolutionId)
                                        }
                                        onValueChange={(valor) =>
                                            setData(
                                                'resolutionId',
                                                valor === 'nueva'
                                                    ? null
                                                    : Number(valor),
                                            )
                                        }
                                    >
                                        <SelectTrigger
                                            id="resolutionId"
                                            className="max-w-md"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="nueva">
                                                Cargar una resolución nueva
                                            </SelectItem>
                                            {resoluciones.map((r) => (
                                                <SelectItem
                                                    key={r.id}
                                                    value={String(r.id)}
                                                >
                                                    {r.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <FieldError message={errors.resolutionId} />
                                </div>
                            )}

                            {data.resolutionId === null && (
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

                            <div className="lg:max-w-[58%]">
                                <PaperPhotoDropzone
                                    id="resolutionFile"
                                    label="Archivo de la resolución"
                                    foto={data.resolutionFile}
                                    onElegir={(archivo) =>
                                        setData('resolutionFile', archivo)
                                    }
                                    error={errores.resolutionFile}
                                />
                            </div>

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
                                                            marcada === true
                                                                ? [
                                                                      ...data.actIds,
                                                                      acta.id,
                                                                  ]
                                                                : data.actIds.filter(
                                                                      (id) =>
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
                                        <Label htmlFor={`acta-tipo-${i}`}>
                                            Tipo de acta
                                        </Label>
                                        <Select
                                            value={acta.actType}
                                            onValueChange={(valor) =>
                                                cambiarActa(i, {
                                                    actType: valor as ActType,
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
                                                    text: e.target.value,
                                                })
                                            }
                                            placeholder="N°1025288/25"
                                            maxLength={120}
                                            aria-invalid={Boolean(
                                                errores[`acts.${i}.text`],
                                            )}
                                        />
                                        <FieldError
                                            message={errores[`acts.${i}.text`]}
                                        />
                                    </div>
                                    <div className="sm:col-span-3">
                                        <Label htmlFor={`acta-fecha-${i}`}>
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
                                                    issuedOn: e.target.value,
                                                })
                                            }
                                        />
                                        <FieldError
                                            message={
                                                errores[`acts.${i}.issuedOn`]
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
                                                        (_, j) => j !== i,
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
                                            error={errores[`acts.${i}.file`]}
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
                                    Agregar un acta
                                </Button>
                            </div>
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
                                <Link href={index()}>Cancelar</Link>
                            </Button>
                            <Button type="submit" disabled={processing}>
                                {processing ? 'Guardando…' : 'Registrar multa'}
                            </Button>
                        </div>
                    </section>
                </form>
            </div>
        </>
    );
}
