import type { ReactNode } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';
import { DialogoCierre } from './closing-dialogs';

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

vi.mock('@/components/ui/dialog', () => {
    const Content = ({ children }: { children: ReactNode }) => children;

    return {
        Dialog: Content,
        DialogContent: Content,
        DialogDescription: Content,
        DialogFooter: Content,
        DialogHeader: Content,
        DialogTitle: Content,
    };
});

vi.mock('@/features/caja/rutas', () => ({
    useRutasDeCaja: () => ({
        cierres: { store: () => ({ url: '/caja/cierres' }) },
    }),
}));

describe('el cierre mensual desde el calendario', () => {
    it('muestra el mes fijo sin ofrecer otro período ni otra fecha', () => {
        const html = renderToStaticMarkup(
            <DialogoCierre
                inicial={{ date: '2026-10-01', periodType: 'monthly' }}
                cerrar={() => {}}
                selected={{ cashBoxId: 1, currency: 'ARS' }}
                pendingDaysByMonth={{}}
                tipoFijo
            />,
        );

        expect(html).toContain('Cerrar octubre de 2026');
        expect(html).toContain('Octubre de 2026');
        expect(html).toContain('Cerrar mes');
        expect(html).not.toContain('id="periodType"');
        expect(html).not.toContain('id="date"');
    });
});
