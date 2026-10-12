import type { ComponentProps, ReactNode } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';
import OrdenCreate from '@/pages/haberes/ordenes/create';
import IssueReceiptDialog from './components/issue-receipt-dialog';
import PayBeneficiaryDialog from './components/pay-beneficiary-dialog';

const message = 'La numeración alcanzó su límite. Configurá una nueva serie.';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({
        props: {
            errors: {
                numbering:
                    'La numeración alcanzó su límite. Configurá una nueva serie.',
            },
            ultimoTalonario: { income: null, expense: null },
        },
    }),
    useForm: (data: Record<string, unknown>) => ({
        data,
        errors: {},
        isDirty: false,
        processing: false,
    }),
}));

// Se renderiza el contenido del diálogo sin portales (SSR no tiene document.body).
vi.mock('@/components/ui/dialog', () => {
    const Content = ({ children }: { children: ReactNode }) => children;

    return {
        Dialog: Content,
        DialogContent: Content,
        DialogHeader: Content,
        DialogTitle: Content,
        DialogDescription: Content,
        DialogFooter: Content,
    };
});
vi.mock('./components/document-frame', () => ({
    default: () => null,
    HOJA_ALTO_A5: 420,
}));
vi.mock('./components/document-viewer-dialog', () => ({ default: () => null }));
vi.mock('./components/order-form-fields', () => ({
    Seccion: ({ children }: { children: ReactNode }) => children,
    Campo: () => null,
    CuentasDelBeneficiario: () => null,
    FojaEnLosPapeles: () => null,
    Opcion: () => null,
    TablaDeDepositos: () => null,
}));

const cuota = {
    id: 1,
    number: 1,
    concept: 'Haberes',
    createdAt: '2026-10-12',
    expectedMedium: 'cash',
    isFullyFunded: true,
    fundedAmount: '1000.00',
    remainingAmount: '0.00',
} as ComponentProps<typeof IssueReceiptDialog>['cuota'];

describe('Errores de numeración enviados por el servidor', () => {
    it('se muestran en la emisión del ingreso', () => {
        const html = renderToStaticMarkup(
            <IssueReceiptDialog
                cuota={cuota}
                firmantes={[]}
                abierto
                onCerrar={() => {}}
            />,
        );
        expect(html).toContain(message);
        expect(html).toContain('role="alert"');
    });

    it.each([false, true])(
        'se muestran en el egreso, con pago ya realizado: %s',
        (needsReceipt) => {
            const estado = {
                amount: '1000.00',
                method: 'cash',
                needsReceipt,
            } as ComponentProps<typeof PayBeneficiaryDialog>['estado'];
            const html = renderToStaticMarkup(
                <PayBeneficiaryDialog
                    cuota={cuota}
                    estado={estado}
                    abierto
                    onCerrar={() => {}}
                />,
            );
            expect(html).toContain(message);
            expect(html).toContain('role="alert"');
        },
    );

    it('se muestran al emitir la orden de pago', () => {
        const props = {
            cuota: { id: 1, number: 1, amount: '1000.00', concept: 'Haberes' },
            estado: { missing: [], sources: [], canIssue: true },
            contexto: {
                accounts: [],
                defaultPaseDestination: 'SAF GOBIERNO',
                beneficiaryName: 'Ana',
                employerName: 'Empresa',
                employerId: 2,
            },
            puedeVerificarCbu: false,
            puedeForzarCbu: false,
        } as unknown as ComponentProps<typeof OrdenCreate>;
        const html = renderToStaticMarkup(<OrdenCreate {...props} />);
        expect(html).toContain(message);
        expect(html).toContain('role="alert"');
    });
});
