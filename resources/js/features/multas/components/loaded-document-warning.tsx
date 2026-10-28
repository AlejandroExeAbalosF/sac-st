/**
 * El aviso de que el número que se escribe ya está cargado en el
 * expediente (`findLoadedDocument`). No bloquea: si se puede, ofrece usar
 * el documento cargado en vez de cargar otro igual.
 */
export default function LoadedDocumentWarning({
    documento,
    onUse,
}: {
    documento: { label: string } | null;
    /** Usar el cargado; sin esto, solo se avisa. */
    onUse?: () => void;
}) {
    if (documento === null) {
        return null;
    }

    return (
        <p className="mt-1 text-xs text-warning-strong">
            Ya está en el expediente: {documento.label}.{' '}
            {onUse ? (
                <button
                    type="button"
                    className="font-medium underline underline-offset-2"
                    onClick={onUse}
                >
                    Usar la cargada
                </button>
            ) : (
                'No hace falta cargarla de nuevo.'
            )}
        </p>
    );
}
