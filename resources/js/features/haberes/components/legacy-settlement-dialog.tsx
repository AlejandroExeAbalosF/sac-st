import { useForm, usePage } from '@inertiajs/react';
import { Archive } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import Money from '@/components/money';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { businessToday, date, money, parseAmount } from '@/lib/format';
import { legacySettlement as registrarPagoAnterior } from '@/routes/haberes/installments';

type Cuota = App.Modules.Haberes.Data.InstallmentListItemData;
type Estado = App.Modules.Haberes.Data.InstallmentLegacyData;
type Opcion = App.Modules.Haberes.Data.LegacyReceiptOptionData;
type Modo = App.Modules.Haberes.Enums.LegacySettlementMode;

type Formulario = {
    mode: Modo;
    incomeNumber: string;
    incomeDate: string;
    incomeAmount: string;
    incomePhoto: File | null;
    orderNumber: string;
    orderDate: string;
    orderAmount: string;
    orderPhoto: File | null;
    expenseNumber: string;
    expenseDate: string;
    expenseAmount: string;
    expensePhoto: File | null;
    paidOn: string;
    paymentMedium: string;
    receiptId: string;
    notes: string;
    confirmDuplicates: boolean;
};

/**
 * Registrar que una cuota de un expediente histórico ya se pagó.
 *
 * **Se copian papeles, no se inventan datos.** Cada uno —recibo de
 * ingreso, Orden de Pago, recibo de egreso— va con el número de talonario
 * y la fecha que tiene impresos, y la foto si está a mano. La numeración
 * del sistema no se toca: esos papeles se buscan en el archivo por su
 * número, no por uno que el sistema les pondría hoy.
 *
 * Dos maneras de haberse pagado, y la pantalla pide lo que cada una tiene:
 * en papel, la fecha y el medio del pago; desde «Pagos anteriores», el
 * recibo del sistema que ya registró ese egreso.
 */
