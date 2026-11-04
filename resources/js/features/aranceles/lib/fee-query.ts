type Valor =
    string | number | null | undefined | Valor[] | { [k: string]: Valor };

/**
 * Los datos de un cobro como consulta: `items[0][feeTypeId]=14`, que es lo que
 * Laravel lee como arreglo. Lo vacío no viaja.
 */
export function consultaDeCobro(datos: Record<string, Valor>): string {
    const pares = new URLSearchParams();

    const agregar = (clave: string, valor: Valor) => {
        if (valor === null || valor === undefined || valor === '') {
            return;
        }

        if (typeof valor === 'object') {
            Object.entries(valor).forEach(([k, v]) =>
                agregar(`${clave}[${k}]`, v),
            );

            return;
        }

        pares.append(clave, String(valor));
    };

    Object.entries(datos).forEach(([k, v]) => agregar(k, v));

    return pares.toString();
}
