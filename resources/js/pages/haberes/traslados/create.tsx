import { Head, useForm, usePage } from '@inertiajs/react';
import { Landmark } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import PageHeader from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import DepositTicketFields from '@/features/banking/components/deposit-ticket-fields';
import TicketPhotoPanel, {
    useTicketPhoto,
} from '@/features/banking/components/ticket-photo-panel';
import { useCuentasDelDia } from '@/features/caja/cuentas-del-dia';
import type { PeriodoDeCuenta } from '@/features/caja/cuentas-del-dia';
import { isNonZero, money } from '@/lib/format';
import { transfer as trasladar } from '@/routes/haberes/installments';

type Props = {
    cuota: {
        id: number;
        number: number;
        amount: string;
        /**
         * Lo que viaja sin ser de la cuota: el resto de un cheque que tiene
         * solo en parte. Un cheque se deposita entero, como llegó.
         */
        carriedAmount: string;
        concept: string | null;
        receiptNumber: string;
    };
    haber: { id: number; expedienteId: number; beneficiaryName: string };
    expediente: { id: number; displayNumber: string };
    /** Cada período de las cuentas de la caja, en la moneda del haber. */
    accounts: (PeriodoDeCuenta & { currency: string })[];
};

/**
 * El efectivo que el beneficiario no retiró, camino al banco.
 *
 * **Es una pantalla y no un modal por la misma razón que la carga del
 * comprobante del expediente**: el operador vuelve del banco con un papel
 * en la mano y lo transcribe mirándolo. La foto va al lado del formulario,
 * y eso no entra en un diálogo sin apretarlo hasta volverlo incómodo.
 *
 * Lo que sí es distinto de aquella pantalla, y por eso no es la misma: allá
 * se registra dinero que **entró** —lo depositó un tercero y hay que
 * averiguar de quién es—; acá el depósito lo hicimos nosotros y se sabe
 * exactamente qué se depositó. El importe no se escribe: lo pone la cuota.
 */
/*
 * `haber` y `expediente` siguen llegando del controlador, pero acá no se usan:
 * el beneficiario y el número de expediente los dice el camino de migas.
 */