export default function LegacySettlementDialog({
    cuota,
    estado,
    abierto,
    onCerrar,
}: {
    cuota: Cuota;
    estado: Estado;
    abierto: boolean;
    onCerrar: () => void;
}) {
    /*
     * Se leen de la página y no por props: solo este diálogo los mira, y
     * enhebrarlos por la lista y la tarjeta sería pasamanos.
     */
    const { recibosAnteriores, corteHistorico } = usePage()
        .props as unknown as {
        recibosAnteriores: Opcion[];
        corteHistorico: string | null;
    };

    const hoy = businessToday();
    const ingresoCargado =
        estado.documents.find((papel) => papel.kind === 'income_receipt') ??
        null;
    const [conOrden, setConOrden] = useState(false);
    const [conEgreso, setConEgreso] = useState(false);

    const form = useForm<Formulario>({
        mode: 'before_opening',
        incomeNumber: '',
        incomeDate: '',
        // Las cuotas se pagan enteras: el recibo es por el importe de la cuota.
        incomeAmount: money(cuota.expectedAmount, { symbol: false }),
        incomePhoto: null,
        orderNumber: '',
        orderDate: '',
        orderAmount: money(cuota.expectedAmount, { symbol: false }),
        orderPhoto: null,
        expenseNumber: '',
        expenseDate: '',
        expenseAmount: money(cuota.expectedAmount, { symbol: false }),
        expensePhoto: null,
        paidOn: '',
        paymentMedium: 'cash',
        receiptId: '',
        notes: '',
        confirmDuplicates: false,
    });

    const enPapel = form.data.mode === 'before_opening';
    const cerrar = () => {
        form.reset();
        form.clearErrors();
        setConOrden(false);
        setConEgreso(false);
        onCerrar();
    };

    const enviar = (e: React.FormEvent) => {
        e.preventDefault();

        /*
         * Lo que no se cargó no viaja: un papel sin número es un papel que
         * no está, y mandarlo con el importe sugerido lo haría parecer uno.
         */
        form.transform((datos) => ({
            mode: datos.mode,
            ...(ingresoCargado === null
                ? papel(
                      'income',
                      datos.incomeNumber,
                      datos.incomeDate,
                      datos.incomeAmount,
                      datos.incomePhoto,
                  )
                : {}),
            ...(conOrden
                ? papel(
                      'order',
                      datos.orderNumber,
                      datos.orderDate,
                      datos.orderAmount,
                      datos.orderPhoto,
                  )
                : {}),
            ...(enPapel && conEgreso
                ? papel(
                      'expense',
                      datos.expenseNumber,
                      datos.expenseDate,
                      datos.expenseAmount,
                      datos.expensePhoto,
                  )
                : {}),
            ...(enPapel
                ? { paidOn: datos.paidOn, paymentMedium: datos.paymentMedium }
                : { receiptId: datos.receiptId }),
            notes: datos.notes,
            confirmDuplicates: datos.confirmDuplicates,
        }));

        form.post(registrarPagoAnterior(cuota.id).url, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => cerrar(),
        });
    };

    return (
        <Dialog open={abierto} onOpenChange={(v) => !v && cerrar()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        Cuota {cuota.number}: pago histórico
                    </DialogTitle>
                    <DialogDescription>
                        La cuota es de <Money value={cuota.expectedAmount} />.
                        Queda pagada fuera del circuito y el sistema no la
                        vuelve a ofrecer para cobrar ni para pagar.
                    </DialogDescription>
                </DialogHeader>

                {corteHistorico === null ? (
                    <p className="rounded-lg border border-warning-strong/30 bg-warning-soft p-3 text-sm text-warning-strong">
                        La caja de Haberes todavía no tiene apertura. Sin ella
                        no se puede saber si un papel es anterior al sistema:
                        primero hay que abrir los libros.
                    </p>
                ) : (
                    <form
                        id={`form-pago-anterior-${cuota.id}`}
                        onSubmit={enviar}
                        className="grid gap-5"
                    >
                        <p className="text-xs text-muted-foreground">
                            Los papeles tienen que ser anteriores al{' '}
                            {date(corteHistorico)}, el día en que la caja abrió
                            sus libros.
                        </p>
                        {/*
                         * Lo que no es de un campo —la cuota ya se movió,
                         * el haber no está activo— vuelve con esta clave.
                         */}
                        <InputError
                            message={
                                (
                                    form.errors as Partial<
                                        Record<'installment', string>
                                    >
                                ).installment
                            }
                        />

                        <div className="grid gap-2">
                            <Label htmlFor={`modo-${cuota.id}`}>
                                Cómo se pagó
                            </Label>
                            <Select
                                value={form.data.mode}
                                onValueChange={(valor) =>
                                    form.setData('mode', valor as Modo)
                                }
                            >
                                <SelectTrigger id={`modo-${cuota.id}`}>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="before_opening">
                                        En papel, antes de la apertura
                                    </SelectItem>
                                    <SelectItem value="legacy_disbursement">
                                        Desde Pagos anteriores, con recibo del
                                        sistema
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        {ingresoCargado === null ? (
                            <Papel
                                id={`ingreso-${cuota.id}`}
                                titulo="Recibo de ingreso"
                                ayuda="Lo que prueba que el empleador pagó."
                                hoy={hoy}
                                numero={form.data.incomeNumber}
                                fecha={form.data.incomeDate}
                                importe={form.data.incomeAmount}
                                onNumero={(v) =>
                                    form.setData('incomeNumber', v)
                                }
                                onFecha={(v) => form.setData('incomeDate', v)}
                                onImporte={(v) =>
                                    form.setData('incomeAmount', v)
                                }
                                onFoto={(f) => form.setData('incomePhoto', f)}
                                errores={{
                                    numero: form.errors.incomeNumber,
                                    fecha: form.errors.incomeDate,
                                    importe: form.errors.incomeAmount,
                                    foto: form.errors.incomePhoto,
                                }}
                            />
                        ) : (
                            <p className="rounded-lg border bg-muted/30 p-3 text-sm">
                                El recibo de ingreso n.º{' '}
                                <span className="font-mono">
                                    {ingresoCargado.number}
                                </span>{' '}
                                del {date(ingresoCargado.issuedOn)} ya está
                                cargado: se usa ese.
                            </p>
                        )}

                        {enPapel ? (
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor={`pagado-${cuota.id}`}>
                                        Cuándo se le pagó
                                    </Label>
                                    <Input
                                        id={`pagado-${cuota.id}`}
                                        type="date"
                                        max={hoy}
                                        value={form.data.paidOn}
                                        onChange={(e) =>
                                            form.setData(
                                                'paidOn',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <InputError message={form.errors.paidOn} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor={`medio-${cuota.id}`}>
                                        Con qué se le pagó
                                    </Label>
                                    <Select
                                        value={form.data.paymentMedium}
                                        onValueChange={(valor) =>
                                            form.setData('paymentMedium', valor)
                                        }
                                    >
                                        <SelectTrigger id={`medio-${cuota.id}`}>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="cash">
                                                Efectivo
                                            </SelectItem>
                                            <SelectItem value="cheque">
                                                Cheque
                                            </SelectItem>
                                            <SelectItem value="bank">
                                                Transferencia
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <InputError
                                        message={form.errors.paymentMedium}
                                    />
                                </div>
                            </div>
                        ) : (
                            <div className="grid gap-2">
                                <Label htmlFor={`recibo-${cuota.id}`}>
                                    Recibo de Pagos anteriores
                                </Label>
                                {recibosAnteriores.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        No hay pagos de Pagos anteriores a este
                                        beneficiario con importe sin vincular.
                                    </p>
                                ) : (
                                    <Select
                                        value={form.data.receiptId}
                                        onValueChange={(valor) =>
                                            form.setData('receiptId', valor)
                                        }
                                    >
                                        <SelectTrigger
                                            id={`recibo-${cuota.id}`}
                                        >
                                            <SelectValue placeholder="Elegí el recibo" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {recibosAnteriores.map((opcion) => (
                                                <SelectItem
                                                    key={opcion.id}
                                                    value={String(opcion.id)}
                                                >
                                                    {`N.º ${opcion.number} · ${date(opcion.date)} · quedan ${money(opcion.available)}`}
                                                    {opcion.reference
                                                        ? ` · ${opcion.reference}`
                                                        : ''}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                )}
                                <InputError message={form.errors.receiptId} />
                            </div>
                        )}

                        <OptativoPapel
                            activo={conOrden}
                            onActivar={setConOrden}
                            etiqueta="Agregar la Orden de Pago de papel"
                        >
                            <Papel
                                id={`orden-${cuota.id}`}
                                titulo="Orden de Pago"
                                hoy={hoy}
                                numero={form.data.orderNumber}
                                fecha={form.data.orderDate}
                                importe={form.data.orderAmount}
                                onNumero={(v) => form.setData('orderNumber', v)}
                                onFecha={(v) => form.setData('orderDate', v)}
                                onImporte={(v) =>
                                    form.setData('orderAmount', v)
                                }
                                onFoto={(f) => form.setData('orderPhoto', f)}
                                errores={{
                                    numero: form.errors.orderNumber,
                                    fecha: form.errors.orderDate,
                                    importe: form.errors.orderAmount,
                                    foto: form.errors.orderPhoto,
                                }}
                            />
                        </OptativoPapel>

                        {/*
                         * Desde Pagos anteriores el egreso ya tiene su recibo
                         * del sistema: uno de papel diría dos veces lo mismo.
                         */}
                        {enPapel && (
                            <OptativoPapel
                                activo={conEgreso}
                                onActivar={setConEgreso}
                                etiqueta="Agregar el recibo de egreso de papel"
                            >
                                <Papel
                                    id={`egreso-${cuota.id}`}
                                    titulo="Recibo de egreso"
                                    ayuda="El que firmó el beneficiario."
                                    hoy={hoy}
                                    numero={form.data.expenseNumber}
                                    fecha={form.data.expenseDate}
                                    importe={form.data.expenseAmount}
                                    onNumero={(v) =>
                                        form.setData('expenseNumber', v)
                                    }
                                    onFecha={(v) =>
                                        form.setData('expenseDate', v)
                                    }
                                    onImporte={(v) =>
                                        form.setData('expenseAmount', v)
                                    }
                                    onFoto={(f) =>
                                        form.setData('expensePhoto', f)
                                    }
                                    errores={{
                                        numero: form.errors.expenseNumber,
                                        fecha: form.errors.expenseDate,
                                        importe: form.errors.expenseAmount,
                                        foto: form.errors.expensePhoto,
                                    }}
                                />
                            </OptativoPapel>
                        )}

                        <div className="grid gap-2">
                            <Label htmlFor={`notas-pago-anterior-${cuota.id}`}>
                                Observaciones
                                <span className="ml-1 text-xs font-normal text-muted-foreground">
                                    opcional
                                </span>
                            </Label>
                            <Textarea
                                id={`notas-pago-anterior-${cuota.id}`}
                                rows={2}
                                value={form.data.notes}
                                onChange={(e) =>
                                    form.setData('notes', e.target.value)
                                }
                            />
                            <InputError message={form.errors.notes} />
                        </div>

                        {/*
                         * El aviso de un número repetido con otra fecha no
                         * es un error: puede ser otro papel. Se confirma acá,
                         * después de haberlo leído al lado de cada campo.
                         */}
                        {form.errors.confirmDuplicates && (
                            <label className="flex items-start gap-2 rounded-lg border border-warning-strong/30 bg-warning-soft p-3 text-sm text-warning-strong">
                                <Checkbox
                                    className="mt-0.5"
                                    checked={form.data.confirmDuplicates}
                                    onCheckedChange={(v) =>
                                        form.setData(
                                            'confirmDuplicates',
                                            v === true,
                                        )
                                    }
                                />
                                Revisé los avisos: son papeles distintos de los
                                que ya están cargados.
                            </label>
                        )}
                    </form>
                )}

                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={cerrar}>
                        Cancelar
                    </Button>
                    <Button
                        type="submit"
                        form={`form-pago-anterior-${cuota.id}`}
                        disabled={form.processing || corteHistorico === null}
                    >
                        <Archive className="size-4" aria-hidden="true" />
                        Registrar el pago
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** Los cuatro campos de un papel, con el prefijo que espera el servidor. */
function papel(
    prefijo: 'income' | 'order' | 'expense',
    numero: string,
    fecha: string,
    importe: string,
    foto: File | null,
): Record<string, string | File | null> {
    return {
        [`${prefijo}Number`]: numero,
        [`${prefijo}Date`]: fecha,
        [`${prefijo}Amount`]: parseAmount(importe),
        [`${prefijo}Photo`]: foto,
    };
}

/** Un papel que puede no estar: se agrega a pedido. */
function OptativoPapel({
    activo,
    onActivar,
    etiqueta,
    children,
}: {
    activo: boolean;
    onActivar: (activo: boolean) => void;
    etiqueta: string;
    children: React.ReactNode;
}) {
    if (!activo) {
        return (
            <Button
                type="button"
                variant="outline"
                size="sm"
                className="justify-self-start"
                onClick={() => onActivar(true)}
            >
                {etiqueta}
            </Button>
        );
    }

    return (
        <div className="grid gap-2">
            {children}
            <Button
                type="button"
                variant="ghost"
                size="sm"
                className="justify-self-start text-xs"
                onClick={() => onActivar(false)}
            >
                Quitar este papel
            </Button>
        </div>
    );
}

/** Número, fecha, importe y foto de un papel del sistema anterior. */
function Papel({
    id,
    titulo,
    ayuda,
    hoy,
    numero,
    fecha,
    importe,
    onNumero,
    onFecha,
    onImporte,
    onFoto,
    errores,
}: {
    id: string;
    titulo: string;
    ayuda?: string;
    hoy: string;
    numero: string;
    fecha: string;
    importe: string;
    onNumero: (valor: string) => void;
    onFecha: (valor: string) => void;
    onImporte: (valor: string) => void;
    onFoto: (archivo: File | null) => void;
    errores: {
        numero?: string;
        fecha?: string;
        importe?: string;
        foto?: string;
    };
}) {
    return (
        <fieldset className="grid gap-3 rounded-lg border p-3">
            <legend className="px-1 text-sm font-medium">
                {titulo}
                {ayuda && (
                    <span className="ml-1 text-xs font-normal text-muted-foreground">
                        {ayuda}
                    </span>
                )}
            </legend>
            <div className="grid gap-3 sm:grid-cols-3">
                <div className="grid gap-1.5">
                    <Label htmlFor={`${id}-numero`}>N.º de talonario</Label>
                    <Input
                        id={`${id}-numero`}
                        className="font-mono tabular-nums"
                        value={numero}
                        onChange={(e) => onNumero(e.target.value)}
                    />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor={`${id}-fecha`}>Fecha</Label>
                    <Input
                        id={`${id}-fecha`}
                        type="date"
                        max={hoy}
                        value={fecha}
                        onChange={(e) => onFecha(e.target.value)}
                    />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor={`${id}-importe`}>Importe del papel</Label>
                    <Input
                        id={`${id}-importe`}
                        inputMode="decimal"
                        className="text-right font-mono tabular-nums"
                        value={importe}
                        onChange={(e) => onImporte(e.target.value)}
                    />
                </div>
            </div>
            <InputError message={errores.numero} />
            <InputError message={errores.fecha} />
            <InputError message={errores.importe} />
            <div className="grid gap-1.5">
                <Label htmlFor={`${id}-foto`}>
                    Foto
                    <span className="ml-1 text-xs font-normal text-muted-foreground">
                        opcional · JPG, PNG, WEBP o PDF
                    </span>
                </Label>
                <Input
                    id={`${id}-foto`}
                    type="file"
                    accept="image/jpeg,image/png,image/webp,application/pdf"
                    onChange={(e) => onFoto(e.target.files?.[0] ?? null)}
                />
                <InputError message={errores.foto} />
            </div>
        </fieldset>
    );
}
