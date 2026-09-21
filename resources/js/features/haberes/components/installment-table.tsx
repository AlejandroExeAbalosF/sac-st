import { Plus, Trash2 } from 'lucide-react';
import { Fragment } from 'react';
import AmountInput from '@/components/amount-input';
import Money from '@/components/money';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { compareAmounts, money, parseAmount, sumAmounts } from '@/lib/format';
import { cn } from '@/lib/utils';
import { nuevaCuota } from '../types';
import type { EtiquetaOption, HaberDraft, InstallmentDraft } from '../types';

type Props = {
    haber: HaberDraft;
    etiquetas: EtiquetaOption[];
    onChange: (installments: InstallmentDraft[]) => void;
    errors: Record<string, string>;
};

/**
 * Medio previsto de la cuota.
 *
 * Es una anticipación del circuito, no una autorización: el DER es
 * explícito en que el medio efectivo lo fija la primera recepción
 * vinculada, y una segunda con medio distinto se rechaza porque haría
 * ambiguo el medio impreso en el recibo.
 */
const MEDIOS = [
    { value: 'cash', label: 'Efectivo' },
    { value: 'bank', label: 'Depósito en cuenta' },
    { value: 'cheque', label: 'Cheque' },
] as const;

/**
 * Las cuotas de un haber.
 *
 * Cada importe se carga a mano. El sistema NO reparte el total en partes
 * iguales: el DER es explícito en que los importes vienen determinados en
 * el expediente y pueden diferir entre sí. El formulario del área inducía
 * lo contrario con un campo de «cantidad» que dividía solo.
 *
 * La suma corriente se calcula con aritmética exacta de centavos y se
 * compara contra el importe del haber en el momento, no al guardar.
 */