export default function TrasladoCreate({ cuota, accounts }: Props) {
    const {
        foto,
        vistaPrevia,
        inputRef: inputFoto,
        elegir: elegirArchivo,
    } = useTicketPhoto();

    const [clave] = useState(
        () => `traslado:${cuota.id}:${crypto.randomUUID()}`,
    );

    const form = useForm({
        bankAccountId: '',
        depositDate: '',
        depositTime: '',
        operationNumber: '',
        terminal: '',
        notes: '',
        ticket: null as File | null,
        idempotencyKey: clave,
    });

    /*
     * Los rechazos del Action —efectivo ya depositado, cuota bancaria—
     * llegan con la clave del campo del backend, que no está en el tipo de
     * este formulario.
     */
    const { errors } = usePage().props as unknown as {
        errors?: Record<string, string>;
    };

    const elegirFoto = (file: File | null) => {
        elegirArchivo(file);
        form.setData('ticket', file);
    };

    // Las cuentas de la caja vigentes el día del depósito, aunque hoy estén dadas de baja.
    const vigentes = useCuentasDelDia(
        accounts,
        form.data.depositDate,
        form.data.bankAccountId,
        (id) => form.setData('bankAccountId', id),
    );

    /*
     * La cuenta se bloquea cuando hay una sola: no es una restricción por
     * rol, es que no hay nada que elegir.
     */
    const cuentaUnica = vigentes.length <= 1;

    /*
     * Solo llegan cuentas de la moneda del haber: el depósito no cambia de
     * moneda. Si no hay ninguna —hoy, los dólares— no hay a dónde llevar
     * el efectivo, y se dice en vez de dejar un selector vacío.
     */
    const sinCuenta = accounts.length === 0;
    const sinCuentaEseDia = !sinCuenta && vigentes.length === 0;

    const enviar = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(trasladar(cuota.id).url, { forceFormData: true });
    };

    return (
        <>
            <Head title="Depositar el efectivo en el banco" />

            <div className="space-y-6 p-4 sm:p-6">
                <PageHeader
                    title="Depositar el efectivo en el banco"
                    description={`El beneficiario no retiró la cuota ${cuota.number}: su efectivo se lleva a la cuenta del organismo.`}
                />

                {/*
                 * Las dos columnas desde `xl`: con la barra lateral abierta,
                 * a 1024 px el formulario quedaba en 265 px y el resumen se
                 * pisaba. Antes se apilan, con la foto debajo.
                 */}
                <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,26rem)]">
                    <form
                        onSubmit={enviar}
                        className="space-y-5 rounded-lg border bg-card p-5 shadow-raised"
                    >
                        {/*
                         * Lo que se deposita no se elige: es lo que la
                         * cuota tiene en la caja. Escribirlo abriría la
                         * puerta a un asiento que no coincide con el
                         * efectivo que salió.
                         */}
                        <dl className="grid gap-3 rounded-lg bg-muted/40 p-4 sm:grid-cols-3">
                            <div>
                                <dt className="text-xs text-field-label">
                                    Importe a depositar
                                </dt>
                                <dd className="font-mono text-lg font-semibold tabular-nums">
                                    {money(cuota.amount)}
                                </dd>
                                {isNonZero(cuota.carriedAmount) && (
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Incluye {money(cuota.carriedAmount)} que
                                        no son de esta cuota: el cheque se
                                        deposita completo, como llegó.
                                    </p>
                                )}
                            </div>
                            <div>
                                <dt className="text-xs text-field-label">
                                    Cuota
                                </dt>
                                <dd className="text-sm">
                                    N.º {cuota.number}
                                    {cuota.concept !== null && (
                                        <span className="block text-xs text-muted-foreground">
                                            {cuota.concept}
                                        </span>
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs text-field-label">
                                    Recibo emitido
                                </dt>
                                <dd className="font-mono text-sm">
                                    {cuota.receiptNumber}
                                </dd>
                            </div>
                        </dl>

                        <p className="text-xs text-muted-foreground">
                            El dinero sigue siendo del beneficiario y sigue
                            imputado a esta cuota: solo cambia de lugar. El
                            recibo ya emitido no se modifica — sigue diciendo
                            «Efectivo», porque eso fue lo que pasó.
                        </p>

                        <div className="grid gap-2">
                            <Label htmlFor="bankAccountId">
                                Cuenta del organismo
                            </Label>
                            {/*
                             * La cuenta es un dato del papel —a cuál entró
                             * el efectivo—, no un privilegio: se bloquea
                             * cuando no hay nada que elegir, no por rol. Si
                             * el organismo abre una segunda cuenta, quien
                             * registra el depósito tiene que poder decir en
                             * cuál se hizo; si no, el cruce contra el
                             * extracto no encuentra el movimiento.
                             */}
                            <Select
                                value={form.data.bankAccountId}
                                onValueChange={(v) =>
                                    form.setData('bankAccountId', v)
                                }
                                disabled={cuentaUnica}
                            >
                                <SelectTrigger id="bankAccountId">
                                    <SelectValue placeholder="Elegí la cuenta" />
                                </SelectTrigger>
                                <SelectContent>
                                    {vigentes.map((c) => (
                                        <SelectItem
                                            key={c.id}
                                            value={c.id.toString()}
                                        >
                                            {c.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {sinCuenta ? (
                                <p className="text-xs text-warning-strong">
                                    No hay cuenta bancaria activa en la moneda
                                    de este haber. Se da de alta en Banco ›
                                    Cuentas.
                                </p>
                            ) : sinCuentaEseDia ? (
                                <p className="text-xs text-warning-strong">
                                    Ninguna cuenta de la caja vale ese día:
                                    revisá la fecha del ticket.
                                </p>
                            ) : cuentaUnica ? (
                                <p className="text-xs text-muted-foreground">
                                    Es la única cuenta de la caja vigente ese
                                    día.
                                </p>
                            ) : (
                                <InputError
                                    message={form.errors.bankAccountId}
                                />
                            )}
                        </div>

                        <DepositTicketFields
                            data={form.data}
                            setData={(campo, valor) =>
                                form.setData(campo, valor)
                            }
                            errors={form.errors}
                        />

                        <InputError message={errors?.installmentId} />

                        <div className="flex items-center gap-3">
                            <Button
                                type="submit"
                                disabled={
                                    form.processing ||
                                    foto === null ||
                                    sinCuenta
                                }
                            >
                                <Landmark className="size-4" />
                                Registrar el depósito
                            </Button>
                            {foto === null && (
                                <span className="text-xs text-warning-strong">
                                    Falta el ticket del cajero
                                </span>
                            )}
                        </div>
                    </form>

                    <TicketPhotoPanel
                        foto={foto}
                        vistaPrevia={vistaPrevia}
                        inputRef={inputFoto}
                        onElegir={elegirFoto}
                        error={form.errors.ticket}
                    />
                </div>
            </div>
        </>
    );
}
