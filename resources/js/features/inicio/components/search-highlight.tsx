type Props = {
    text: string;
    query: string;
};

/** Resalta el término como texto literal; barras y puntos no se interpretan. */
export default function SearchHighlight({ text, query }: Props) {
    const term = query.trim();

    if (term === '') {
        return text;
    }

    const escaped = term.replace(/[.*+?^$()|[\]\\{}]/g, '\\$&');
    const parts = text.split(new RegExp(`(${escaped})`, 'gi'));

    return parts.map((part, index) =>
        index % 2 === 1 ? (
            <mark
                key={`${part}-${index}`}
                className="rounded-sm bg-primary/20 px-0.5 text-foreground"
            >
                {part}
            </mark>
        ) : (
            part
        ),
    );
}
