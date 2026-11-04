/** Sin mayúsculas ni tildes: «homologacion» encuentra «Homologación». */
function normalizar(texto: string): string {
    return texto
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase()
        .trim();
}

type Buscable = {
    code: number;
    decreeLetter: string | null;
    name: string;
};

/**
 * Si un tipo de arancel coincide con lo escrito: cada palabra tiene que estar
 * en el código, el inciso o el concepto. Un número o una letra sola se buscan
 * exactos, para que «14» no traiga el 140 ni «n» todo lo que tenga una ene.
 */
export function coincideTipo(tipo: Buscable, consulta: string): boolean {
    const palabras = normalizar(consulta).split(/\s+/).filter(Boolean);
    const concepto = normalizar(tipo.name);

    return palabras.every((palabra) => {
        if (/^\d+$/.test(palabra)) {
            return String(tipo.code) === palabra;
        }

        if (palabra.length === 1) {
            return tipo.decreeLetter === palabra;
        }

        return concepto.includes(palabra);
    });
}
