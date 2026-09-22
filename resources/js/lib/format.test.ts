import { describe, expect, it } from 'vitest';
import {
    compareAmounts,
    businessToday,
    cuit,
    date,
    dateTime,
    dni,
    documentMask,
    isNegative,
    isNonZero,
    money,
    parseAmount,
    subtractAmounts,
    sumAmounts,
} from './format';

/*
 * Estos tests existen por un error concreto: `parseAmount` no era
 * idempotente. Al normalizar un valor que ya estaba normalizado, los
 * centavos se fusionaban con los pesos y el importe se multiplicaba por
 * cien. En la pantalla se veía como «faltan $600.000.000» cuando faltaban
 * seis millones, y en la carga significaba que escribir `1000.50` guardaba
 * cien mil cincuenta pesos.
 *
 * Es la capa que toca la plata: acá no alcanza con que ande el camino
 * feliz.
 */
describe('parseAmount', () => {
    it('lee el formato local, con puntos de miles y coma decimal', () => {
        expect(parseAmount('1.000.000,50')).toBe('1000000.50');
        expect(parseAmount('1.000.000')).toBe('1000000.00');
        expect(parseAmount('0,05')).toBe('0.05');
    });

    it('lee también el punto como separador decimal', () => {
        expect(parseAmount('1000.50')).toBe('1000.50');
        expect(parseAmount('10000000.00')).toBe('10000000.00');
        expect(parseAmount('1.5')).toBe('1.50');
    });

    it('resuelve el punto ambiguo como separador de miles', () => {
        // `1.500` en la Argentina es mil quinientos, no uno y medio.
        expect(parseAmount('1.500')).toBe('1500.00');
        expect(parseAmount('12.345')).toBe('12345.00');
    });

    it('es idempotente: normalizar dos veces da lo mismo', () => {
        const casos = [
            '10000000',
            '10000000.00',
            '4000000.00',
            '-4000000.00',
            '1.000.000',
            '1.000.000,50',
            '1000.50',
            '0,05',
        ];

        for (const caso of casos) {
            const una = parseAmount(caso);

            expect(parseAmount(una), `parseAmount(${caso})`).toBe(una);
        }
    });

    it('conserva el signo y recorta a dos decimales', () => {
        expect(parseAmount('-4000000.00')).toBe('-4000000.00');
        expect(parseAmount('1,999')).toBe('1.99');
        expect(parseAmount('  1 000,5 ')).toBe('1000.50');
    });

    it('devuelve cadena vacía cuando no hay nada que leer', () => {
        expect(parseAmount('')).toBe('');
        expect(parseAmount('   ')).toBe('');
    });
});

describe('sumAmounts', () => {
    it('suma sin punto flotante', () => {
        // 0.1 + 0.2 en coma flotante da 0.30000000000000004.
        expect(sumAmounts(['0.10', '0.20'])).toBe('0.30');
        expect(sumAmounts(['2000000.00', '2000000.00'])).toBe('4000000.00');
    });

    it('resta cuando el importe viene en negativo', () => {
        // El caso que se veía mal en la pantalla de cuotas.
        expect(sumAmounts(['10000000.00', '-4000000.00'])).toBe('6000000.00');
    });

    it('ignora los huecos', () => {
        expect(sumAmounts(['1.00', null, undefined, ''])).toBe('1.00');
        expect(sumAmounts([])).toBe('0.00');
    });

    it('maneja importes que exceden el entero seguro de JavaScript', () => {
        // 90.071.992.547.409,93 en centavos supera Number.MAX_SAFE_INTEGER.
        expect(sumAmounts(['90071992547409.93', '0.01'])).toBe(
            '90071992547409.94',
        );
    });
});

describe('compareAmounts', () => {
    it('compara exacto', () => {
        expect(compareAmounts('4000000.00', '10000000.00')).toBe(-1);
        expect(compareAmounts('10000000.00', '10000000.00')).toBe(0);
        expect(compareAmounts('10000000.01', '10000000.00')).toBe(1);
    });

    it('no se deja engañar por la forma de escribir el mismo importe', () => {
        expect(compareAmounts('1.000.000,00', '1000000')).toBe(0);
        expect(compareAmounts('1000000.00', '1.000.000')).toBe(0);
    });
});

