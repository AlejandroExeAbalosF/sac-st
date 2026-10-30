import { useEffect, useMemo } from 'react';
import { businessToday } from '@/lib/format';

/**
 * Un período de una cuenta en la caja, como lo manda
 * `CashBoxBankAccounts::depositOptions`. Sin vigencia, una cuenta de una
 * caja que todavía no controla sus cuentas.
 */
export type PeriodoDeCuenta = {
    id: number;
    label: string;
    isDefault: boolean;
    validFrom: string | null;
    validUntil: string | null;
};

const cubre = (p: PeriodoDeCuenta, dia: string): boolean =>
    (p.validFrom === null || p.validFrom <= dia) &&
    (p.validUntil === null || p.validUntil >= dia);

/**
 * Las cuentas vigentes ese día, una vez cada una, la predeterminada
 * primero. Sin fecha todavía, las de hoy: un depósito de antes de una baja
 * aparece cuando se carga su fecha.
 */
export function cuentasDelDia<T extends PeriodoDeCuenta>(
    periodos: T[],
    fecha: string,
): T[] {
    const dia = fecha === '' ? businessToday() : fecha;
    const vistas = new Set<number>();

    return periodos
        .filter((p) => cubre(p, dia))
        .sort(
            (a, b) =>
                Number(b.isDefault) - Number(a.isDefault) ||
                a.label.localeCompare(b.label),
        )
        .filter((p) => !vistas.has(p.id) && vistas.add(p.id) !== undefined);
}

/**
 * Las cuentas del día del depósito, y la elegida al día: si la fecha deja
 * afuera la que estaba, pasa a la predeterminada o a la primera vigente.
 * `conservar` sigue en la lista aunque no valga ese día —al corregir un
 * comprobante, la cuenta que ya tiene—; el servidor decide igual.
 */
export function useCuentasDelDia<T extends PeriodoDeCuenta>(
    periodos: T[],
    fecha: string,
    elegida: string,
    elegir: (id: string) => void,
    conservar?: string,
): T[] {
    const vigentes = useMemo(() => {
        const delDia = cuentasDelDia(periodos, fecha);
        const fija = periodos.find((p) => String(p.id) === conservar);

        return fija && !delDia.some((p) => p.id === fija.id)
            ? [...delDia, fija]
            : delDia;
    }, [periodos, fecha, conservar]);

    useEffect(() => {
        if (!vigentes.some((p) => String(p.id) === elegida)) {
            elegir(vigentes[0] ? String(vigentes[0].id) : '');
        }
        // `elegir` cambia en cada render de useForm: no es una dependencia real.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [vigentes, elegida]);

    return vigentes;
}
