import type { ReactNode } from 'react';
import { Label } from '@/components/ui/label';

type Props = {
    htmlFor: string;
    label: ReactNode;
    children: ReactNode;
};

/** Hijo directo de una grilla. Comparte la altura de las etiquetas con los campos de su fila. */
export default function FormRowField({ htmlFor, label, children }: Props) {
    return (
        <div className="row-span-2 grid min-w-0 grid-rows-subgrid gap-y-1.5">
            <Label htmlFor={htmlFor}>{label}</Label>
            <div className="grid min-w-0 content-start gap-1.5">{children}</div>
        </div>
    );
}
