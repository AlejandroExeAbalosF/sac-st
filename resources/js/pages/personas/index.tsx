import { Head, router, useForm } from '@inertiajs/react';
import { Building2, CircleUserRound, Pencil, Search, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import DocumentInput, {
    AYUDA_CUIT,
    AYUDA_DNI,
} from '@/components/document-input';
import HintedLabel from '@/components/hinted-label';
import PageHeader from '@/components/page-header';
import PaginationFooter from '@/components/pagination-footer';
import type { PaginationData } from '@/components/pagination-footer';
import PersonPicker from '@/components/person-picker';
import type { PersonOption } from '@/components/person-picker';
import StatusBadge from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cuit, dni } from '@/lib/format';
import { index, update } from '@/routes/personas';

type Persona = {
    id: number;
    /** «Apellido, Nombre» o la razón social. La arma la base. */
    name: string;
    firstName: string | null;
    lastName: string | null;
    legalName: string | null;
    /** El titular: solo lo llevan las organizaciones, y es opcional. */
    owner: PersonOption | null;
    document: string | null;
    /** El CUIL completo, cuando el papel lo trajo. Contiene al DNI. */
    taxIdentifier: string | null;
    type: string;
    isActive: boolean;
    roles: string[];
};

type Props = {
    personas: PaginationData & { data: Persona[] };
    filters: { q: string };
};

const ROL = { employer: 'Empleador', beneficiary: 'Beneficiario' } as const;

const documento = (persona: Persona): string => {
    if (persona.document === null) {
        return '—';
    }

    return persona.type === 'individual'
        ? dni(persona.document)
        : cuit(persona.document);
};

const roles = (persona: Persona): string =>
    persona.roles
        .map((rol) => ROL[rol as keyof typeof ROL] ?? rol)
        .join(' · ') || 'Sin rol operativo';

/**
 * Maestro de personas y organizaciones.
 *
 * Existe por una consecuencia concreta de que el documento del empleador
 * sea opcional: alguien cargado hoy con la razón social nada más tiene que
 * poder recibir su CUIT cuando llegue el expediente siguiente. Y como el
 * nombre de esta ficha es el que se imprime en los comprobantes, un error
 * de tipeo sin forma de corregirlo queda ahí para siempre.
 */
