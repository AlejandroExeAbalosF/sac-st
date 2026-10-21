import type { ReactNode } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';
import CreditMatchDialog from './components/credit-match-dialog';

const rechazo = 'Ese movimiento no tiene libre el importe exacto del depósito.';

vi.mock('@inertiajs/react', () => ({
    router: { post: () => {} },
    usePage: () => ({
        props: {
            errors: {
                'acreditacion-7': {
                    bankTransactionId:
                        'Ese movimiento no tiene libre el importe exacto del depósito.',
                },
            },
        },
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

function dialogo(id: number) {
    return renderToStaticMarkup(
        <CreditMatchDialog
            traslado={{ id, amount: '20000.00', depositDate: '2026-10-21' }}
            candidatesUrl={`/traslados/${id}/candidatos`}
            confirmUrl={`/traslados/${id}/acreditar`}
            abierto
            onCerrar={() => {}}
        />,
    );
}

describe('El rechazo de la acreditación', () => {
    it('se muestra en el diálogo del traslado rechazado', () => {
        const html = dialogo(7);

        expect(html).toContain(rechazo);
        expect(html).toContain('role="alert"');
    });

    it('no aparece en el diálogo de otro traslado', () => {
        expect(dialogo(8)).not.toContain(rechazo);
    });
});
