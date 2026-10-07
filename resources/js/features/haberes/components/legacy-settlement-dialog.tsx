import { useForm, usePage } from '@inertiajs/react';
import { Archive, ArrowLeft } from 'lucide-react';
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
import { businessToday, date } from '@/lib/format';
import { legacySettlement as registrarPagoAnterior } from '@/routes/haberes/installments';

type Cuota = App.Modules.Haberes.Data.InstallmentListItemData;
type Estado = App.Modules.Haberes.Data.InstallmentLegacyData;

type Formulario = {
    incomeNumber: string;
    incomeDate: string;
    incomePhoto: File | null;
    orderNumber: string;
    orderDate: string;
    orderPhoto: File | null;
    expenseNumber: string;
    expenseDate: string;
    expensePhoto: File | null;
    paidOn: string;
    paymentMedium: string;
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
 * La cuota se pagó antes de que la caja abriera sus libros: además de los
 * papeles se carga cuándo y con qué se le pagó al beneficiario.
 */
export default function LegacySettlementDialog({
    cuota,
    estado,
    abierto,
    onCerrar,
    onVolver,
}: {
    cuota: Cuota;
    estado: Estado;
    abierto: boolean;
    onCerrar: () => void;
    /**
     * Volver a la pregunta del formulario guiado. Sin esto el diálogo se
     * abre solo, sin el «Paso 2 de 2».
     */
    onVolver?: () => void;
}) {
    /*
     * Se lee de la página y no por props: solo este diálogo lo mira, y
     * enhebrarlo por la lista y la tarjeta sería pasamanos.
     */
    const { corteHistorico } = usePage().props as unknown as {
        corteHistorico: string | null;
    };

    const hoy = businessToday();
    const ingresoCargado =
        estado.documents.find((papel) => papel.kind === 'income_receipt') ??
        null;
    const [conOrden, setConOrden] = useState(false);
    const [conEgreso, setConEgreso] = useState(false);

    const form = useForm<Formulario>({
        incomeNumber: '',
        incomeDate: '',
        incomePhoto: null,
        orderNumber: '',
        orderDate: '',
        orderPhoto: null,
        expenseNumber: '',
        expenseDate: '',
        expensePhoto: null,
        paidOn: '',
        paymentMedium: 'cash',
        notes: '',
        confirmDuplicates: false,
    });

    const limpiar = () => {
        form.reset();
        form.clearErrors();
        setConOrden(false);
        setConEgreso(false);
    };

    const cerrar = () => {
        limpiar();
        onCerrar();
    };

    const enviar = (e: React.FormEvent) => {
        e.preventDefault();

        /*
         * Lo que no se cargó no viaja: un papel sin número es un papel que
         * no está, y mandarlo con el importe sugerido lo haría parecer uno.
         */
        form.transform((datos) => ({
            ...(ingresoCargado === null
                ? papel(
                      'income',
                      datos.incomeNumber,
                      datos.incomeDate,
                      datos.incomePhoto,
                  )
                : {}),
            ...(conOrden
                ? papel(
                      'order',
                      datos.orderNumber,
                      datos.orderDate,
                      datos.orderPhoto,
                  )
                : {}),
            ...(conEgreso
                ? papel(
                      'expense',
                      datos.expenseNumber,
                      datos.expenseDate,
                      datos.expensePhoto,
                  )
                : {}),
            paidOn: datos.paidOn,
            paymentMedium: datos.paymentMedium,
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
                    {onVolver !== undefined && (
                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                            Paso 2 de 2
                        </p>
                    )}
                    <DialogTitle>
                        Cuota {cuota.number}: pago histórico
                    </DialogTitle>
                    <DialogDescription>
                        La cuota es de <Money value={cuota.expectedAmount} />.
                        Se pagó antes de que la caja abriera sus libros: queda
                        registrada como pagada y el sistema no la vuelve a
                        ofrecer para cobrar ni para pagar.
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

                        {ingresoCargado === null ? (
                            <Papel
                                id={`ingreso-${cuota.id}`}
                                titulo="Recibo de ingreso"
                                ayuda="Lo que prueba que el empleador pagó."
                                hoy={hoy}
                                numero={form.data.incomeNumber}
                                fecha={form.data.incomeDate}
                                importe={cuota.expectedAmount}
                                onNumero={(v) =>
                                    form.setData('incomeNumber', v)
                                }
                                onFecha={(v) => form.setData('incomeDate', v)}
                                onFoto={(f) => form.setData('incomePhoto', f)}
                                errores={{
                                    numero: form.errors.incomeNumber,
                                    fecha: form.errors.incomeDate,
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
                                        form.setData('paidOn', e.target.value)
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
                                importe={cuota.expectedAmount}
                                onNumero={(v) => form.setData('orderNumber', v)}
                                onFecha={(v) => form.setData('orderDate', v)}
                                onFoto={(f) => form.setData('orderPhoto', f)}
                                errores={{
                                    numero: form.errors.orderNumber,
                                    fecha: form.errors.orderDate,
                                    foto: form.errors.orderPhoto,
                                }}
                            />
                        </OptativoPapel>

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
                                importe={cuota.expectedAmount}
                                onNumero={(v) =>
                                    form.setData('expenseNumber', v)
                                }
                                onFecha={(v) => form.setData('expenseDate', v)}
                                onFoto={(f) => form.setData('expensePhoto', f)}
                                errores={{
                                    numero: form.errors.expenseNumber,
                                    fecha: form.errors.expenseDate,
                                    foto: form.errors.expensePhoto,
                                }}
                            />
                        </OptativoPapel>

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
                    {onVolver !== undefined && (
                        <Button
                            type="button"
                            variant="ghost"
                            className="sm:mr-auto"
                            onClick={() => {
                                limpiar();
                                onVolver();
                            }}
                        >
                            <ArrowLeft className="size-4" aria-hidden="true" />
                            Volver a la pregunta
                        </Button>
                    )}
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

/**
 * Los campos de un papel, con el prefijo que espera el servidor.
 *
 * El importe no viaja: las cuotas se pagan enteras, así que cada papel es
 * por la cuota y lo pone el servidor.
 */
function papel(
    prefijo: 'income' | 'order' | 'expense',
    numero: string,
    fecha: string,
    foto: File | null,
): Record<string, string | File | null> {
    return {
        [`${prefijo}Number`]: numero,
        [`${prefijo}Date`]: fecha,
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

/**
 * Número, fecha y foto de un papel del sistema anterior, con su importe.
 *
 * El importe se muestra y no se edita: es el de la cuota, porque se pagan
 * enteras. Un campo para escribirlo solo servía para equivocarse.
 */
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
    onFoto,
    errores,
}: {
    id: string;
    titulo: string;
    ayuda?: string;
    hoy: string;
    numero: string;
    fecha: string;
    /** El de la cuota. */
    importe: string;
    onNumero: (valor: string) => void;
    onFecha: (valor: string) => void;
    onFoto: (archivo: File | null) => void;
    errores: {
        numero?: string;
        fecha?: string;
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
                    <span className="text-sm leading-none font-medium">
                        Importe
                    </span>
                    <p
                        className="flex h-9 items-center justify-end rounded-md border bg-muted/40 px-3 text-sm"
                        title="Es el de la cuota: se pagan enteras"
                    >
                        <Money value={importe} />
                    </p>
                </div>
            </div>
            <InputError message={errores.numero} />
            <InputError message={errores.fecha} />
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
