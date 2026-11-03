import { Link } from '@inertiajs/react';
import {
    AlertCircle,
    ArrowRight,
    FileSearch,
    FileText,
    Gavel,
    RotateCw,
    Search,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import SearchHighlight from '@/features/inicio/components/search-highlight';

type SearchResult = {
    id: string;
    title: string;
    description: string;
    context: string;
    status: string;
    href: string;
};

type SearchGroup = {
    key: 'haberes' | 'multas';
    label: string;
    total: number;
    moreHref: string;
    results: SearchResult[];
};

type SearchResponse = {
    query: string;
    total: number;
    groups: SearchGroup[];
};

type RequestState = 'idle' | 'success' | 'error';

export default function HomeSearch({ children }: { children: ReactNode }) {
    const [input, setInput] = useState('');
    const query = useDebouncedValue(input.trim(), 350);
    const [state, setState] = useState<RequestState>('idle');
    const [data, setData] = useState<SearchResponse | null>(null);
    const [failedQuery, setFailedQuery] = useState<string | null>(null);
    const [attempt, setAttempt] = useState(0);
    const inputRef = useRef<HTMLInputElement>(null);
    const normalizedInput = input.trim();
    const open = normalizedInput.length > 0;
    const ready = normalizedInput.length >= 2;
    const debouncing = ready && query !== normalizedInput;

    useEffect(() => {
        if (query.length < 2) {
            return;
        }

        const controller = new AbortController();

        void fetch(`/inicio/buscar?q=${encodeURIComponent(query)}`, {
            signal: controller.signal,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                return response.json() as Promise<SearchResponse>;
            })
            .then((response) => {
                setData(response);
                setFailedQuery(null);
                setState('success');
            })
            .catch((error: unknown) => {
                if (
                    error instanceof DOMException &&
                    error.name === 'AbortError'
                ) {
                    return;
                }

                setFailedQuery(query);
                setState('error');
            });

        return () => controller.abort();
    }, [query, attempt]);

    const loading =
        debouncing || (ready && data?.query !== query && failedQuery !== query);
    const announcement = !open
        ? ''
        : !ready
          ? 'Escribí al menos dos caracteres para buscar.'
          : loading
            ? 'Buscando expedientes y multas.'
            : state === 'error'
              ? 'No se pudo completar la búsqueda.'
              : `${data?.total ?? 0} resultados encontrados.`;

    const clear = () => {
        setInput('');
        setData(null);
        setFailedQuery(null);
        setState('idle');
        inputRef.current?.focus();
    };

    return (
        <div className="grid gap-6">
            <div
                role="search"
                aria-label="Búsqueda global"
                className="mx-auto w-full max-w-3xl"
            >
                <label
                    htmlFor="home-global-search"
                    className="mb-2 block text-sm font-semibold"
                >
                    Buscar en el sistema
                </label>
                <div className="relative">
                    <Search
                        className="pointer-events-none absolute top-1/2 left-4 size-5 -translate-y-1/2 text-muted-foreground transition-colors duration-200 peer-focus:text-primary motion-reduce:transition-none"
                        aria-hidden="true"
                    />
                    <Input
                        ref={inputRef}
                        id="home-global-search"
                        type="search"
                        value={input}
                        maxLength={120}
                        autoComplete="off"
                        spellCheck={false}
                        placeholder="Número, carátula, empleador o beneficiario…"
                        aria-describedby="home-search-hint"
                        aria-controls={open ? 'home-search-results' : undefined}
                        aria-keyshortcuts="Escape"
                        onChange={(event) => setInput(event.target.value)}
                        onKeyDown={(event) => {
                            if (event.key === 'Escape') {
                                event.preventDefault();
                                clear();
                            }
                        }}
                        className="peer h-12 rounded-lg pr-12 pl-12 text-base transition-[border-color,box-shadow] motion-reduce:transition-none"
                    />
                    {input !== '' && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            aria-label="Limpiar búsqueda"
                            onClick={clear}
                            className="absolute top-1/2 right-1.5 -translate-y-1/2"
                        >
                            <X aria-hidden="true" />
                        </Button>
                    )}
                </div>
                <p
                    id="home-search-hint"
                    className="mt-2 text-xs text-muted-foreground"
                >
                    Haberes y Multas · La búsqueda comienza con 2 caracteres
                </p>
            </div>

            <p
                role="status"
                aria-live="polite"
                aria-atomic="true"
                className="sr-only"
            >
                {announcement}
            </p>

            {open ? (
                <section
                    key="search-results"
                    id="home-search-results"
                    aria-label="Resultados de búsqueda global"
                    className="animate-in duration-300 ease-out fade-in-0 slide-in-from-bottom-2 motion-reduce:animate-none"
                >
                    <div className="mb-5 flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <h2 className="text-lg font-semibold">
                                Búsqueda global
                            </h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {ready
                                    ? `Resultados para “${normalizedInput}”`
                                    : 'Encontrá expedientes y multas de las dos cajas.'}
                            </p>
                        </div>
                        <Button variant="ghost" size="sm" onClick={clear}>
                            Volver al inicio
                        </Button>
                    </div>

                    {!ready ? (
                        <SearchPrompt />
                    ) : loading ? (
                        <SearchSkeleton />
                    ) : state === 'error' ? (
                        <SearchError
                            onRetry={() => {
                                setFailedQuery(null);
                                setState('idle');
                                setAttempt((value) => value + 1);
                            }}
                        />
                    ) : data?.total === 0 ? (
                        <EmptySearch />
                    ) : (
                        <div className="grid gap-7">
                            {data?.groups
                                .filter((group) => group.total > 0)
                                .map((group) => (
                                    <ResultGroup
                                        key={group.key}
                                        group={group}
                                        query={query}
                                    />
                                ))}
                        </div>
                    )}
                </section>
            ) : (
                <div
                    key="cash-boxes"
                    className="animate-in duration-300 ease-out fade-in-0 slide-in-from-bottom-2 motion-reduce:animate-none"
                >
                    {children}
                </div>
            )}
        </div>
    );
}

