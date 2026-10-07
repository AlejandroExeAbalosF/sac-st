import { useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, PiggyBank } from 'lucide-react';
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
import {
    businessToday,
    compareAmounts,
    date,
    money,
    parseAmount,
    subtractAmounts,
    sumAmounts,
} from '@/lib/format';
import { legacyFunds as apartarFondos } from '@/routes/haberes/installments';

type Cuota = App.Modules.Haberes.Data.InstallmentListItemData;
type Papel = App.Modules.Haberes.Data.LegacyDocumentData;
type Opciones = App.Modules.Haberes.Data.LegacyFundsOptionsData;

/**
 * El papel del cheque que se identifica ahora. Sin importe: es lo que le
 * falta a la cuota, y lo fija el servidor.
 */
type ChequeNuevo = {
    number: string;
    bank: string;
    issueDate: string;
};

type Formulario = {
    medium: string;
    bankAccountId: string;
    /**
     * El importe de cada cheque elegido, por su id. Se aparta entero: el
     * importe es el del cheque y no se edita.
     */
    cheques: Record<number, string>;
    /**
     * Un cheque que la apertura declaró sin detallar y se identifica ahora,
     * con los datos del papel. `null` mientras no se carga.
     */
    chequeNuevo: ChequeNuevo | null;
    incomeNumber: string;
    incomeDate: string;
    incomePhoto: File | null;
    idempotencyKey: string;
    confirmDuplicates: boolean;
};

/**
 * Apartar del saldo del sistema anterior la plata de una cuota.
 *
 * Es para el expediente histórico que todavía tiene plata en custodia. La
 * plata no se mueve: el efectivo sigue en el cajón, el cheque en la
 * cartera y el depósito directo en la cuenta. Lo que cambia es de quién
 * es, y desde ahí la cuota sigue el circuito de siempre.
 *
 * **No hay ningún campo de importe**: el efectivo y el depósito son por la
 * cuota entera, y un cheque se aparta entero —es un papel que se entrega o
 * se deposita completo—. Si la cuota se cubre con varios cheques, se eligen
 * todos, y uno identificado ahora cubre lo que falte.
 */
