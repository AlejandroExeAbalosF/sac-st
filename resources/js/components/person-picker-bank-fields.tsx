import { ChevronRight, Landmark } from 'lucide-react';
import type { Dispatch, SetStateAction } from 'react';
import type { PersonDraft } from '@/components/person-picker-shared';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

type Props = {
    listId: string;
    open: boolean;
    setOpen: Dispatch<SetStateAction<boolean>>;
    draft: PersonDraft;
    setDraft: Dispatch<SetStateAction<PersonDraft>>;
    error?: string;
};

/** Alta opcional del CBU, oculto hasta que el operador decide cargarlo. */
export default function PersonPickerBankFields({
    listId,
    open,
    setOpen,
    draft,
    setDraft,
    error,
}: Props) {
    return (
        <div className="border-t pt-3">
            <button
                type="button"
                onClick={() => setOpen((current) => !current)}
                aria-expanded={open}
                aria-controls={`${listId}-bank-details`}
                className="flex min-h-9 w-full items-center gap-2 rounded-md text-left text-xs font-medium text-muted-foreground transition-colors duration-150 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <span className="grid size-7 place-items-center rounded-full bg-muted">
                    <Landmark className="size-3.5" aria-hidden="true" />
                </span>
                <span className="flex-1">
                    Datos bancarios
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
                id={`${listId}-bank-details`}
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
                        <Label htmlFor={`${listId}-cbu`}>CBU</Label>
                        <Input
                            id={`${listId}-cbu`}
                            value={draft.cbu}
                            onChange={(event) =>
                                setDraft((current) => ({
                                    ...current,
                                    cbu: event.target.value
                                        .replace(/\D+/g, '')
                                        .slice(0, 22),
                                }))
                            }
                            tabIndex={open ? 0 : -1}
                            className="font-mono tracking-[0.08em] tabular-nums"
                            inputMode="numeric"
                            autoComplete="off"
                            maxLength={22}
                            aria-invalid={Boolean(error)}
                            aria-describedby={`${listId}-cbu-help${error ? ` ${listId}-cbu-error` : ''}`}
                            placeholder="2850000300000000000024"
                        />
                        {error && (
                            <p
                                id={`${listId}-cbu-error`}
                                className="text-xs text-destructive-strong"
                            >
                                {error}
                            </p>
                        )}
                        <p
                            id={`${listId}-cbu-help`}
                            className="text-xs leading-relaxed text-muted-foreground"
                        >
                            Podés cargarlo más adelante. Se verificará antes de
                            emitir la Orden de Pago.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    );
}
