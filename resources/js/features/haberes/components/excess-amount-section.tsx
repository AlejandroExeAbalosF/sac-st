import { ChevronRight } from 'lucide-react';
import AmountInput from '@/components/amount-input';
import InputError from '@/components/input-error';
import ReasonFields from '@/components/reason-fields';
import { Label } from '@/components/ui/label';
import { money } from '@/lib/format';
import { cn } from '@/lib/utils';

export default function ExcessAmountSection({
    titulo,
    etiquetaTotal,
    cuota,
    abierto,
    onAbrir,
    total,
    onTotal,
    excedente,
    incompleto,
    motivo,
    detalle,
    onMotivo,
    onDetalle,
    errores,
}: {
    titulo: string;
    etiquetaTotal: string;
    cuota: { id: number; amountDue: string };
    abierto: boolean;
    onAbrir: (abierto: boolean) => void;
    total: string;
    onTotal: (valor: string) => void;
    excedente: string | null;
    /** Abierta sin un total mayor que lo que se debe: no se puede cobrar así. */
    incompleto: boolean;
    motivo: App.Modules.Haberes.Enums.HaberesReasonCode | '';
    detalle: string;
    onMotivo: (codigo: App.Modules.Haberes.Enums.HaberesReasonCode) => void;
    onDetalle: (texto: string) => void;
    errores: { total?: string; motivo?: string; detalle?: string };
}) {
    return (
        <div className="rounded-md border bg-card">
            <button
                type="button"
                onClick={() => onAbrir(!abierto)}
                aria-expanded={abierto}
                className="flex w-full items-center gap-1.5 px-3 py-2 text-left text-xs font-medium hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <ChevronRight
                    className={cn(
                        'size-3.5 transition-transform motion-reduce:transition-none',
                        abierto && 'rotate-90',
                    )}
                    aria-hidden="true"
                />
                {titulo}
            </button>

            {abierto && (
                <div className="space-y-3 border-t px-3 py-3">
                    <div className="space-y-1.5">
                        <Label htmlFor={`total-mayor-${cuota.id}`}>
                            {etiquetaTotal}
                        </Label>
                        <AmountInput
                            id={`total-mayor-${cuota.id}`}
                            value={total}
                            onChange={onTotal}
                            aria-invalid={Boolean(errores.total) || incompleto}
                            words="below"
                        />
                        <InputError
                            message={
                                errores.total ??
                                (incompleto
                                    ? 'Escribí un total mayor que lo que se debe, o plegá esta sección para usar el importe de la cuota.'
                                    : undefined)
                            }
                        />
                    </div>

                    <dl className="grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <dt className="text-xs text-field-label">
                                Importe a abonar
                            </dt>
                            <dd className="tabular-nums">
                                {money(cuota.amountDue)}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-field-label">
                                Excedente recibido
                            </dt>
                            <dd className="tabular-nums">
                                {excedente === null ? '—' : money(excedente)}
                            </dd>
                        </div>
                    </dl>

                    {excedente !== null && (
                        <ReasonFields
                            act="installment_excess"
                            code={motivo}
                            detail={detalle}
                            onCodeChange={onMotivo}
                            onDetailChange={onDetalle}
                            codeError={errores.motivo}
                            detailError={errores.detalle}
                            idPrefix={`motivo-importe-mayor-${cuota.id}`}
                            maxLength={300}
                        />
                    )}
                </div>
            )}
        </div>
    );
}
