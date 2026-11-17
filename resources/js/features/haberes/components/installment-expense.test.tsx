import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';
import InstallmentExpense from './installment-expense';

vi.mock('@inertiajs/react', () => ({
    useForm: (data: Record<string, unknown>) => ({
        data,
        errors: {},
        processing: false,
        setData: () => {},
        post: () => {},
        reset: () => {},
    }),
    usePage: () => ({ props: { errors: {} } }),
}));

type Cuota = App.Modules.Haberes.Data.InstallmentListItemData;
type Estado = App.Modules.Haberes.Data.InstallmentDisbursementData;

const egreso: App.Modules.Haberes.Data.DisbursementSummaryData = {
    id: 7,
    method: 'bank_transfer',
    status: 'report_received',
    amount: '1000.00',
    paymentDate: null,
    deliveredByName: null,
    notes: null,
    reportedAt: '2026-11-17',
    transferReference: '83690105',
    reportAttachment: null,
    reportHistory: null,
    beneficiaryCbu: null,
    debitTransactionId: null,
    debitDate: null,
    debitOperationId: null,
    debitDescription: null,
    debitAmount: null,
    feeAmount: null,
    validatedByName: null,
    validatedAt: null,
};

/** Una transferencia informada, sin débito, sobre una cuota con traba. */
const trabada: Estado = {
    channel: 'transfer',
    applies: true,
    isCounter: false,
    blockedReason: 'La etiqueta «Retener pago» frena el pago de la cuota.',
    method: 'bank_transfer',
    amount: '1000.00',
    disbursement: egreso,
    receipt: null,
    voidedReceipts: [],
    hasIncomeReceipt: true,
    orderNumber: '0001/2026',
    pendingStep: null,
    canPay: false,
    canReportTransfer: false,
    canLinkDebit: false,
    canUnlinkDebit: false,
    canValidate: false,
    canFailTransfer: true,
    needsReceipt: false,
};

function render(estado: Estado, puedeDarPorFallida: boolean): string {
    return renderToStaticMarkup(
        <InstallmentExpense
            cuota={{ id: 1 } as Cuota}
            estado={estado}
            puedePagar
            puedeValidar
            puedeDarPorFallida={puedeDarPorFallida}
        />,
    );
}

describe('la transferencia en curso de una cuota trabada', () => {
    it('muestra la traba y ofrece darla por fallida', () => {
        const html = render(trabada, true);

        expect(html).toContain('frena el pago de la cuota');
        expect(html).toContain('Dar por fallida');
    });

    it('no la ofrece sin el permiso', () => {
        expect(render(trabada, false)).not.toContain('Dar por fallida');
    });

    it('no la ofrece cuando ya no se puede cerrar', () => {
        expect(
            render({ ...trabada, canFailTransfer: false }, true),
        ).not.toContain('Dar por fallida');
    });
});