export default function InstallmentTable({
    haber,
    etiquetas,
    onChange,
    errors,
}: Props) {
    const pagoUnico = haber.expectedInstallmentCount === 1;

    const suma = sumAmounts(haber.installments.map((c) => c.amount));
    const totalIngresado = parseAmount(haber.assignedAmount);
    const total = totalIngresado || '0.00';
    const comparacion = totalIngresado
        ? compareAmounts(suma, totalIngresado)
        : null;
    const importesCompletos = haber.installments.every(
        (cuota) => parseAmount(cuota.amount) !== '',
    );
    const coincide = comparacion === 0 && importesCompletos;
    const faltan = haber.expectedInstallmentCount - haber.installments.length;
    const completas = faltan <= 0;
    const resto = sumAmounts([total, `-${suma.replace('-', '')}`]);

    /*
     * Cargar la diferencia a mano es tipear un numero que el sistema ya
     * sabe, y es donde entra el cero de mas. El boton lo aplica a la ultima
     * cuota, que es la que se esta completando cuando la suma queda corta.
     */
    const asignarResto = () => {
        const ultima = haber.installments.at(-1);

        if (!ultima) {
            return;
        }

        actualizar(ultima.key, {
            amount: money(sumAmounts([ultima.amount, resto]), {
                symbol: false,
            }),
        });
    };

    const actualizar = (key: string, cambios: Partial<InstallmentDraft>) =>
        onChange(
            haber.installments.map((c) =>
                c.key === key ? { ...c, ...cambios } : c,
            ),
        );

    const agregar = () =>
        onChange([
            ...haber.installments,
            nuevaCuota(haber.installments.length + 1),
        ]);

    const quitar = (key: string) =>
        onChange(
            haber.installments
                .filter((c) => c.key !== key)
                .map((c, i) => ({ ...c, number: i + 1 })),
        );

    const errorDe = (index: number, field: keyof InstallmentDraft) =>
        errors[`installments.${index}.${field}`];

    return (
        <div>
            <div className="mb-2 flex items-center gap-2">
                <h4 className="text-xs font-semibold tracking-wide text-primary uppercase">
                    {pagoUnico ? 'Pago único' : 'Cuotas con importe conocido'}
                </h4>
                {!pagoUnico && (
                    <span className="text-xs text-muted-foreground">
                        {faltan > 0
                            ? `${haber.installments.length} de ${haber.expectedInstallmentCount} · faltan ${faltan} por definir`
                            : `${haber.installments.length} de ${haber.expectedInstallmentCount}`}
                    </span>
                )}
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className={cn('ml-auto', pagoUnico && 'hidden')}
                    onClick={agregar}
                    disabled={
                        haber.installments.length >=
                        haber.expectedInstallmentCount
                    }
                >
                    <Plus className="size-3.5" aria-hidden="true" />
                    Agregar cuota
                </Button>
            </div>

            <p className="mb-2 text-xs text-muted-foreground sm:hidden">
                Deslizá la tabla hacia los lados para completar todos los datos
                de la cuota.
            </p>

            <div className="overflow-x-auto rounded-md border bg-card">
                <table className="w-full min-w-[56rem] text-left text-sm">
                    <thead>
                        <tr className="border-b bg-muted/50 text-xs tracking-wide text-field-label uppercase">
                            <th
                                scope="col"
                                className="w-14 px-2 py-1.5 font-medium"
                            >
                                N°
                            </th>
                            <th
                                scope="col"
                                className="w-40 px-2 py-1.5 font-medium"
                            >
                                Importe
                            </th>
                            <th
                                scope="col"
                                className="w-40 px-2 py-1.5 font-medium"
                            >
                                Medio previsto
                            </th>
                            <th
                                scope="col"
                                className="w-40 px-2 py-1.5 font-medium"
                            >
                                Etiqueta
                            </th>
                            <th scope="col" className="px-2 py-1.5 font-medium">
                                Concepto
                            </th>
                            <th scope="col" className="w-10 px-2 py-1.5">
                                <span className="sr-only">Quitar</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {haber.installments.map((cuota, index) => (
                            <Fragment key={cuota.key}>
                                <tr>
                                    <td className="px-2 py-1.5 text-center align-top font-mono tabular-nums">
                                        {cuota.number}
                                        {errorDe(index, 'number') && (
                                            <p className="mt-1 text-[0.6875rem] text-destructive-strong">
                                                {errorDe(index, 'number')}
                                            </p>
                                        )}
                                    </td>
                                    <td className="px-2 py-1.5 align-top">
                                        <AmountInput
                                            value={cuota.amount}
                                            onChange={(amount) =>
                                                actualizar(cuota.key, {
                                                    amount,
                                                })
                                            }
                                            words="tooltip"
                                            aria-label={`Importe de la cuota ${cuota.number}`}
                                            aria-invalid={Boolean(
                                                errorDe(index, 'amount'),
                                            )}
                                            aria-describedby={
                                                errorDe(index, 'amount')
                                                    ? `${cuota.key}-amount-error`
                                                    : undefined
                                            }
                                            className="h-11 sm:h-9"
                                            placeholder="0,00"
                                            required
                                        />
                                        {errorDe(index, 'amount') && (
                                            <p
                                                id={`${cuota.key}-amount-error`}
                                                className="mt-1 text-[0.6875rem] text-destructive-strong"
                                            >
                                                {errorDe(index, 'amount')}
                                            </p>
                                        )}
                                    </td>
                                    <td className="px-2 py-1.5 align-top">
                                        {/*
                                         * Sin «Sin definir»: el medio pasó a
                                         * ser obligatorio. Una cuota sin él
                                         * no dice si se cobra por mostrador
                                         * o si se espera un depósito, y de
                                         * eso depende el circuito entero.
                                         * Ofrecer la opción sería ofrecer
                                         * algo que el guardado rechaza.
                                         */}
                                        <Select
                                            value={cuota.expectedMedium ?? ''}
                                            onValueChange={(selected) =>
                                                actualizar(cuota.key, {
                                                    expectedMedium:
                                                        selected as InstallmentDraft['expectedMedium'],
                                                })
                                            }
                                        >
                                            <SelectTrigger
                                                aria-label={`Medio previsto de la cuota ${cuota.number}`}
                                                aria-invalid={Boolean(
                                                    errorDe(
                                                        index,
                                                        'expectedMedium',
                                                    ),
                                                )}
                                                className="h-11 w-full bg-card sm:h-9"
                                            >
                                                <SelectValue placeholder="Elegí el medio" />
                                            </SelectTrigger>
                                            <SelectContent align="start">
                                                {MEDIOS.map((medio) => (
                                                    <SelectItem
                                                        key={medio.value}
                                                        value={medio.value}
                                                    >
                                                        {medio.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {errorDe(index, 'expectedMedium') && (
                                            <p className="mt-1 text-[0.6875rem] text-destructive-strong">
                                                {errorDe(
                                                    index,
                                                    'expectedMedium',
                                                )}
                                            </p>
                                        )}
                                    </td>
                                    <td className="px-2 py-1.5 align-top">
                                        <Select
                                            value={
                                                cuota.managementLabelId === null
                                                    ? 'none'
                                                    : String(
                                                          cuota.managementLabelId,
                                                      )
                                            }
                                            onValueChange={(selected) =>
                                                actualizar(cuota.key, {
                                                    managementLabelId:
                                                        selected === 'none'
                                                            ? null
                                                            : Number(selected),
                                                })
                                            }
                                        >
                                            <SelectTrigger
                                                aria-label={`Etiqueta de la cuota ${cuota.number}`}
                                                aria-invalid={Boolean(
                                                    errorDe(
                                                        index,
                                                        'managementLabelId',
                                                    ),
                                                )}
                                                className="h-11 w-full bg-card sm:h-9"
                                            >
                                                <SelectValue placeholder="Sin etiqueta" />
                                            </SelectTrigger>
                                            <SelectContent align="start">
                                                <SelectItem value="none">
                                                    Sin etiqueta
                                                </SelectItem>
                                                {etiquetas.map((etiqueta) => (
                                                    <SelectItem
                                                        key={etiqueta.id}
                                                        value={String(
                                                            etiqueta.id,
                                                        )}
                                                    >
                                                        {etiqueta.code}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {errorDe(
                                            index,
                                            'managementLabelId',
                                        ) && (
                                            <p className="mt-1 text-[0.6875rem] text-destructive-strong">
                                                {errorDe(
                                                    index,
                                                    'managementLabelId',
                                                )}
                                            </p>
                                        )}
                                    </td>
                                    <td className="px-2 py-1.5 align-top">
                                        {/*
                                         * Vacío hereda el concepto del haber, que
                                         * es el caso normal. Se completa solo
                                         * cuando esta cuota difiere — el caso
                                         * Tinte, con dos conceptos distintos.
                                         */}
                                        <input
                                            type="text"
                                            value={cuota.concept}
                                            aria-label={`Concepto propio de la cuota ${cuota.number}`}
                                            onChange={(e) =>
                                                actualizar(cuota.key, {
                                                    concept: e.target.value,
                                                })
                                            }
                                            maxLength={255}
                                            aria-invalid={Boolean(
                                                errorDe(index, 'concept'),
                                            )}
                                            placeholder="El mismo del haber"
                                            className="h-11 w-full rounded-md border bg-card px-2.5 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring aria-invalid:border-destructive sm:h-9"
                                        />
                                        {errorDe(index, 'concept') && (
                                            <p className="mt-1 text-[0.6875rem] text-destructive-strong">
                                                {errorDe(index, 'concept')}
                                            </p>
                                        )}
                                    </td>
                                    <td className="px-2 py-1.5 align-top">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="size-11 sm:size-9"
                                            onClick={() => quitar(cuota.key)}
                                            disabled={
                                                haber.installments.length <= 1
                                            }
                                            aria-label={`Quitar la cuota ${cuota.number}`}
                                        >
                                            <Trash2
                                                className="size-4"
                                                aria-hidden="true"
                                            />
                                        </Button>
                                    </td>
                                </tr>

                                {/*
                                 * Las observaciones van en su propio
                                 * renglón y no en una séptima columna: son
                                 * texto libre y de largo impredecible, y
                                 * meterlas en la grilla apretaría las
                                 * columnas que sí se leen de un vistazo.
                                 */}
                                <tr className="border-b last:border-0">
                                    <td />
                                    <td colSpan={5} className="px-2 pb-2">
                                        <input
                                            type="text"
                                            value={cuota.notes}
                                            aria-label={`Observaciones de la cuota ${cuota.number}`}
                                            onChange={(e) =>
                                                actualizar(cuota.key, {
                                                    notes: e.target.value,
                                                })
                                            }
                                            maxLength={1000}
                                            aria-invalid={Boolean(
                                                errorDe(index, 'notes'),
                                            )}
                                            placeholder="Observaciones de esta cuota (no salen en el comprobante)"
                                            className="h-11 w-full rounded-md border border-dashed bg-transparent px-2.5 text-xs outline-none placeholder:text-muted-foreground focus-visible:border-solid focus-visible:bg-card focus-visible:ring-2 focus-visible:ring-ring aria-invalid:border-destructive sm:h-8"
                                        />
                                        {errorDe(index, 'notes') && (
                                            <p className="mt-1 text-[0.6875rem] text-destructive-strong">
                                                {errorDe(index, 'notes')}
                                            </p>
                                        )}
                                    </td>
                                </tr>
                            </Fragment>
                        ))}
                    </tbody>
                </table>
            </div>

            {/*
             * El control que importa: mientras falten cuotas por definir la
             * suma puede ser menor, pero nunca mayor. Cuando ya están todas
             * las previstas, tiene que dar exacto.
             */}
            <p
                className={cn(
                    'mt-2 flex flex-wrap items-baseline gap-x-2 text-xs',
                    comparacion === 1
                        ? 'text-destructive-strong'
                        : comparacion !== null && completas && comparacion !== 0
                          ? 'text-warning-strong'
                          : 'text-muted-foreground',
                )}
            >
                <span>Suma de cuotas</span>
                <Money value={suma} className="text-sm" />
                <span>de</span>
                <Money value={total} className="text-sm" />
                {comparacion === 1 && (
                    <span className="font-medium">
                        — supera el importe del haber
                    </span>
                )}
                {coincide && (
                    <span className="font-medium text-success-strong">
                        — coincide
                    </span>
                )}
                {comparacion === -1 && completas && (
                    <>
                        <span className="font-medium">
                            — faltan <Money value={resto} /> para completar el
                            haber
                        </span>
                        <Button
                            type="button"
                            variant="link"
                            size="sm"
                            className="h-auto p-0 text-xs"
                            onClick={asignarResto}
                        >
                            Asignar a la cuota{' '}
                            {haber.installments.at(-1)?.number}
                        </Button>
                    </>
                )}
            </p>

            {errors.installments && (
                <p className="mt-1 text-xs text-destructive-strong">
                    {errors.installments}
                </p>
            )}
        </div>
    );
}