describe('money', () => {
    it('formatea con separadores locales', () => {
        expect(money('1000000.50')).toBe('$ 1.000.000,50');
        expect(money('0.00')).toBe('$ 0,00');
    });

    it('distingue dólares de pesos cuando una pantalla habilite ambas monedas', () => {
        expect(money('1250.50', { currency: 'USD' })).toBe('US$ 1.250,50');
    });
});

describe('viaje de ida y vuelta', () => {
    /*
     * El campo de importe normaliza al perder el foco, asi que lo que
     * escribe `money()` vuelve a entrar por `parseAmount()`. Si el signo se
     * pierde en ese viaje, un importe negativo se convierte en positivo sin
     * que nadie lo note.
     */
    it('lo que formatea money() lo vuelve a leer parseAmount()', () => {
        for (const importe of [
            '1204500.50',
            '-6000000.00',
            '0.05',
            '1000.00',
        ]) {
            const formateado = money(importe, { symbol: false });

            expect(parseAmount(formateado), formateado).toBe(importe);
        }
    });
});

describe('date', () => {
    it('no convierte una fecha de calendario a otra zona horaria', () => {
        // El error clásico: `new Date('2026-01-01')` es medianoche UTC, que
        // en Salta es el 31 de diciembre.
        expect(date('2026-01-01')).toBe('01/01/2026');
        expect(date('2018-03-22')).toBe('22/03/2018');
    });
});

describe('businessToday', () => {
    it('conserva la fecha de Salta cuando UTC ya pasó de medianoche', () => {
        expect(businessToday(new Date('2026-09-04T02:30:00.000Z'))).toBe(
            '2026-09-03',
        );
    });
});

describe('dateTime', () => {
    it('convierte el instante UTC a la hora de Salta incluso cerca de medianoche', () => {
        expect(dateTime('2026-09-23T02:30:00.000Z')).toBe(
            '22/09/2026 23:30',
        );
    });
});

describe('auxiliares', () => {
    it('detecta el cero y el negativo', () => {
        expect(isNonZero('0.00')).toBe(false);
        expect(isNonZero('0.01')).toBe(true);
        expect(isNegative('-1.00')).toBe(true);
        expect(isNegative('-0.00')).toBe(false);
    });

    it('formatea el CUIT', () => {
        expect(cuit('30710442892')).toBe('30-71044289-2');
        expect(cuit('123')).toBe('123');
    });
});

describe('documento', () => {
    it('agrupa el DNI de a tres', () => {
        expect(dni('29939415')).toBe('29.939.415');
        expect(dni('1234567')).toBe('1.234.567');
        expect(dni('123456')).toBe('123.456');
    });

    it('deja intacto lo que no es un DNI', () => {
        expect(dni('12345')).toBe('12345');
        expect(dni('20299394159')).toBe('20299394159');
        expect(dni(null)).toBe('—');
    });

    /*
     * La máscara decide por la cantidad de dígitos y nada más. Los estados
     * intermedios quedan sin separadores a propósito: es la forma de decir
     * «esto todavía no es ni un DNI ni un CUIL».
     */
    it('cambia de forma según la cantidad de dígitos', () => {
        expect(documentMask('299')).toBe('299');
        expect(documentMask('29939415')).toBe('29.939.415');
        expect(documentMask('299394159')).toBe('299394159');
        expect(documentMask('20299394159')).toBe('20-29939415-9');
    });

    it('ignora los separadores que ya estaban escritos', () => {
        expect(documentMask('20-29939415-9')).toBe('20-29939415-9');
        expect(documentMask('29.939.415')).toBe('29.939.415');
        expect(documentMask('')).toBe('');
    });
});

describe('subtractAmounts', () => {
    it('resta sin pasar por punto flotante', () => {
        expect(subtractAmounts('0.30', '0.10')).toBe('0.20');
        expect(subtractAmounts('2009750.00', '1909750.00')).toBe('100000.00');
    });

    it('devuelve el faltante en negativo', () => {
        expect(subtractAmounts('1909750.00', '2009750.00')).toBe('-100000.00');
    });

    it('trata lo que falta como cero', () => {
        expect(subtractAmounts('100.00', null)).toBe('100.00');
        expect(subtractAmounts(null, '100.00')).toBe('-100.00');
    });
});
