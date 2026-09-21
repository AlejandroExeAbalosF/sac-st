/**
 * Avisa cuando el número del talonario no sigue al anterior.
 *
 * **No valida nada.** El número sale de un lote de papel impreso afuera: el
 * sistema no lo genera, es opcional, y no se espera que venga correlativo —
 * un talonario nuevo empieza donde el fabricante quiso, y en la planilla de
 * junio de 2026 hay un salto de cincuenta números en el medio de un día.
 *
 * Lo único que hace es cazar el error de tipeo en el momento: un `72912`
 * escrito donde iba `72192` salta a la vista si al lado dice cuál fue el
 * último. Un salto legítimo se ignora y se sigue.
 *
 * Por eso **calla cuando el número sí sigue**: un aviso que aparece siempre
 * deja de leerse a las dos semanas.
 */
export default function TalonarioHint({
    value,
    ultimo,
}: {
    value: string;
    /** El último número cargado en esta serie, o `null` si no hay ninguno. */
    ultimo: string | null;
}) {
    const escrito = value.trim();

    if (escrito === '' || ultimo === null) {
        return null;
    }

    if (sigueAl(ultimo, escrito)) {
        return null;
    }

    return (
        <p className="text-xs text-warning-strong">
            El último cargado fue{' '}
            <span className="font-mono tabular-nums">{ultimo}</span>. Verificá
            que sea el que dice el papel.
        </p>
    );
}

/**
 * Si `candidato` es el número inmediatamente siguiente a `ultimo`.
 *
 * Se compara sobre los dígitos y se conserva el relleno de ceros a la
 * izquierda —`00071513` da `00071514`— porque esa es la forma en que el
 * área escribe el número y cambiarla haría que el aviso apareciera en el
 * caso correcto.
 *
 * Un número que no es solo dígitos no se puede suceder: se lo da por
 * distinto y el aviso aparece, que es lo prudente.
 */
function sigueAl(ultimo: string, candidato: string): boolean {
    if (!/^\d+$/.test(ultimo) || !/^\d+$/.test(candidato)) {
        return false;
    }

    const siguiente = (BigInt(ultimo) + 1n).toString();

    return candidato === siguiente.padStart(ultimo.length, '0');
}