export default function PersonasIndex({ personas, filters }: Props) {
    const [termino, setTermino] = useState(filters.q ?? '');
    const [editando, setEditando] = useState<Persona | null>(null);

    const buscar = (e: React.FormEvent) => {
        e.preventDefault();
        router.get(
            index().url,
            { q: termino },
            { preserveState: true, replace: true },
        );
    };

    return (
        <>
            <Head title="Personas" />

            <div className="flex flex-col gap-6 p-6">
                <PageHeader
                    title="Personas y organizaciones"
                    eyebrow="Maestro · Núcleo transversal"
                    description="Empleadores y beneficiarios. El nombre de esta ficha es el que sale impreso en los comprobantes."
                />

                <form onSubmit={buscar} className="flex gap-2">
                    <div className="relative flex-1 sm:max-w-md">
                        <Search
                            className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <input
                            type="search"
                            value={termino}
                            onChange={(e) => setTermino(e.target.value)}
                            aria-label="Buscar por nombre, razón social, DNI o CUIT"
                            placeholder="Nombre, razón social, DNI o CUIT…"
                            className="h-9 w-full rounded-lg border bg-card pr-3 pl-9 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring"
                        />
                    </div>
                    <Button type="submit" variant="secondary">
                        Buscar
                    </Button>
                </form>

                <div className="hidden max-h-[calc(100svh-22rem)] overflow-auto rounded-lg border bg-card shadow-raised md:block">
                    <table className="w-full border-collapse text-left">
                        <caption className="sr-only">
                            Personas y organizaciones del maestro
                        </caption>
                        <thead className="sticky top-0 z-10">
                            <tr className="border-b bg-muted text-xs tracking-wide text-field-label uppercase shadow-[0_1px_0_0_var(--border)]">
                                <th
                                    scope="col"
                                    className="px-4 py-2.5 font-medium"
                                >
                                    Nombre
                                </th>
                                <th
                                    scope="col"
                                    className="py-2.5 pr-5 font-medium"
                                >
                                    Documento
                                </th>
                                <th
                                    scope="col"
                                    className="py-2.5 pr-5 font-medium"
                                >
                                    Interviene como
                                </th>
                                <th
                                    scope="col"
                                    className="py-2.5 pr-5 font-medium"
                                >
                                    Estado
                                </th>
                                <th
                                    scope="col"
                                    className="py-2.5 pr-4 text-right font-medium"
                                >
                                    Acciones
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {personas.data.map((persona) => (
                                <tr
                                    key={persona.id}
                                    className="border-b transition-colors focus-within:bg-accent/30 hover:bg-accent/30"
                                >
                                    <td className="flex items-center gap-3 px-4 py-3">
                                        <span className="grid size-8 shrink-0 place-items-center rounded-full bg-muted text-muted-foreground">
                                            {persona.type === 'company' ? (
                                                <Building2
                                                    className="size-4"
                                                    aria-hidden="true"
                                                />
                                            ) : (
                                                <CircleUserRound
                                                    className="size-4"
                                                    aria-hidden="true"
                                                />
                                            )}
                                        </span>
                                        <span className="text-sm">
                                            {persona.name}
                                        </span>
                                    </td>
                                    <td className="py-3 pr-5 font-mono text-sm tabular-nums">
                                        {persona.document === null ? (
                                            <span className="font-sans text-xs text-warning-strong">
                                                Sin documento
                                            </span>
                                        ) : (
                                            <>
                                                {documento(persona)}
                                                {persona.taxIdentifier && (
                                                    <span className="block text-xs text-muted-foreground">
                                                        CUIL{' '}
                                                        {cuit(
                                                            persona.taxIdentifier,
                                                        )}
                                                    </span>
                                                )}
                                            </>
                                        )}
                                    </td>
                                    <td className="py-3 pr-5 text-sm text-muted-foreground">
                                        {roles(persona)}
                                    </td>
                                    <td className="py-3 pr-5">
                                        <StatusBadge
                                            label={
                                                persona.isActive
                                                    ? 'Activa'
                                                    : 'Inactiva'
                                            }
                                            tone={
                                                persona.isActive
                                                    ? 'done'
                                                    : 'neutral'
                                            }
                                        />
                                    </td>
                                    <td className="py-3 pr-4">
                                        <div className="flex justify-end">
                                            <Tooltip>
                                                <TooltipTrigger asChild>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label={`Corregir la ficha de ${persona.name}`}
                                                        onClick={() =>
                                                            setEditando(persona)
                                                        }
                                                    >
                                                        <Pencil
                                                            className="size-4"
                                                            aria-hidden="true"
                                                        />
                                                    </Button>
                                                </TooltipTrigger>
                                                <TooltipContent>
                                                    Corregir la ficha
                                                </TooltipContent>
                                            </Tooltip>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>

                    {personas.data.length === 0 && (
                        <p className="px-5 py-12 text-center text-sm text-muted-foreground">
                            Ninguna ficha coincide con «{filters.q}».
                        </p>
                    )}
                </div>

                <div className="grid gap-3 md:hidden">
                    {personas.data.map((persona) => (
                        <PersonaCard
                            key={persona.id}
                            persona={persona}
                            onEdit={() => setEditando(persona)}
                        />
                    ))}

                    {personas.data.length === 0 && (
                        <p className="rounded-lg border border-dashed px-5 py-10 text-center text-sm text-muted-foreground">
                            Ninguna ficha coincide con «{filters.q}».
                        </p>
                    )}
                </div>

                <PaginationFooter
                    pagination={personas}
                    singular="ficha"
                    plural="fichas"
                />
            </div>

            <EditarFicha persona={editando} onClose={() => setEditando(null)} />
        </>
    );
}

function PersonaCard({
    persona,
    onEdit,
}: {
    persona: Persona;
    onEdit: () => void;
}) {
    return (
        <article className="grid gap-4 rounded-lg border bg-card p-4 shadow-xs">
            <div className="flex min-w-0 items-start gap-3">
                <span className="grid size-9 shrink-0 place-items-center rounded-full bg-muted text-muted-foreground">
                    {persona.type === 'company' ? (
                        <Building2 className="size-4" aria-hidden="true" />
                    ) : (
                        <CircleUserRound
                            className="size-4"
                            aria-hidden="true"
                        />
                    )}
                </span>
                <div className="min-w-0 flex-1">
                    <h2 className="text-sm font-semibold wrap-break-word">
                        {persona.name}
                    </h2>
                    <p className="mt-1 font-mono text-xs text-muted-foreground tabular-nums">
                        {persona.document === null
                            ? 'Sin documento'
                            : documento(persona)}
                    </p>
                    {persona.taxIdentifier && (
                        <p className="mt-0.5 font-mono text-xs text-muted-foreground tabular-nums">
                            CUIL {cuit(persona.taxIdentifier)}
                        </p>
                    )}
                </div>
                <StatusBadge
                    label={persona.isActive ? 'Activa' : 'Inactiva'}
                    tone={persona.isActive ? 'done' : 'neutral'}
                />
            </div>

            <div className="flex flex-wrap items-center justify-between gap-3 border-t pt-3">
                <p className="min-w-0 text-xs text-muted-foreground">
                    {roles(persona)}
                </p>
                <Button variant="outline" size="sm" onClick={onEdit}>
                    <Pencil aria-hidden="true" />
                    Corregir ficha
                </Button>
            </div>
        </article>
    );
}

/**
 * Corrección de una ficha.
 *
 * El tipo no se puede cambiar: una persona con roles queda atada al suyo
 * por la restricción de la base, y convertir una organización en persona
 * física no es una corrección sino decir que la ficha estaba mal desde el
 * principio.
 */
function EditarFicha({
    persona,
    onClose,
}: {
    persona: Persona | null;
    onClose: () => void;
}) {
    const { data, setData, patch, processing, errors, reset, clearErrors } =
        useForm({
            firstName: '',
            lastName: '',
            legalName: '',
            ownerPersonId: null as number | null,
            document: '',
            isActive: true,
        });

    useEffect(() => {
        if (persona) {
            clearErrors();
            setData({
                firstName: persona.firstName ?? '',
                lastName: persona.lastName ?? '',
                legalName: persona.legalName ?? '',
                ownerPersonId: persona.owner?.id ?? null,
                // El CUIL contiene al DNI, así que mostrar el número
                // entero conserva el prefijo al volver a guardar.
                document: persona.taxIdentifier ?? persona.document ?? '',
                isActive: persona.isActive,
            });
        }
        // `setData` y `clearErrors` cambian de identidad en cada render de
        // useForm; incluirlos volvería a poblar el formulario mientras se
        // escribe y pisaría lo tipeado.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [persona]);

    if (!persona) {
        return null;
    }

    const esEmpresa = persona.type === 'company';
    const esBeneficiaria = persona.roles.includes('beneficiary');

    const enviar = (e: React.FormEvent) => {
        e.preventDefault();
        patch(update(persona.id).url, {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        });
    };

    return (
        <Dialog open onOpenChange={(abierto) => !abierto && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Corregir la ficha</DialogTitle>
                    <DialogDescription>
                        {esEmpresa ? 'Organización' : 'Persona física'}. El tipo
                        no se puede cambiar.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={enviar} className="grid gap-5">
                    {esEmpresa ? (
                        <div>
                            <Label htmlFor="legalName">Razón social</Label>
                            <Input
                                id="legalName"
                                value={data.legalName}
                                onChange={(e) =>
                                    setData('legalName', e.target.value)
                                }
                                className="mt-1"
                                autoComplete="off"
                                maxLength={160}
                                aria-invalid={Boolean(errors.legalName)}
                            />
                            {errors.legalName && (
                                <p className="mt-1 text-xs text-destructive-strong">
                                    {errors.legalName}
                                </p>
                            )}
                        </div>
                    ) : (
                        <div className="grid gap-5 sm:grid-cols-2">
                            <div>
                                <Label htmlFor="lastName">Apellido</Label>
                                <Input
                                    id="lastName"
                                    value={data.lastName}
                                    onChange={(e) =>
                                        setData('lastName', e.target.value)
                                    }
                                    className="mt-1"
                                    autoComplete="off"
                                    maxLength={80}
                                    aria-invalid={Boolean(errors.lastName)}
                                />
                                {errors.lastName && (
                                    <p className="mt-1 text-xs text-destructive-strong">
                                        {errors.lastName}
                                    </p>
                                )}
                            </div>

                            <div>
                                <Label htmlFor="firstName">Nombre</Label>
                                <Input
                                    id="firstName"
                                    value={data.firstName}
                                    onChange={(e) =>
                                        setData('firstName', e.target.value)
                                    }
                                    className="mt-1"
                                    autoComplete="off"
                                    maxLength={80}
                                    aria-invalid={Boolean(errors.firstName)}
                                />
                                {errors.firstName && (
                                    <p className="mt-1 text-xs text-destructive-strong">
                                        {errors.firstName}
                                    </p>
                                )}
                            </div>
                        </div>
                    )}

                    <div>
                        <HintedLabel
                            htmlFor="document"
                            hint={esEmpresa ? AYUDA_CUIT : AYUDA_DNI}
                        >
                            {esEmpresa ? 'CUIT' : 'DNI o CUIL'}
                            {esEmpresa && !esBeneficiaria && (
                                <span className="ml-1 font-normal text-muted-foreground">
                                    (opcional)
                                </span>
                            )}
                        </HintedLabel>
                        <DocumentInput
                            id="document"
                            value={data.document}
                            onChange={(document) =>
                                setData('document', document)
                            }
                            className="mt-1"
                            aria-invalid={Boolean(errors.document)}
                        />
                        {errors.document ? (
                            <p className="mt-1 text-xs text-destructive-strong">
                                {errors.document}
                            </p>
                        ) : (
                            esBeneficiaria && (
                                <p className="mt-1 text-xs text-muted-foreground">
                                    Es beneficiaria de un haber: el documento es
                                    con lo que se le paga.
                                </p>
                            )
                        )}
                    </div>

                    {/*
                     * El titular es una ficha del maestro y no un nombre
                     * copiado: puede ser alguien que ya está cargado por
                     * otro motivo, y dos copias del mismo humano se
                     * desfasan en cuanto alguien corrige una.
                     */}
                    {esEmpresa && (
                        <fieldset className="grid gap-2 rounded-lg border p-3">
                            <legend className="px-1 text-xs font-medium text-field-label">
                                Titular del organismo
                                <span className="ml-1 font-normal text-muted-foreground">
                                    (opcional)
                                </span>
                            </legend>

                            <div className="flex items-center gap-2">
                                <PersonPicker
                                    id="ownerPersonId"
                                    value={data.ownerPersonId}
                                    onChange={(id) =>
                                        setData('ownerPersonId', id)
                                    }
                                    options={
                                        persona.owner ? [persona.owner] : []
                                    }
                                    role={null}
                                    placeholder="Buscar o registrar…"
                                    invalid={Boolean(errors.ownerPersonId)}
                                />
                                {data.ownerPersonId !== null && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label="Quitar el titular"
                                        onClick={() =>
                                            setData('ownerPersonId', null)
                                        }
                                    >
                                        <X
                                            className="size-4"
                                            aria-hidden="true"
                                        />
                                    </Button>
                                )}
                            </div>

                            {errors.ownerPersonId ? (
                                <p className="text-xs text-destructive-strong">
                                    {errors.ownerPersonId}
                                </p>
                            ) : (
                                <p className="text-xs text-muted-foreground">
                                    Quien está al frente del organismo. No
                                    interviene en el circuito: no aparece como
                                    empleador ni como beneficiario.
                                </p>
                            )}
                        </fieldset>
                    )}

                    <div className="flex items-start gap-3 rounded-lg border bg-muted/30 p-3">
                        <Checkbox
                            id="isActive"
                            checked={data.isActive}
                            onCheckedChange={(marcado) =>
                                setData('isActive', marcado === true)
                            }
                            className="mt-0.5"
                        />
                        <Label
                            htmlFor="isActive"
                            className="leading-snug font-normal"
                        >
                            Ficha activa
                            <span className="block text-xs text-muted-foreground">
                                Una ficha inactiva deja de aparecer en los
                                buscadores, pero no se borra: los expedientes
                                que la usan siguen apuntando a ella.
                            </span>
                        </Label>
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={onClose}
                            disabled={processing}
                        >
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Guardando…' : 'Guardar cambios'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
