import { money, percent } from '@/lib/format';

/** Lo que vale un precio del catálogo, según el modo de su tipo. */
export function feeTypeValue(
    mode: App.Modules.Aranceles.Enums.FeeCalculationMode,
    price: { unitPrice: string | null; rate: string | null } | null,
): string {
    if (price === null) {
        return '—';
    }

    return mode === 'percentage' ? percent(price.rate) : money(price.unitPrice);
}
