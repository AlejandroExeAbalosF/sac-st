import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import AmountInput from './amount-input';
import { EnMoneda } from './money';

const campo = (value: string) =>
    renderToStaticMarkup(<AmountInput value={value} onChange={() => {}} />);

describe('el campo de importe', () => {
    it('muestra agrupado el decimal que llega del servidor', () => {
        expect(campo('2034800.00')).toContain('value="2.034.800,00"');
    });

    it('muestra igual un importe que la pantalla ya formateó', () => {
        expect(campo('2.034.800,00')).toContain('value="2.034.800,00"');
    });

    it('arranca vacío sin inventar un cero', () => {
        expect(campo('')).toContain('value=""');
    });

    it('lleva el símbolo de la moneda de la pantalla', () => {
        const html = renderToStaticMarkup(
            <EnMoneda moneda="USD">
                <AmountInput value="1500.00" onChange={() => {}} />
            </EnMoneda>,
        );

        expect(html).toContain('US$');
        expect(html).toContain('value="1.500,00"');
    });

    it('conserva el importe en letras debajo', () => {
        const html = renderToStaticMarkup(
            <AmountInput
                value="2034800.00"
                onChange={() => {}}
                words="below"
            />,
        );

        expect(html).toContain('dos millones');
    });
});
