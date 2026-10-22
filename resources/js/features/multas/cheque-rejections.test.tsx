import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import ChequeRejections from './components/cheque-rejections';

describe('Alcance vigente del rechazo de cheques', () => {
    it('conserva reemplazo y papeles y deja el banco pendiente sin acciones avanzadas', () => {
        const html = renderToStaticMarkup(
            <ChequeRejections
                amount="20000.00"
                canManage
                canReplace
                canViewAttachments={false}
                rechazos={[
                    {
                        id: 1,
                        chequeNumber: '123',
                        chequeBank: 'Macro',
                        rejectedOn: '2026-10-22',
                        reason: 'Sin fondos',
                        registeredByName: null,
                        bankStatus: 'debit_pending',
                        depositDate: '2026-10-21',
                        creditOn: null,
                        debitOn: null,
                        neverCreditedNote: null,
                        companyNotifiedOn: null,
                        disposition: null,
                        regularized: false,
                        replacementChequeNumber: null,
                        attachments: [],
                    },
                ]}
            />,
        );
        expect(html).toContain('Recibir reemplazo');
        expect(html).toContain('Completar');
        expect(html).toContain(
            'Rechazo con acreditación previa: revisión bancaria pendiente',
        );
        expect(html).not.toContain('Imputar');
        expect(html).not.toContain('El banco no lo acreditó');
    });
});
