/**
 * «Se lee como 169607/2024-0»: lo que va a entender el sistema del número
 * que se escribe, con el año completo. Si el siglo no es el correcto, se
 * escribe el año con cuatro cifras. No se muestra si no se lee nada o si ya
 * se escribió igual.
 */
export default function NumberReadingHint({
    reading,
    text,
}: {
    reading: string | null;
    text: string;
}) {
    if (reading === null || reading === text.trim()) {
        return null;
    }

    return (
        <p className="mt-1 text-xs text-muted-foreground">
            Se lee como <span className="font-mono">{reading}</span>
        </p>
    );
}
