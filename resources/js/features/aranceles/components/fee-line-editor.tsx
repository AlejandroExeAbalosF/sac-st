import { Trash2 } from 'lucide-react';
import AmountInput from '@/components/amount-input';
import FormRowField from '@/components/form-row-field';
import InputError from '@/components/input-error';
import ReasonFields from '@/components/reason-fields';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import FeeTypeCombobox from '@/features/aranceles/components/fee-type-combobox';
import { importeCalculado, precioEn } from '@/features/aranceles/lib/fee-line';
import { compareAmounts, money, percent } from '@/lib/format';

type Tipo = App.Modules.Aranceles.Data.FeeTypeData;
type Acto = App.Modules.Aranceles.Enums.FeeReasonAct;
type Codigo = App.Modules.Aranceles.Enums.FeeReasonCode;

export type LineaDeCobro = {
    /** Solo para que React distinga los renglones. */
    key: number;
    feeTypeId: number | null;
    quantity: string;
    baseAmount: string;
    /** Vacío: vale lo calculado. */
    amount: string;
    reasonCode: Codigo | '';
    reason: string;
};

type Props = {
    index: number;
    linea: LineaDeCobro;
    types: Tipo[];
    /** La fecha que elige la tarifa. */
    fecha: string;
    canChangeAmount: boolean;
    removable: boolean;
    errors: Record<string, string | undefined>;
    onChange: (cambios: Partial<LineaDeCobro>) => void;
    onRemove: () => void;
};

/** Lo que vale esta línea: lo escrito, o lo calculado si no se escribió nada. */
export function importeDeLinea(
    linea: LineaDeCobro,
    types: Tipo[],
    fecha: string,
): string | null {
    const tipo = types.find((t) => t.id === linea.feeTypeId);

    if (tipo === undefined) {
        return null;
    }

    return linea.amount.trim() !== ''
        ? linea.amount
        : importeCalculado(
              tipo,
              precioEn(tipo, fecha),
              linea.quantity,
              linea.baseAmount,
          );
}

/**
 * Un renglón del cobro. El precio sale del catálogo, según la fecha. Quien tiene
 * el permiso puede escribir otro importe: si difiere, pide el motivo.
 */
export default function FeeLineEditor({
    index,
    linea,
    types,
    fecha,
    canChangeAmount,
    removable,
    errors,
    onChange,
    onRemove,
}: Props) {
    const tipo = types.find((t) => t.id === linea.feeTypeId);
    const precio = tipo === undefined ? null : precioEn(tipo, fecha);
    const porcentual = tipo?.calculationMode === 'percentage';
    const calculado =
        tipo === undefined
            ? null
            : importeCalculado(tipo, precio, linea.quantity, linea.baseAmount);
    const cambiado =
        linea.amount.trim() !== '' &&
        (calculado === null || compareAmounts(linea.amount, calculado) !== 0);
    const error = (campo: string) => errors[`items.${index}.${campo}`];

    return (
        <div className="grid grid-cols-1 gap-3 rounded-lg border bg-card p-3">
            <div className="grid grid-cols-1 gap-1.5">
                <div className="flex items-center justify-between gap-2">
                    <Label htmlFor={`linea-${linea.key}-tipo`}>
                        Arancel {index + 1}
                    </Label>
                    {removable && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={onRemove}
                        >
                            <Trash2 className="size-4" aria-hidden="true" />
                            Quitar
                        </Button>
                    )}
                </div>
                <FeeTypeCombobox
                    id={`linea-${linea.key}-tipo`}
                    types={types}
                    value={linea.feeTypeId}
                    fecha={fecha}
                    invalid={Boolean(error('feeTypeId'))}
                    onChange={(elegido) =>
                        onChange({
                            feeTypeId: elegido.id,
                            quantity: '1',
                            baseAmount: '',
                            amount: '',
                            reasonCode: '',
                            reason: '',
                        })
                    }
                />
                <InputError message={error('feeTypeId')} />
            </div>

            {tipo !== undefined && (
                <>
                    <div className="grid gap-3 sm:grid-cols-3">
                        {porcentual ? (
                            <FormRowField
                                htmlFor={`linea-${linea.key}-base`}
                                label="Monto del acuerdo"
                            >
                                <AmountInput
                                    id={`linea-${linea.key}-base`}
                                    value={linea.baseAmount}
                                    onChange={(valor) =>
                                        onChange({ baseAmount: valor })
                                    }
                                />
                                <p className="text-xs text-muted-foreground">
                                    Tasa: {percent(precio?.rate)}
                                </p>
                                <InputError message={error('baseAmount')} />
                            </FormRowField>
                        ) : (
                            <FormRowField
                                htmlFor={`linea-${linea.key}-cantidad`}
                                label={
                                    tipo.unitLabel
                                        ? `Cantidad (${tipo.unitLabel})`
                                        : 'Cantidad'
                                }
                            >
                                <Input
                                    id={`linea-${linea.key}-cantidad`}
                                    inputMode="numeric"
                                    value={linea.quantity}
                                    onChange={(e) =>
                                        onChange({ quantity: e.target.value })
                                    }
                                />
                                <p className="text-xs text-muted-foreground">
                                    Precio: {money(precio?.unitPrice)}
                                </p>
                                <InputError message={error('quantity')} />
                            </FormRowField>
                        )}

                        <FormRowField
                            htmlFor={`linea-${linea.key}-calculado`}
                            label="Calculado"
                        >
                            <output
                                id={`linea-${linea.key}-calculado`}
                                className="flex min-h-9 items-center text-sm tabular-nums max-sm:min-h-11"
                            >
                                {money(calculado)}
                            </output>
                        </FormRowField>

                        {canChangeAmount && (
                            <FormRowField
                                htmlFor={`linea-${linea.key}-importe`}
                                label="Importe a cobrar (opcional)"
                            >
                                <AmountInput
                                    id={`linea-${linea.key}-importe`}
                                    value={linea.amount}
                                    onChange={(valor) =>
                                        onChange({ amount: valor })
                                    }
                                />
                                <InputError message={error('amount')} />
                            </FormRowField>
                        )}
                    </div>

                    {!canChangeAmount && (
                        <InputError message={error('amount')} />
                    )}

                    {precio === null && (
                        <p className="text-sm text-destructive-strong">
                            Este arancel no tenía precio en esa fecha.
                        </p>
                    )}

                    {cambiado && (
                        <ReasonFields<Acto, Codigo>
                            act="amount_change"
                            idPrefix={`linea-${linea.key}`}
                            hint="el importe es distinto del calculado"
                            code={linea.reasonCode}
                            detail={linea.reason}
                            maxLength={300}
                            detailRequired={
                                linea.reasonCode === 'exception' ||
                                linea.reasonCode === 'other'
                            }
                            onCodeChange={(codigo) =>
                                onChange({ reasonCode: codigo })
                            }
                            onDetailChange={(detalle) =>
                                onChange({ reason: detalle })
                            }
                            codeError={error('reasonCode')}
                            detailError={error('reason')}
                        />
                    )}
                </>
            )}
        </div>
    );
}
