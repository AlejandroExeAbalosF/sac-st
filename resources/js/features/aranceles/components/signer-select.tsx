import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

export type Firmante = { id: number; name: string; position: string | null };

export const SIN_FIRMANTE = 'none';

/** Opcional: sin firmante, el pie queda para firmar y sellar a mano. */
export default function SignerSelect({
    id,
    firmantes,
    value,
    onChange,
}: {
    id: string;
    firmantes: Firmante[];
    value: string;
    onChange: (valor: string) => void;
}) {
    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>Firma</Label>
            <Select value={value} onValueChange={onChange}>
                <SelectTrigger id={id} className="w-full">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={SIN_FIRMANTE}>
                        Vacía, para firmar y sellar a mano
                    </SelectItem>
                    {firmantes.map((f) => (
                        <SelectItem key={f.id} value={String(f.id)}>
                            {f.name}
                            {f.position ? ` — ${f.position}` : ''}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}
