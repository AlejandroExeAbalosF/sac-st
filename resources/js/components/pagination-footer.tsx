import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Button } from '@/components/ui/button';

export type PaginationData = {
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
};

export default function PaginationFooter({
    pagination,
    singular,
    plural,
}: {
    pagination: PaginationData;
    singular: string;
    plural: string;
}) {
    const previous = pagination.links[0]?.url ?? null;
    const next = pagination.links.at(-1)?.url ?? null;
    const totalLabel =
        pagination.total === 0
            ? `Sin ${plural}`
            : pagination.total === 1
              ? `1 ${singular}`
              : `${pagination.from}–${pagination.to} de ${pagination.total} ${plural}`;

    return (
        <div className="flex flex-col gap-3 text-xs text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
            <p aria-live="polite">{totalLabel}</p>

            {pagination.last_page > 1 && (
                <nav
                    className="flex items-center justify-between gap-2 sm:justify-end"
                    aria-label={`Paginación de ${plural}`}
                >
                    <PaginationButton href={previous} label="Anterior">
                        <ChevronLeft aria-hidden="true" />
                        Anterior
                    </PaginationButton>

                    <span className="min-w-20 text-center font-mono tabular-nums">
                        Página {pagination.current_page} de{' '}
                        {pagination.last_page}
                    </span>

                    <PaginationButton href={next} label="Siguiente">
                        Siguiente
                        <ChevronRight aria-hidden="true" />
                    </PaginationButton>
                </nav>
            )}
        </div>
    );
}

function PaginationButton({
    href,
    label,
    children,
}: {
    href: string | null;
    label: string;
    children: React.ReactNode;
}) {
    if (href === null) {
        return (
            <Button variant="outline" size="sm" disabled aria-label={label}>
                {children}
            </Button>
        );
    }

    return (
        <Button asChild variant="outline" size="sm">
            <Link href={href} preserveScroll preserveState aria-label={label}>
                {children}
            </Link>
        </Button>
    );
}
