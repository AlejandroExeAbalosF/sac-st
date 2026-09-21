import {
    Building2,
    Check,
    CircleUserRound,
    Plus,
    RotateCw,
} from 'lucide-react';
import type { Dispatch, SetStateAction } from 'react';
import { personDocumentLabel } from '@/components/person-picker-shared';
import type { PersonOption } from '@/components/person-picker-shared';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

type Props = {
    listId: string;
    query: string;
    setQuery: (query: string) => void;
    results: PersonOption[];
    selectedId: number | null;
    loading: boolean;
    showSkeleton: boolean;
    error: string | null;
    hasMore: boolean;
    retry: Dispatch<SetStateAction<number>>;
    selectPerson: (person: PersonOption) => void;
    startCreate: () => void;
};

/** Panel de búsqueda remoto del selector, con sus estados de carga y error. */
export default function PersonPickerSearchPanel({
    listId,
    query,
    setQuery,
    results,
    selectedId,
    loading,
    showSkeleton,
    error,
    hasMore,
    retry,
    selectPerson,
    startCreate,
}: Props) {
    return (
        <div className="flex min-h-0 flex-1 animate-in flex-col duration-200 fade-in-0 slide-in-from-left-2 motion-reduce:animate-none">
            <Command
                shouldFilter={false}
                className="flex min-h-0 flex-1 flex-col"
            >
                <CommandInput
                    autoFocus
                    value={query}
                    onValueChange={setQuery}
                    aria-label="Buscar por nombre, DNI o CUIT"
                    placeholder="Nombre, DNI o CUIT…"
                />
                <CommandList
                    id={listId}
                    aria-busy={loading}
                    className="max-h-none flex-1"
                >
                    {showSkeleton ? (
                        <div
                            className="grid gap-2 p-2"
                            aria-label="Buscando personas"
                        >
                            {[0, 1, 2].map((row) => (
                                <div
                                    key={row}
                                    className="flex items-center gap-3 px-1 py-1.5"
                                >
                                    <Skeleton className="size-8 rounded-full" />
                                    <div className="grid flex-1 gap-1.5">
                                        <Skeleton className="h-3.5 w-2/3" />
                                        <Skeleton className="h-3 w-1/3" />
                                    </div>
                                </div>
                            ))}
                        </div>
                    ) : error ? (
                        <div className="grid justify-items-center gap-3 px-5 py-8 text-center">
                            <p className="max-w-72 text-sm text-muted-foreground">
                                {error}
                            </p>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() => retry((attempt) => attempt + 1)}
                            >
                                <RotateCw aria-hidden="true" />
                                Reintentar
                            </Button>
                        </div>
                    ) : results.length === 0 ? (
                        <div className="grid justify-items-center gap-2 px-5 py-8 text-center">
                            <CircleUserRound
                                className="size-7 text-muted-foreground"
                                strokeWidth={1.5}
                                aria-hidden="true"
                            />
                            <p className="text-sm font-medium">
                                No encontramos coincidencias
                            </p>
                            <p className="max-w-72 text-xs leading-relaxed text-muted-foreground">
                                Podés registrarla acá sin perder los datos del
                                expediente.
                            </p>
                        </div>
                    ) : (
                        <CommandGroup>
                            {results.map((person) => (
                                <CommandItem
                                    key={person.id}
                                    value={String(person.id)}
                                    onSelect={() => selectPerson(person)}
                                >
                                    <span className="grid size-8 shrink-0 place-items-center rounded-full bg-muted text-muted-foreground">
                                        {person.type === 'company' ? (
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
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate font-medium">
                                            {person.name}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {personDocumentLabel(person)}
                                        </span>
                                    </span>
                                    <Check
                                        className={cn(
                                            'size-4 shrink-0 text-primary transition-opacity',
                                            selectedId === person.id
                                                ? 'opacity-100'
                                                : 'opacity-0',
                                        )}
                                        aria-hidden="true"
                                    />
                                </CommandItem>
                            ))}
                        </CommandGroup>
                    )}
                </CommandList>

                <div className="flex shrink-0 items-center justify-between gap-3 border-t bg-muted/30 p-2">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="justify-start"
                        onClick={startCreate}
                    >
                        <Plus aria-hidden="true" />
                        Registrar nueva persona
                    </Button>
                    {hasMore && !loading && (
                        <span className="pr-1 text-[0.6875rem] text-muted-foreground">
                            Escribí más para acotar
                        </span>
                    )}
                </div>
            </Command>
        </div>
    );
}
