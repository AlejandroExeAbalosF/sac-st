import {
    Check,
    ChevronRight,
    CircleUserRound,
    LoaderCircle,
} from 'lucide-react';
import type { Dispatch, SetStateAction } from 'react';
import DocumentInput, { AYUDA_DNI } from '@/components/document-input';
import HintedLabel from '@/components/hinted-label';
import { esDocumentoBuscable } from '@/components/person-picker-shared';
import type {
    OwnerLookup,
    PersonDraft,
} from '@/components/person-picker-shared';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

type Props = {
    listId: string;
    open: boolean;
    setOpen: Dispatch<SetStateAction<boolean>>;
    draft: PersonDraft;
    setDraft: Dispatch<SetStateAction<PersonDraft>>;
    owner: OwnerLookup;
    setOwner: Dispatch<SetStateAction<OwnerLookup>>;
    errors: Record<string, string>;
};

/** Datos opcionales del titular y resolución contra el maestro de personas. */
export default function PersonPickerOwnerFields({
    listId,
    open,
    setOpen,
    draft,
    setDraft,
    owner,
    setOwner,
    errors,
}: Props) {
    return (
        <div className="border-t pt-3">
            <button
                type="button"
                onClick={() => setOpen((current) => !current)}
                aria-expanded={open}
                aria-controls={`${listId}-owner`}
                className="flex min-h-9 w-full items-center gap-2 rounded-md text-left text-xs font-medium text-muted-foreground transition-colors duration-150 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <span className="grid size-7 place-items-center rounded-full bg-muted">
                    <CircleUserRound className="size-3.5" aria-hidden="true" />
                </span>
                <span className="flex-1">
                    Titular del organismo
                    <span className="ml-1 font-normal">(opcional)</span>
                </span>
                <ChevronRight
                    className={cn(
                        'size-3.5 transition-transform duration-200 motion-reduce:transition-none',
                        open && 'rotate-90',
                    )}
                    aria-hidden="true"
                />
            </button>

            <div
                id={`${listId}-owner`}
                aria-hidden={!open}
                className={cn(
                    'grid transition-[grid-template-rows,opacity] duration-300 ease-out motion-reduce:transition-none',
                    open
                        ? 'grid-rows-[1fr] opacity-100'
                        : 'grid-rows-[0fr] opacity-0',
                )}
            >
                <div className="overflow-hidden">
                    <div className="grid gap-1.5 pt-3">
                        <HintedLabel
                            htmlFor={`${listId}-owner-document`}
                            hint={AYUDA_DNI}
                        >
                            DNI o CUIL
                        </HintedLabel>
                        <DocumentInput
                            id={`${listId}-owner-document`}
                            value={draft.ownerDocument}
                            onChange={(ownerDocument) => {
                                setDraft((current) => ({
                                    ...current,
                                    ownerDocument,
                                }));
                                setOwner(
                                    esDocumentoBuscable(ownerDocument)
                                        ? { estado: 'buscando' }
                                        : { estado: 'vacio' },
                                );
                            }}
                            tabIndex={open ? 0 : -1}
                            aria-invalid={Boolean(errors.ownerDocument)}
                            aria-describedby={`${listId}-owner-estado`}
                            placeholder="28114902"
                        />

                        <div id={`${listId}-owner-estado`} aria-live="polite">
                            <OwnerStatus
                                owner={owner}
                                error={errors.ownerDocument}
                            />
                        </div>
                    </div>

                    {owner.estado === 'nuevo' && (
                        <div className="grid gap-4 pt-3 sm:grid-cols-2">
                            <OwnerNameField
                                id={`${listId}-owner-last-name`}
                                label="Apellido"
                                value={draft.ownerLastName}
                                error={errors.ownerLastName}
                                placeholder="Pérez"
                                tabIndex={open ? 0 : -1}
                                onChange={(ownerLastName) =>
                                    setDraft((current) => ({
                                        ...current,
                                        ownerLastName,
                                    }))
                                }
                            />
                            <OwnerNameField
                                id={`${listId}-owner-first-name`}
                                label="Nombre"
                                value={draft.ownerFirstName}
                                error={errors.ownerFirstName}
                                placeholder="María Elena"
                                tabIndex={open ? 0 : -1}
                                onChange={(ownerFirstName) =>
                                    setDraft((current) => ({
                                        ...current,
                                        ownerFirstName,
                                    }))
                                }
                            />
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

function OwnerStatus({ owner, error }: { owner: OwnerLookup; error?: string }) {
    if (error) {
        return <p className="text-xs text-destructive-strong">{error}</p>;
    }

    if (owner.estado === 'buscando') {
        return (
            <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                <LoaderCircle
                    className="size-3 animate-spin motion-reduce:animate-none"
                    aria-hidden="true"
                />
                Buscando en el maestro…
            </p>
        );
    }

    if (owner.estado === 'encontrado') {
        return (
            <p className="flex items-center gap-1.5 text-xs text-success-strong">
                <Check className="size-3" aria-hidden="true" />
                {owner.person.name}
            </p>
        );
    }

    if (owner.estado === 'nuevo') {
        return (
            <p className="text-xs text-warning-strong">
                No está en el maestro. Cargá su apellido y nombre para
                registrarlo.
            </p>
        );
    }

    if (owner.estado === 'error') {
        return (
            <p className="text-xs text-destructive-strong">{owner.message}</p>
        );
    }

    return (
        <p className="text-xs text-muted-foreground">
            Quien está al frente del organismo.
        </p>
    );
}

function OwnerNameField({
    id,
    label,
    value,
    error,
    placeholder,
    tabIndex,
    onChange,
}: {
    id: string;
    label: string;
    value: string;
    error?: string;
    placeholder: string;
    tabIndex: number;
    onChange: (value: string) => void;
}) {
    return (
        <div>
            <Label htmlFor={id}>{label}</Label>
            <Input
                id={id}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className="mt-1"
                autoComplete="off"
                maxLength={80}
                tabIndex={tabIndex}
                aria-invalid={Boolean(error)}
                placeholder={placeholder}
            />
            {error && (
                <p className="mt-1 text-xs text-destructive-strong">{error}</p>
            )}
        </div>
    );
}
