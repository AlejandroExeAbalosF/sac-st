export type DestinoDeCalendario = { anio: number; mes?: number };

/**
 * A dónde llevan las flechas del calendario.
 *
 * **Mueven lo que se está mirando**: el mes cuando se entró a uno, el año
 * cuando se ve la grilla de los doce. Moverse de a un año dentro de un mes
 * obligaba a doce clics para llegar al mes de al lado.
 *
 * `mes` en `null` significa la vista del año, que es como lo dice el
 * servidor: sin mes en la dirección, la vista es la del año.
 */
export function pasoDeCalendario(
    anio: number,
    mes: number | null,
    direccion: -1 | 1,
): DestinoDeCalendario {
    if (mes === null) {
        return { anio: anio + direccion };
    }

    const destino = mes + direccion;

    if (destino === 0) {
        return { anio: anio - 1, mes: 12 };
    }

    if (destino === 13) {
        return { anio: anio + 1, mes: 1 };
    }

    return { anio, mes: destino };
}

/**
 * Si el destino ya empezó.
 *
 * No se ofrece avanzar a lo que todavía no pasó: el calendario ya apaga los
 * días futuros, y una flecha que no lleva a ningún lado es peor que una
 * apagada, porque parece que la pantalla no respondió.
 *
 * Un destino sin mes es un año entero, y alcanza con que haya empezado.
 */
export function yaEmpezo(destino: DestinoDeCalendario, hoy: Date): boolean {
    if (destino.anio !== hoy.getFullYear()) {
        return destino.anio < hoy.getFullYear();
    }

    return (destino.mes ?? 1) <= hoy.getMonth() + 1;
}