export default function LegacyFundsDialog({
    cuota,
    reciboDePapel,
    abierto,
    onCerrar,
    onVolver,
}: {
    cuota: Cuota;
    /** El recibo de papel que la cuota ya tiene, si se apartó antes y se liberó. */
    reciboDePapel: Papel | null;
    abierto: boolean;
    onCerrar: () => void;
    /**
     * Volver a la pregunta del formulario guiado. Sin esto el diálogo se
     * abre solo, sin el «Paso 2 de 2».
     */
    onVolver?: () => void;
}) {
    const { fondosAnteriores, corteHistorico } = usePage().props as unknown as {
        fondosAnteriores: Opciones | null;
        corteHistorico: string | null;
    };

    const hoy = businessToday();
    const [clave] = useState(
        () => `apartar:${cuota.id}:${crypto.randomUUID()}`,
    );

    const form = useForm<Formulario>({
        medium: cuota.expectedMedium,
        bankAccountId:
            fondosAnteriores?.bankAccounts.length === 1
                ? String(fondosAnteriores.bankAccounts[0].id)
                : '',
        cheques: {},
        chequeNuevo: null,
        incomeNumber: '',
        incomeDate: '',
        incomePhoto: null,
        idempotencyKey: clave,
        confirmDuplicates: false,
    });

    const errores = form.errors as Partial<Record<string, string>>;
    const cerrar = () => {
        form.clearErrors();
        onCerrar();
    };

    if (fondosAnteriores === null) {
        return null;
    }

    const enviar = (e: React.FormEvent) => {
        e.preventDefault();

        form.transform((datos) => ({
            medium: datos.medium,
            bankAccountId: datos.medium === 'bank' ? datos.bankAccountId : null,
            cheques:
                datos.medium === 'cheque'
                    ? [
                          ...Object.keys(datos.cheques).map((id) => ({
                              receiptId: Number(id),
                          })),
                          ...(datos.chequeNuevo === null
                              ? []
                              : [
                                    {
                                        number: datos.chequeNuevo.number,
                                        bank: datos.chequeNuevo.bank,
                                        issueDate: datos.chequeNuevo.issueDate,
                                    },
                                ]),
                      ]
                    : null,
            ...(reciboDePapel === null
                ? {
                      incomeNumber: datos.incomeNumber,
                      incomeDate: datos.incomeDate,
                      incomePhoto: datos.incomePhoto,
                  }
                : {}),
            idempotencyKey: datos.idempotencyKey,
            confirmDuplicates: datos.confirmDuplicates,
        }));

        form.post(apartarFondos(cuota.id).url, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => cerrar(),
        });
    };

    const elegirCheque = (id: number, elegido: boolean, disponible: string) => {
        const cheques = { ...form.data.cheques };

        if (elegido) {
            cheques[id] = disponible;
        } else {
            delete cheques[id];
        }

        form.setData('cheques', cheques);
    };

    const cambiarChequeNuevo = (campo: keyof ChequeNuevo, valor: string) => {
        if (form.data.chequeNuevo === null) {
            return;
        }

        form.setData('chequeNuevo', {
            ...form.data.chequeNuevo,
            [campo]: valor,
        });
    };

    /*
     * El importe del cheque nuevo no se tipea: es lo que le falta a la
     * cuota después de los cheques de la lista, porque las cuotas se pagan
     * enteras. El servidor lo vuelve a calcular y no toma otro.
     */
    const importeChequeNuevo = subtractAmounts(
        cuota.expectedAmount,
        sumAmounts(
            Object.values(form.data.cheques).map((importe) =>
                parseAmount(importe),
            ),
        ),
    );

    /*
     * Lo que la apertura declaró en cheques sin detallarlos. De ahí, y solo
     * de ahí, sale un cheque que no está en la lista: más sería un cheque
     * que la caja no tiene.
     */
    const haySinDetallar =
        compareAmounts(fondosAnteriores.undetailedCheques, '0.00') === 1;

    // Los errores de cada renglón de cheque vuelven como `cheques.0.number`.
    const errorDeCheque = Object.entries(errores).find(([clave]) =>
        clave.startsWith('cheques.'),
    )?.[1];

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
                        Cuota {cuota.number}: reservar fondos
                    </DialogTitle>
                    <DialogDescription>
                        Se reservan <Money value={cuota.expectedAmount} /> del
                        saldo del sistema anterior, que hoy tiene{' '}
                        <Money value={fondosAnteriores.pending} /> sin reservar.
                        La plata no se mueve: queda reservada para este
                        beneficiario hasta que se le pague.
                    </DialogDescription>
                </DialogHeader>

                <form
                    id={`form-apartar-${cuota.id}`}
                    onSubmit={enviar}
                    className="grid gap-5"
                >
                    <InputError message={errores.installment} />
                    <InputError message={errores.amount} />

                    <div className="grid gap-2">
                        <Label htmlFor={`medio-apartar-${cuota.id}`}>
                            Dónde está la plata
                        </Label>
                        <Select
                            value={form.data.medium}
                            onValueChange={(valor) =>
                                form.setData('medium', valor)
                            }
                        >
                            <SelectTrigger id={`medio-apartar-${cuota.id}`}>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="cash">
                                    Efectivo en el cajón
                                </SelectItem>
                                <SelectItem value="cheque">
                                    Cheques de la cartera
                                </SelectItem>
                                <SelectItem value="bank">
                                    Depósito directo en la cuenta
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError message={errores.medium} />
                        {/*
                         * El tope es lo que queda del sistema anterior en el
                         * cajón, no lo que hay en el cajón: lo cobrado después
                         * es de otros beneficiarios.
                         */}
                        {form.data.medium === 'cash' && (
                            <p className="text-xs text-muted-foreground">
                                Del sistema anterior quedan{' '}
                                <Money value={fondosAnteriores.cash} /> en
                                efectivo.
                            </p>
                        )}
                    </div>

                    {form.data.medium === 'bank' && (
                        <div className="grid gap-2">
                            <Label htmlFor={`cuenta-apartar-${cuota.id}`}>
                                En qué cuenta está
                            </Label>
                            <Select
                                value={form.data.bankAccountId}
                                onValueChange={(valor) =>
                                    form.setData('bankAccountId', valor)
                                }
                            >
                                <SelectTrigger
                                    id={`cuenta-apartar-${cuota.id}`}
                                >
                                    <SelectValue placeholder="Elegí la cuenta" />
                                </SelectTrigger>
                                <SelectContent>
                                    {fondosAnteriores.bankAccounts.map(
                                        (cuenta) => (
                                            <SelectItem
                                                key={cuenta.id}
                                                value={String(cuenta.id)}
                                            >
                                                {cuenta.label} · quedan{' '}
                                                {money(cuenta.available)} del
                                                sistema anterior
                                            </SelectItem>
                                        ),
                                    )}
                                </SelectContent>
                            </Select>
                            <InputError message={errores.bankAccountId} />
                        </div>
                    )}

                    {form.data.medium === 'cheque' && (
                        <fieldset className="grid gap-2">
                            <legend className="mb-1 text-sm font-medium">
                                Cheques de la cartera de la apertura
                            </legend>
                            {fondosAnteriores.cheques.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No hay cheques de la apertura cargados uno
                                    por uno sin reservar.
                                </p>
                            ) : (
                                <ul className="divide-y rounded-lg border">
                                    {fondosAnteriores.cheques.map((cheque) => {
                                        const elegido =
                                            form.data.cheques[cheque.id] !==
                                            undefined;

                                        return (
                                            <li
                                                key={cheque.id}
                                                className="flex flex-wrap items-center gap-3 px-3 py-2 text-sm"
                                            >
                                                <Checkbox
                                                    checked={elegido}
                                                    onCheckedChange={(v) =>
                                                        elegirCheque(
                                                            cheque.id,
                                                            v === true,
                                                            cheque.available,
                                                        )
                                                    }
                                                    aria-label={`Cheque ${cheque.number}`}
                                                />
                                                <span className="flex-1">
                                                    <span className="font-mono">
                                                        {cheque.number}
                                                    </span>
                                                    {cheque.bank && (
                                                        <> · {cheque.bank}</>
                                                    )}
                                                    <span className="block text-xs text-muted-foreground">
                                                        Se reserva entero
                                                        {cheque.expediente && (
                                                            <>
                                                                {' '}
                                                                · Expte.{' '}
                                                                {
                                                                    cheque.expediente
                                                                }
                                                            </>
                                                        )}
                                                        {cheque.beneficiary && (
                                                            <>
                                                                {' '}
                                                                ·{' '}
                                                                {
                                                                    cheque.beneficiary
                                                                }
                                                            </>
                                                        )}
                                                    </span>
                                                </span>
                                                <span className="font-mono text-sm tabular-nums">
                                                    {money(cheque.amount)}
                                                </span>
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}
                            {haySinDetallar &&
                                form.data.chequeNuevo === null && (
                                    <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-dashed px-3 py-2 text-xs text-muted-foreground">
                                        <span>
                                            La apertura declaró{' '}
                                            {money(
                                                fondosAnteriores.undetailedCheques,
                                            )}{' '}
                                            en cheques sin detallarlos.
                                        </span>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                form.setData('chequeNuevo', {
                                                    number: '',
                                                    bank: '',
                                                    /*
                                                     * Vacía: no hay otra fecha que se
                                                     * le parezca. La de carga de la cuota
                                                     * o la del recibo no son la del
                                                     * cheque, y proponerlas sería guardar
                                                     * algo que el papel no dice.
                                                     */
                                                    issueDate: '',
                                                })
                                            }
                                        >
                                            Cargar un cheque que no está en la
                                            lista
                                        </Button>
                                    </div>
                                )}

                            {form.data.chequeNuevo !== null && (
                                <div className="grid gap-3 rounded-lg border p-3">
                                    <p className="text-xs text-muted-foreground">
                                        Cheque de la cartera que la apertura no
                                        detalló. Queda en custodia con estos
                                        datos, para entregarlo o depositarlo.
                                        Quedan{' '}
                                        {money(
                                            fondosAnteriores.undetailedCheques,
                                        )}{' '}
                                        sin identificar.
                                    </p>
                                    <div className="grid gap-3 sm:grid-cols-4">
                                        <div className="grid gap-1.5">
                                            <Label
                                                htmlFor={`cheque-nuevo-numero-${cuota.id}`}
                                            >
                                                N.º del cheque
                                            </Label>
                                            <Input
                                                id={`cheque-nuevo-numero-${cuota.id}`}
                                                className="font-mono tabular-nums"
                                                value={
                                                    form.data.chequeNuevo.number
                                                }
                                                onChange={(e) =>
                                                    cambiarChequeNuevo(
                                                        'number',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label
                                                htmlFor={`cheque-nuevo-banco-${cuota.id}`}
                                            >
                                                Banco
                                                <span className="ml-1 text-xs font-normal text-muted-foreground">
                                                    opcional
                                                </span>
                                            </Label>
                                            <Input
                                                id={`cheque-nuevo-banco-${cuota.id}`}
                                                value={
                                                    form.data.chequeNuevo.bank
                                                }
                                                onChange={(e) =>
                                                    cambiarChequeNuevo(
                                                        'bank',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label
                                                htmlFor={`cheque-nuevo-fecha-${cuota.id}`}
                                            >
                                                Fecha del cheque
                                                <span className="ml-1 text-xs font-normal text-muted-foreground">
                                                    opcional
                                                </span>
                                            </Label>
                                            <Input
                                                id={`cheque-nuevo-fecha-${cuota.id}`}
                                                type="date"
                                                max={hoy}
                                                value={
                                                    form.data.chequeNuevo
                                                        .issueDate
                                                }
                                                onChange={(e) =>
                                                    cambiarChequeNuevo(
                                                        'issueDate',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        </div>
                                        <div className="grid gap-1.5">
                                            <span className="text-sm font-medium">
                                                Importe
                                            </span>
                                            <p
                                                className="flex h-9 items-center justify-end rounded-md border bg-muted/40 px-3 font-mono text-sm tabular-nums"
                                                title="Es lo que le falta a la cuota: las cuotas se pagan enteras"
                                            >
                                                {money(importeChequeNuevo)}
                                            </p>
                                        </div>
                                    </div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        className="justify-self-start text-xs"
                                        onClick={() =>
                                            form.setData('chequeNuevo', null)
                                        }
                                    >
                                        Quitar este cheque
                                    </Button>
                                </div>
                            )}

                            <InputError message={errores.cheques} />
                            <InputError message={errorDeCheque} />
                        </fieldset>
                    )}

                    <InputError message={errores.sources} />

                    {reciboDePapel === null ? (
                        <fieldset className="grid gap-3 rounded-lg border p-3">
                            <legend className="px-1 text-sm font-medium">
                                Recibo de ingreso de papel
                                <span className="ml-1 text-xs font-normal text-muted-foreground">
                                    el que se le dio al empleador
                                    {corteHistorico !== null && (
                                        <>
                                            , anterior al {date(corteHistorico)}
                                        </>
                                    )}
                                </span>
                            </legend>
                            <div className="grid gap-3 sm:grid-cols-3">
                                <div className="grid gap-1.5">
                                    <Label
                                        htmlFor={`apartar-numero-${cuota.id}`}
                                    >
                                        N.º de talonario
                                    </Label>
                                    <Input
                                        id={`apartar-numero-${cuota.id}`}
                                        className="font-mono tabular-nums"
                                        value={form.data.incomeNumber}
                                        onChange={(e) =>
                                            form.setData(
                                                'incomeNumber',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label
                                        htmlFor={`apartar-fecha-${cuota.id}`}
                                    >
                                        Fecha
                                    </Label>
                                    <Input
                                        id={`apartar-fecha-${cuota.id}`}
                                        type="date"
                                        max={hoy}
                                        value={form.data.incomeDate}
                                        onChange={(e) =>
                                            form.setData(
                                                'incomeDate',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>
                                {/*
                                 * El importe del papel es el de la cuota: se
                                 * paga entera. Se muestra y no se edita.
                                 */}
                                <div className="grid gap-1.5">
                                    <span className="text-sm leading-none font-medium">
                                        Importe
                                    </span>
                                    <p
                                        className="flex h-9 items-center justify-end rounded-md border bg-muted/40 px-3 text-sm"
                                        title="Es el de la cuota: se paga entera"
                                    >
                                        <Money value={cuota.expectedAmount} />
                                    </p>
                                </div>
                            </div>
                            <InputError message={errores.incomeNumber} />
                            <InputError message={errores.incomeDate} />
                            <div className="grid gap-1.5">
                                <Label htmlFor={`apartar-foto-${cuota.id}`}>
                                    Foto
                                    <span className="ml-1 text-xs font-normal text-muted-foreground">
                                        opcional · JPG, PNG, WEBP o PDF
                                    </span>
                                </Label>
                                <Input
                                    id={`apartar-foto-${cuota.id}`}
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp,application/pdf"
                                    onChange={(e) =>
                                        form.setData(
                                            'incomePhoto',
                                            e.target.files?.[0] ?? null,
                                        )
                                    }
                                />
                                <InputError message={errores.incomePhoto} />
                            </div>
                        </fieldset>
                    ) : (
                        <p className="rounded-lg border bg-muted/30 p-3 text-sm">
                            La cuota ya tiene su recibo de ingreso de papel n.º{' '}
                            <span className="font-mono">
                                {reciboDePapel.number}
                            </span>{' '}
                            del {date(reciboDePapel.issuedOn)}: se usa ese.
                        </p>
                    )}

                    {errores.confirmDuplicates && (
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
                            Revisé los avisos: es un papel distinto del que ya
                            está cargado.
                        </label>
                    )}
                </form>

                <DialogFooter className="gap-2">
                    {onVolver !== undefined && (
                        <Button
                            type="button"
                            variant="ghost"
                            className="sm:mr-auto"
                            onClick={() => {
                                form.clearErrors();
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
                        form={`form-apartar-${cuota.id}`}
                        disabled={form.processing}
                    >
                        <PiggyBank className="size-4" aria-hidden="true" />
                        Reservar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
