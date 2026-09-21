import { ArrowLeft, ChevronDown, LoaderCircle } from 'lucide-react';
import { useEffect, useId, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import DocumentInput, {
    AYUDA_CUIT,
    AYUDA_DNI,
} from '@/components/document-input';
import HintedLabel from '@/components/hinted-label';
import PersonPickerBankFields from '@/components/person-picker-bank-fields';
import PersonPickerOwnerFields from '@/components/person-picker-owner-fields';
import PersonPickerSearchPanel from '@/components/person-picker-search-panel';
import {
    esDocumentoBuscable,
    personDocumentLabel,
} from '@/components/person-picker-shared';
import type {
    OwnerLookup,
    PersonBankAccountOption,
    PersonDraft,
    PersonOption,
    PersonRole,
    PersonType,
} from '@/components/person-picker-shared';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { ApiError, apiGet, apiPost, isAbort } from '@/lib/api';
import { cn } from '@/lib/utils';

export type {
    PersonBankAccountOption,
    PersonOption,
    PersonRole,
    PersonType,
} from '@/components/person-picker-shared';

type Props = {
    id?: string;
    value: number | null;
    onChange: (
        id: number | null,
        defaultBankAccount?: PersonBankAccountOption | null,
    ) => void;
    options: PersonOption[];
    /**
     * `null` cuando lo que se elige no interviene en el circuito —el
     * titular de una organización—, y por lo tanto no recibe ningún rol.
     */
    role: PersonRole | null;
    allowedTypes?: PersonType[];
    placeholder?: string;
    invalid?: boolean;
};

type SearchResponse = {
    data: PersonOption[];
    meta: { hasMore: boolean };
};

/** Lo que contesta `/people/resolve` mientras se tipea un documento. */
type ResolveResponse = {
    data: PersonOption | null;
    documentNumber: string;
};

/**
 * En qué punto está la búsqueda del titular.
 *
 * Es el mismo tratamiento que el campo del número de expediente: la
 * consulta corre sola al terminar de escribir y contesta en una línea viva
 * debajo. Un paso que hay que acordarse de dar es un paso que el día
 * apurado no se da, y ahí entra la segunda ficha del mismo humano.
 */
type StoreResponse = {
    data: PersonOption;
    bankAccount: PersonBankAccountOption | null;
    reused: boolean;
    message: string;
};

const SEARCH_DELAY_MS = 250;
const BENEFICIARY_TYPES: PersonType[] = ['individual'];
const EMPLOYER_TYPES: PersonType[] = ['company', 'individual'];
/** Sin rol se elige a un humano: hoy el único caso es el titular. */
const ROLELESS_TYPES: PersonType[] = ['individual'];

const mergePeople = (
    current: PersonOption[],
    incoming: PersonOption[],
): PersonOption[] => {
    const people = new Map(current.map((person) => [person.id, person]));

    incoming.forEach((person) => people.set(person.id, person));

    return [...people.values()];
};

/**
 * Búsqueda y alta contextual sobre el maestro único de personas.
 *
 * La selección y el alta viven en un popover para no sacar al operador del
 * expediente. Las consultas se cancelan al seguir escribiendo y el alta
 * devuelve la persona creada —o la ficha ya existente— para seleccionarla
 * sin perder ningún dato del formulario padre.
 */
export default function PersonPicker({
    id,
    value,
    onChange,
    options,
    role,
    allowedTypes,
    placeholder = 'Seleccioná…',
    invalid = false,
}: Props) {
    const listId = useId();
    const createAbortController = useRef<AbortController | null>(null);

    /*
     * `allowedTypes` puede llegar como literal en el JSX, y entonces es un
     * array nuevo en cada render. Si esa referencia entrara directo en las
     * dependencias del efecto de búsqueda, cada respuesta dispararía la
     * consulta siguiente y el buscador quedaría consultando para siempre.
     * La clave de texto la ancla sin obligar a quien lo use a acordarse de
     * memoizar del lado de afuera.
     */
    const typesKey = (
        allowedTypes ??
        (role === 'employer'
            ? EMPLOYER_TYPES
            : role === 'beneficiary'
              ? BENEFICIARY_TYPES
              : ROLELESS_TYPES)
    ).join(',');
    const types = useMemo(
        () => typesKey.split(',') as PersonType[],
        [typesKey],
    );

    const [open, setOpen] = useState(false);
    const [mode, setMode] = useState<'search' | 'create'>('search');
    const [query, setQuery] = useState('');
    const [knownPeople, setKnownPeople] = useState<PersonOption[]>(options);
    const [results, setResults] = useState<PersonOption[]>(options);
    const [loading, setLoading] = useState(false);
    const [searchError, setSearchError] = useState<string | null>(null);
    const [hasMore, setHasMore] = useState(false);
    const [retry, setRetry] = useState(0);
    const [submitting, setSubmitting] = useState(false);
    const [bankDetailsOpen, setBankDetailsOpen] = useState(false);
    const [formError, setFormError] = useState<string | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    /*
     * Los tres campos de nombre conviven en el borrador, pero solo el par
     * que corresponde al tipo llega con dato: el servidor rechaza un
     * apellido en una organización y una razón social en una persona.
     */
    const [draft, setDraft] = useState<PersonDraft>({
        type: types[0],
        firstName: '',
        lastName: '',
        legalName: '',
        document: '',
        ownerDocument: '',
        ownerFirstName: '',
        ownerLastName: '',
        cbu: '',
    });
    const [ownerOpen, setOwnerOpen] = useState(false);
    const [owner, setOwner] = useState<OwnerLookup>({ estado: 'vacio' });
    /*
     * Con qué arrancó el formulario de alta. El alta se abre con algo ya
     * puesto —lo que se venía buscando—, así que «hay datos» no alcanza
     * para saber si el operador escribió: lo que importa es si cambió algo
     * respecto de esto.
     */
    const [draftAlEmpezar, setDraftAlEmpezar] = useState<
        Record<string, string>
    >({});

    useEffect(() => {
        if (!open || mode !== 'search') {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(
            async () => {
                setLoading(true);
                setSearchError(null);

                const params = new URLSearchParams({ q: query });

                if (role !== null) {
                    params.set('role', role);
                }

                if (types.length === 1) {
                    params.set('type', types[0]);
                }

                try {
                    const payload = await apiGet<SearchResponse>(
                        `/people?${params.toString()}`,
                        controller.signal,
                    );

                    setResults(payload.data);
                    setKnownPeople((people) =>
                        mergePeople(people, payload.data),
                    );
                    setHasMore(payload.meta.hasMore);
                } catch (error) {
                    if (isAbort(error)) {
                        return;
                    }

                    setSearchError(
                        error instanceof ApiError
                            ? error.message
                            : 'No pudimos buscar personas. Revisá la conexión e intentá nuevamente.',
                    );
                } finally {
                    if (!controller.signal.aborted) {
                        setLoading(false);
                    }
                }
            },
            query === '' ? 0 : SEARCH_DELAY_MS,
        );

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [mode, open, query, retry, role, types]);

    useEffect(
        () => () => {
            createAbortController.current?.abort();
        },
        [],
    );

    /*
     * El titular se resuelve por su documento, no por su nombre: quien
     * carga tiene el papel delante y tipea el número. Se consulta cuando lo
     * escrito puede ser un DNI o un CUIL; los largos intermedios no son
     * ninguno de los dos y no vale la pena preguntar.
     */
    useEffect(() => {
        const digitos = draft.ownerDocument;

        if (
            mode !== 'create' ||
            draft.type !== 'company' ||
            !esDocumentoBuscable(digitos)
        ) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            setOwner({ estado: 'buscando' });

            try {
                const payload = await apiGet<ResolveResponse>(
                    `/people/resolve?document=${digitos}`,
                    controller.signal,
                );

                setOwner(
                    payload.data
                        ? { estado: 'encontrado', person: payload.data }
                        : { estado: 'nuevo' },
                );
            } catch (error) {
                if (isAbort(error)) {
                    return;
                }

                setOwner({
                    estado: 'error',
                    message:
                        error instanceof ApiError
                            ? error.message
                            : 'No pudimos buscar a esa persona.',
                });
            }
        }, SEARCH_DELAY_MS);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [draft.ownerDocument, draft.type, mode]);

    /*
     * Un dedo que erró el objetivo no puede costar lo cargado. Mientras el
     * formulario tenga algo escrito, el clic afuera no cierra: se sale por
     * «Cancelar», por la flecha de volver o con Escape, que son gestos
     * deliberados. Con el formulario intacto cierra como cualquier popover,
     * porque no hay nada que proteger.
     */
    const hayCambiosSinGuardar =
        mode === 'create' &&
        Object.entries(draft).some(
            ([campo, valor]) => draftAlEmpezar[campo] !== valor,
        );

    const selected =
        knownPeople.find((person) => person.id === value) ??
        options.find((person) => person.id === value) ??
        null;
    const showSkeleton = loading && (query !== '' || results.length === 0);

    const changeOpen = (nextOpen: boolean) => {
        setOpen(nextOpen);

        /*
         * La búsqueda se limpia al cerrar, no al abrir. Reponer la lista
         * mientras el término anterior seguía en el campo dejaba en pantalla
         * resultados que no correspondían a lo escrito hasta que volvía la
         * consulta.
         */
        if (!nextOpen) {
            setQuery('');
            setResults(options);

            return;
        }

        /*
         * El paso y lo que se venía cargando se reponen al **abrir**. El
         * panel sigue montado los milisegundos que dura el desvanecido, así
         * que volverlo a la búsqueda al cerrar hacía que el formulario de
         * alta se transformara en el listado justo antes de desaparecer.
         */
        setMode('search');
        setFieldErrors({});
        setFormError(null);
        setBankDetailsOpen(false);
        setOwnerOpen(false);
        setOwner({ estado: 'vacio' });
    };

    const selectPerson = (
        person: PersonOption,
        defaultBankAccount: PersonBankAccountOption | null = null,
    ) => {
        setKnownPeople((people) => mergePeople(people, [person]));
        onChange(person.id, defaultBankAccount);
        // Por `changeOpen` y no `setOpen`: el alta que termina bien también
        // se cierra desvaneciéndose, y tiene que hacerlo mostrando el
        // formulario, no el listado.
        changeOpen(false);
    };

    const startCreate = () => {
        const onlyNumbers = query.replace(/\D+/g, '');
        // Lo tipeado se aprovecha como apellido o razón social —es por
        // donde se empieza a buscar—, nunca como nombre de pila.
        const typed = /[a-záéíóúñ]/i.test(query) ? query.trim() : '';

        const inicial = {
            type: types[0],
            firstName: '',
            lastName: types[0] === 'individual' ? typed : '',
            legalName: types[0] === 'company' ? typed : '',
            document: onlyNumbers,
            ownerDocument: '',
            ownerFirstName: '',
            ownerLastName: '',
            cbu: '',
        };

        setDraftAlEmpezar(inicial);
        setDraft(inicial);
        setOwner({ estado: 'vacio' });
        setOwnerOpen(false);
        setFieldErrors({});
        setFormError(null);
        setBankDetailsOpen(false);
        setMode('create');
    };

    const cancelCreate = () => {
        setMode('search');
        setFieldErrors({});
        setFormError(null);
        setBankDetailsOpen(false);
        setOwnerOpen(false);
        setOwner({ estado: 'vacio' });
    };

    const createPerson = async (event: React.FormEvent) => {
        event.preventDefault();
        event.stopPropagation();

        if (submitting) {
            return;
        }

        setSubmitting(true);
        setFieldErrors({});
        setFormError(null);
        createAbortController.current?.abort();
        const controller = new AbortController();
        createAbortController.current = controller;

        try {
            const payload = await apiPost<StoreResponse>(
                '/people',
                { ...draft, role },
                controller.signal,
            );

            selectPerson(payload.data, payload.bankAccount);
            toast.success(payload.message, {
                description: payload.bankAccount
                    ? payload.bankAccount.verificationStatus === 'verified'
                        ? 'Usamos el CBU verificado que ya tenía esta persona.'
                        : 'El CBU quedó pendiente de verificación.'
                    : payload.reused
                      ? 'No se creó un duplicado.'
                      : personDocumentLabel(payload.data),
            });
        } catch (error) {
            if (isAbort(error)) {
                return;
            }

            if (error instanceof ApiError && error.hasFieldErrors) {
                setFieldErrors(error.fieldErrors);

                if (error.fieldErrors.cbu) {
                    setBankDetailsOpen(true);
                }

                return;
            }

            setFormError(
                error instanceof Error
                    ? error.message
                    : 'No pudimos registrar la persona.',
            );
        } finally {
            if (!controller.signal.aborted) {
                setSubmitting(false);
            }
        }
    };

    return (
        <Popover open={open} onOpenChange={changeOpen}>
            <PopoverTrigger asChild>
                <Button
                    id={id}
                    type="button"
                    variant="outline"
                    role="combobox"
                    aria-expanded={open}
                    aria-controls={listId}
                    aria-invalid={invalid}
                    className={cn(
                        'w-full justify-between bg-card px-3 font-normal transition-[border-color,box-shadow,background-color] duration-150',
                        'hover:bg-card focus-visible:ring-3',
                        !selected && 'text-muted-foreground',
                    )}
                >
                    <span className="flex min-w-0 items-baseline gap-2 text-left">
                        <span className="truncate">
                            {selected?.name ?? placeholder}
                        </span>
                        {selected && (
                            <span className="hidden shrink-0 text-xs text-muted-foreground sm:inline">
                                {personDocumentLabel(selected)}
                            </span>
                        )}
                    </span>
                    <ChevronDown
                        className={cn(
                            'size-4 shrink-0 opacity-50 transition-transform duration-200 ease-out',
                            open && 'rotate-180',
                        )}
                        aria-hidden="true"
                    />
                </Button>
            </PopoverTrigger>

            <PopoverContent
                align="start"
                overlay
                onInteractOutside={(event) => {
                    if (hayCambiosSinGuardar) {
                        event.preventDefault();
                    }
                }}
                /*
                 * El alto lo pone la pantalla, no el contenido: Radix publica
                 * cuánto espacio quedó y el panel se recorta ahí. Sin esto,
                 * en un teléfono apaisado el formulario de alta se salía por
                 * abajo y los botones quedaban fuera de alcance.
                 */
                className="flex max-h-[min(34rem,var(--radix-popover-content-available-height))] w-[min(28rem,calc(100vw-2rem))] flex-col overflow-hidden p-0"
            >
                {mode === 'search' ? (
                    <PersonPickerSearchPanel
                        listId={listId}
                        query={query}
                        setQuery={setQuery}
                        results={results}
                        selectedId={value}
                        loading={loading}
                        showSkeleton={showSkeleton}
                        error={searchError}
                        hasMore={hasMore}
                        retry={setRetry}
                        selectPerson={selectPerson}
                        startCreate={startCreate}
                    />
                ) : (
                    <form
                        onSubmit={createPerson}
                        className="flex min-h-0 flex-1 animate-in flex-col duration-200 fade-in-0 slide-in-from-right-2 motion-reduce:animate-none"
                    >
                        <div className="flex shrink-0 items-start gap-3 border-b px-4 py-3.5">
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="-ml-2 size-8 shrink-0"
                                onClick={cancelCreate}
                                aria-label="Volver a la búsqueda"
                            >
                                <ArrowLeft aria-hidden="true" />
                            </Button>
                            <div className="min-w-0">
                                <p className="text-sm font-semibold">
                                    Registrar nueva persona
                                </p>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    Quedará seleccionada al guardar.
                                </p>
                            </div>
                        </div>

                        <div className="grid min-h-0 flex-1 auto-rows-min gap-4 overflow-y-auto p-4">
                            {types.length > 1 && (
                                <fieldset>
                                    <legend className="mb-1.5 text-xs font-medium">
                                        Tipo
                                    </legend>
                                    <div className="grid grid-cols-2 rounded-md bg-muted p-1">
                                        {types.map((type) => (
                                            <button
                                                key={type}
                                                type="button"
                                                onClick={() =>
                                                    setDraft((current) => ({
                                                        ...current,
                                                        type,
                                                        // Los campos del
                                                        // tipo anterior ya
                                                        // no aplican.
                                                        firstName: '',
                                                        lastName: '',
                                                        legalName: '',
                                                        document: '',
                                                        ownerDocument: '',
                                                        ownerFirstName: '',
                                                        ownerLastName: '',
                                                    }))
                                                }
                                                className={cn(
                                                    'rounded-sm px-3 py-1.5 text-xs font-medium transition-[color,background-color,box-shadow] duration-150',
                                                    draft.type === type
                                                        ? 'bg-card text-foreground shadow-xs'
                                                        : 'text-muted-foreground hover:text-foreground',
                                                )}
                                                aria-pressed={
                                                    draft.type === type
                                                }
                                            >
                                                {type === 'company'
                                                    ? 'Organización'
                                                    : 'Persona'}
                                            </button>
                                        ))}
                                    </div>
                                </fieldset>
                            )}

                            <div>
                                <HintedLabel
                                    htmlFor={`${listId}-document`}
                                    hint={
                                        draft.type === 'company'
                                            ? AYUDA_CUIT
                                            : AYUDA_DNI
                                    }
                                >
                                    {draft.type === 'company'
                                        ? 'CUIT'
                                        : 'DNI o CUIL'}
                                    {draft.type === 'company' &&
                                        role === 'employer' && (
                                            <span className="ml-1 font-normal text-muted-foreground">
                                                (opcional)
                                            </span>
                                        )}
                                </HintedLabel>
                                <DocumentInput
                                    id={`${listId}-document`}
                                    value={draft.document}
                                    onChange={(document) =>
                                        setDraft((current) => ({
                                            ...current,
                                            document,
                                        }))
                                    }
                                    className="mt-1"
                                    aria-invalid={Boolean(fieldErrors.document)}
                                    aria-describedby={
                                        fieldErrors.document
                                            ? `${listId}-document-error`
                                            : undefined
                                    }
                                    placeholder={
                                        draft.type === 'company'
                                            ? '30-71044289-2'
                                            : '28114902'
                                    }
                                />
                                {fieldErrors.document && (
                                    <p
                                        id={`${listId}-document-error`}
                                        className="mt-1 text-xs text-destructive-strong"
                                    >
                                        {fieldErrors.document}
                                    </p>
                                )}
                            </div>

                            {draft.type === 'company' ? (
                                <div>
                                    <Label htmlFor={`${listId}-legal-name`}>
                                        Razón social
                                    </Label>
                                    <Input
                                        id={`${listId}-legal-name`}
                                        value={draft.legalName}
                                        onChange={(event) =>
                                            setDraft((current) => ({
                                                ...current,
                                                legalName: event.target.value,
                                            }))
                                        }
                                        className="mt-1"
                                        autoComplete="off"
                                        maxLength={160}
                                        aria-invalid={Boolean(
                                            fieldErrors.legalName,
                                        )}
                                        aria-describedby={
                                            fieldErrors.legalName
                                                ? `${listId}-legal-name-error`
                                                : undefined
                                        }
                                        placeholder="Razón social completa"
                                    />
                                    {fieldErrors.legalName && (
                                        <p
                                            id={`${listId}-legal-name-error`}
                                            className="mt-1 text-xs text-destructive-strong"
                                        >
                                            {fieldErrors.legalName}
                                        </p>
                                    )}
                                </div>
                            ) : (
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <Label htmlFor={`${listId}-last-name`}>
                                            Apellido
                                        </Label>
                                        <Input
                                            id={`${listId}-last-name`}
                                            value={draft.lastName}
                                            onChange={(event) =>
                                                setDraft((current) => ({
                                                    ...current,
                                                    lastName:
                                                        event.target.value,
                                                }))
                                            }
                                            className="mt-1"
                                            autoComplete="off"
                                            maxLength={80}
                                            aria-invalid={Boolean(
                                                fieldErrors.lastName,
                                            )}
                                            aria-describedby={
                                                fieldErrors.lastName
                                                    ? `${listId}-last-name-error`
                                                    : undefined
                                            }
                                            placeholder="Pérez"
                                        />
                                        {fieldErrors.lastName && (
                                            <p
                                                id={`${listId}-last-name-error`}
                                                className="mt-1 text-xs text-destructive-strong"
                                            >
                                                {fieldErrors.lastName}
                                            </p>
                                        )}
                                    </div>

                                    <div>
                                        <Label htmlFor={`${listId}-first-name`}>
                                            Nombre
                                        </Label>
                                        <Input
                                            id={`${listId}-first-name`}
                                            value={draft.firstName}
                                            onChange={(event) =>
                                                setDraft((current) => ({
                                                    ...current,
                                                    firstName:
                                                        event.target.value,
                                                }))
                                            }
                                            className="mt-1"
                                            autoComplete="off"
                                            maxLength={80}
                                            aria-invalid={Boolean(
                                                fieldErrors.firstName,
                                            )}
                                            aria-describedby={
                                                fieldErrors.firstName
                                                    ? `${listId}-first-name-error`
                                                    : undefined
                                            }
                                            placeholder="María Elena"
                                        />
                                        {fieldErrors.firstName && (
                                            <p
                                                id={`${listId}-first-name-error`}
                                                className="mt-1 text-xs text-destructive-strong"
                                            >
                                                {fieldErrors.firstName}
                                            </p>
                                        )}
                                    </div>
                                </div>
                            )}

                            {draft.type === 'company' && (
                                <PersonPickerOwnerFields
                                    listId={listId}
                                    open={ownerOpen}
                                    setOpen={setOwnerOpen}
                                    draft={draft}
                                    setDraft={setDraft}
                                    owner={owner}
                                    setOwner={setOwner}
                                    errors={fieldErrors}
                                />
                            )}

                            {role === 'beneficiary' && (
                                <PersonPickerBankFields
                                    listId={listId}
                                    open={bankDetailsOpen}
                                    setOpen={setBankDetailsOpen}
                                    draft={draft}
                                    setDraft={setDraft}
                                    error={fieldErrors.cbu}
                                />
                            )}

                            {formError && (
                                <div
                                    role="alert"
                                    className="rounded-md border border-destructive/30 bg-destructive-soft px-3 py-2 text-xs text-destructive-strong"
                                >
                                    {formError}
                                </div>
                            )}
                        </div>

                        <div className="flex shrink-0 justify-end gap-2 border-t bg-muted/30 px-4 py-3">
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={cancelCreate}
                                disabled={submitting}
                            >
                                Cancelar
                            </Button>
                            <Button
                                type="submit"
                                size="sm"
                                disabled={submitting}
                            >
                                {submitting && (
                                    <LoaderCircle
                                        className="animate-spin motion-reduce:animate-none"
                                        aria-hidden="true"
                                    />
                                )}
                                {submitting ? 'Guardando…' : 'Guardar y usar'}
                            </Button>
                        </div>
                    </form>
                )}

                <span className="sr-only" aria-live="polite">
                    {loading
                        ? 'Buscando personas'
                        : `${results.length} resultados`}
                </span>
            </PopoverContent>
        </Popover>
    );
}
