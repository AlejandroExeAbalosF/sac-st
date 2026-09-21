import { describe, expect, it } from 'vitest';
import { recorridoDe } from './components/installment-timeline';

type Cuota = App.Modules.Haberes.Data.InstallmentListItemData;
type Orden = App.Modules.Haberes.Data.InstallmentOrderStateData;
type Egreso = App.Modules.Haberes.Data.InstallmentDisbursementData;

/** Lo mínimo que `recorridoDe` mira, sin armar una cuota entera. */
const cuota = (extra: Partial<Cuota> = {}): Cuota =>
    ({
        createdAt: '2026-09-01',
        incomeReceipt: null,
        cashTransfer: null,
        ...extra,
    }) as Cuota;

const titulos = (hitos: { titulo: string }[]) => hitos.map((h) => h.titulo);

describe('recorridoDe', () => {
    it('una cuota recién cargada tiene un solo hito', () => {
        expect(titulos(recorridoDe(cuota()))).toEqual(['Cuota cargada']);
    });

    /*
     * Lo que no ocurrió no se dibuja. La lista cuenta lo que pasó; lo que
     * falta se lee en la etapa, que dice exactamente qué se está esperando.
     */
    it('no inventa los hitos que todavía no pasaron', () => {
        const hitos = recorridoDe(
            cuota({
                incomeReceipt: {
                    issueDate: '2026-09-05',
                    formattedNumber: '0001-00000045',
                } as Cuota['incomeReceipt'],
            }),
        );

        expect(titulos(hitos)).toEqual([
            'Cuota cargada',
            'Recibo de ingreso emitido',
        ]);
    });

    it('lleva el número del comprobante como detalle', () => {
        const hitos = recorridoDe(
            cuota({
                incomeReceipt: {
                    issueDate: '2026-09-05',
                    formattedNumber: '0001-00000045',
                } as Cuota['incomeReceipt'],
            }),
        );

        expect(hitos[1].detalle).toBe('0001-00000045');
    });

    /*
     * El §12.3 y el §12.4 describen el informe del organismo y el débito
     * del extracto llegando en cualquier orden. Ordenar por el circuito en
     * vez de por la fecha contaría una historia que no fue.
     */
    it('ordena por fecha y no por el orden del circuito', () => {
        const hitos = recorridoDe(cuota(), undefined, {
            disbursement: {
                reportedAt: '2026-09-20',
                debitDate: '2026-09-15',
            },
        } as Egreso);

        expect(titulos(hitos)).toEqual([
            'Cuota cargada',
            'Débito reconocido en el extracto',
            'El organismo informó la transferencia',
        ]);
    });

    it('cuenta el depósito y su acreditación como dos hechos', () => {
        const hitos = recorridoDe(
            cuota({
                cashTransfer: {
                    depositDate: '2026-09-16',
                    creditedDate: '2026-09-17',
                    operationNumber: '995979830',
                } as Cuota['cashTransfer'],
            }),
        );

        expect(titulos(hitos)).toEqual([
            'Cuota cargada',
            'Efectivo depositado en la cuenta',
            'Depósito identificado en el extracto',
        ]);
        expect(hitos[1].detalle).toBe('operación 995979830');
    });

    it('arma el recorrido completo del circuito bancario', () => {
        const hitos = recorridoDe(
            cuota({
                incomeReceipt: {
                    issueDate: '2026-09-05',
                    formattedNumber: '0001-00000045',
                } as Cuota['incomeReceipt'],
            }),
            {
                order: {
                    orderDate: '2026-09-18',
                    formattedNumber: '0001-00000123',
                },
            } as Orden,
            {
                disbursement: {
                    reportedAt: '2026-09-19',
                    debitDate: '2026-09-21',
                    validatedAt: '2026-09-22',
                    validatedByName: 'Rodríguez, Marcela',
                },
                receipt: {
                    issueDate: '2026-09-23',
                    formattedNumber: '0002-00000077',
                },
            } as Egreso,
        );

        expect(titulos(hitos)).toEqual([
            'Cuota cargada',
            'Recibo de ingreso emitido',
            'Orden de Pago emitida',
            'El organismo informó la transferencia',
            'Débito reconocido en el extracto',
            'Egreso validado',
            'Recibo de egreso emitido',
        ]);
    });
});
