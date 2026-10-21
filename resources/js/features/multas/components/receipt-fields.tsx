import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { Firmante } from '@/features/multas/components/collect-payment-dialog';
import { FieldError } from '@/features/multas/components/form-dialog';

export const SIN_FIRMANTE = 'ninguno';

/**
 * Lo que el operador elige del recibo que sale: la hoja del talonario, si lo
 * escribió a mano, y la aclaración al pie.
 *
 * Lo comparten los actos que emiten un recibo de Multas fuera del cobro en
 * caja: verificar un pago por el banco y cobrar o resolver un recurso.
 */
export default function ReceiptFields({
    idPrefix,
    talonarioNumber,
    onTalonarioChange,
    signedById,
    onSignedByChange,
    firmantes,
    errors,
}: {
    idPrefix: string;
    talonarioNumber: string;
    onTalonarioChange: (valor: string) => void;
    signedById: string;
    onSignedByChange: (valor: string) => void;
    firmantes: Firmante[];
    errors: { talonarioNumber?: string; signedById?: string };
}) {
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <div>
                <Label htmlFor={`${idPrefix}-talonario`}>
                    N.º de talonario (opcional)
                </Label>
                <Input
                    id={`${idPrefix}-talonario`}
                    className="mt-1 font-mono"
                    value={talonarioNumber}
                    maxLength={20}
                    onChange={(e) => onTalonarioChange(e.target.value)}
                    aria-invalid={Boolean(errors.talonarioNumber)}
                />
                <FieldError message={errors.talonarioNumber} />
            </div>
            {firmantes.length > 0 && (
                <div>
                    <Label htmlFor={`${idPrefix}-firmante`}>
                        Aclaración al pie
                    </Label>
                    <Select value={signedById} onValueChange={onSignedByChange}>
                        <SelectTrigger
                            id={`${idPrefix}-firmante`}
                            className="mt-1"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={SIN_FIRMANTE}>
                                Sin aclaración impresa
                            </SelectItem>
                            {firmantes.map((f) => (
                                <SelectItem key={f.id} value={String(f.id)}>
                                    {f.name}
                                    {f.position ? ` · ${f.position}` : ''}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <FieldError message={errors.signedById} />
                </div>
            )}
        </div>
    );
}

/** Lo que el servidor espera de esos dos campos. */
export function receiptPayload(talonarioNumber: string, signedById: string) {
    return {
        talonarioNumber: talonarioNumber.trim() || null,
        signedById: signedById === SIN_FIRMANTE ? null : Number(signedById),
    };
}