function ResultGroup({ group, query }: { group: SearchGroup; query: string }) {
    const Icon = group.key === 'haberes' ? FileText : Gavel;

    return (
        <section aria-labelledby={`search-group-${group.key}`}>
            <div className="mb-2 flex items-center gap-2">
                <Icon className="size-4 text-primary" aria-hidden="true" />
                <h3
                    id={`search-group-${group.key}`}
                    className="text-sm font-semibold"
                >
                    {group.label}
                </h3>
                <span className="rounded-full bg-muted px-2 py-0.5 text-xs font-medium tabular-nums">
                    {group.total}
                </span>
            </div>

            <div className="divide-y overflow-hidden rounded-lg border bg-card shadow-raised">
                {group.results.map((result, index) => (
                    <Link
                        key={result.id}
                        href={result.href}
                        style={{
                            animationDelay: `${Math.min(index * 45, 180)}ms`,
                        }}
                        className="group flex animate-in items-start gap-4 px-5 py-4 duration-300 fade-in-0 [animation-fill-mode:both] slide-in-from-bottom-1 hover:bg-accent/35 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset motion-reduce:animate-none"
                    >
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <p className="font-semibold text-primary">
                                    <SearchHighlight
                                        text={result.title}
                                        query={query}
                                    />
                                </p>
                                <span className="rounded-full bg-muted px-2 py-0.5 text-[11px] font-medium text-muted-foreground">
                                    {result.status}
                                </span>
                            </div>
                            <p className="mt-1 text-sm break-words">
                                <SearchHighlight
                                    text={result.description}
                                    query={query}
                                />
                            </p>
                            {result.context !== '' && (
                                <p className="mt-1 text-xs break-words text-muted-foreground">
                                    <SearchHighlight
                                        text={result.context}
                                        query={query}
                                    />
                                </p>
                            )}
                        </div>
                        <ArrowRight
                            className="mt-1 size-4 shrink-0 text-muted-foreground transition-transform duration-200 group-hover:translate-x-1 group-hover:text-primary motion-reduce:transform-none motion-reduce:transition-none"
                            aria-hidden="true"
                        />
                    </Link>
                ))}
            </div>

            {group.total > group.results.length && (
                <Link
                    href={group.moreHref}
                    className="mt-3 inline-flex min-h-9 items-center gap-1 text-sm font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    Ver los {group.total} resultados en {group.label}
                    <ArrowRight className="size-3.5" aria-hidden="true" />
                </Link>
            )}
        </section>
    );
}

function SearchPrompt() {
    return (
        <div className="flex min-h-64 flex-col items-center justify-center gap-3 rounded-lg border border-dashed px-5 py-10 text-center">
            <Search className="size-8 text-primary" aria-hidden="true" />
            <p className="font-medium">Agregá un carácter más para buscar</p>
            <p className="max-w-md text-sm text-muted-foreground">
                Podés usar el número del expediente, una carátula, un empleador
                o un beneficiario.
            </p>
        </div>
    );
}

function SearchSkeleton() {
    return (
        <div
            className="divide-y overflow-hidden rounded-lg border bg-card"
            aria-label="Buscando resultados"
            aria-busy="true"
        >
            {Array.from({ length: 5 }, (_, index) => (
                <div key={index} className="flex items-center gap-4 px-5 py-5">
                    <div className="min-w-0 flex-1">
                        <Skeleton className="h-4 w-40" />
                        <Skeleton className="mt-2 h-3 w-3/4" />
                        <Skeleton className="mt-2 h-3 w-1/2" />
                    </div>
                    <Skeleton className="size-4 shrink-0" />
                </div>
            ))}
        </div>
    );
}

function SearchError({ onRetry }: { onRetry: () => void }) {
    return (
        <div
            role="alert"
            className="flex min-h-64 flex-col items-center justify-center gap-3 rounded-lg border border-dashed px-5 py-10 text-center"
        >
            <AlertCircle
                className="size-8 text-destructive"
                aria-hidden="true"
            />
            <p className="font-medium">No pudimos completar la búsqueda</p>
            <p className="text-sm text-muted-foreground">
                Revisá la conexión e intentá nuevamente.
            </p>
            <Button variant="outline" onClick={onRetry}>
                <RotateCw aria-hidden="true" />
                Reintentar
            </Button>
        </div>
    );
}

function EmptySearch() {
    return (
        <div className="flex min-h-64 flex-col items-center justify-center gap-3 rounded-lg border border-dashed px-5 py-10 text-center">
            <FileSearch
                className="size-8 text-muted-foreground"
                aria-hidden="true"
            />
            <p className="font-medium">No encontramos coincidencias</p>
            <p className="max-w-md text-sm text-muted-foreground">
                Probá con menos palabras o revisá el número ingresado.
            </p>
        </div>
    );
}

function useDebouncedValue<T>(value: T, delay: number): T {
    const [debounced, setDebounced] = useState(value);

    useEffect(() => {
        const timeout = window.setTimeout(() => setDebounced(value), delay);

        return () => window.clearTimeout(timeout);
    }, [delay, value]);

    return debounced;
}
