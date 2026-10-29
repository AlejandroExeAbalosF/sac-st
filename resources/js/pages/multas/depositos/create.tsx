import { Head, useForm, usePage } from '@inertiajs/react';
import { Landmark } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import PageHeader from '@/components/page-header';
import { Button } from '@/components/ui/button';
import DepositTicketFields from '@/features/banking/components/deposit-ticket-fields';
import TicketPhotoPanel, {
    useTicketPhoto,
} from '@/features/banking/components/ticket-photo-panel';
import { PAYMENT_MEDIUM } from '@/features/multas/labels';
import { businessToday, date, money } from '@/lib/format';
import { store as depositar } from '@/routes/multas/pagos/deposito';

type Props = {
    multa: { id: number; caption: string; caseLabel: string };
    cobro: {
        id: number;
        medium: App.Modules.Multas.Enums.FinePaymentMedium;
        amount: string;
        receivedOn: string | null;
        receiptNumber: string | null;
        chequeNumber: string | null;
        chequeBank: string | null;
    };
    cuenta: string;
    desde: 'pendientes' | 'multa';
};

/**
 * Un cobro de la caja de Multas, camino al banco (M06).
 *
 * Es una pantalla y no un diálogo por lo mismo que en Haberes: el operador
 * vuelve del banco con el ticket y lo transcribe mirándolo, con la foto al
 * lado. El importe no se escribe —el cobro va entero— y la cuenta no se
 * elige: es la del libro de la caja.
 */
export default function DepositoDeCobro({
    multa,
    cobro,
    cuenta,
    desde,
}: Props) {
    const {
        foto,
        vistaPrevia,
        inputRef: inputFoto,
        elegir: elegirArchivo,
    } = useTicketPhoto();

    const [clave] = useState(
        () => `deposito-multa:${cobro.id}:${crypto.randomUUID()}`,
    );

    const form = useForm({
        depositDate: businessToday(),
        depositTime: '',
        operationNumber: '',
        terminal: '',
        notes: '',
        ticket: null as File | null,
        idempotencyKey: clave,
        desde,
    });

    // Los rechazos del Action llegan con la clave del backend.
    const { errors } = usePage().props as unknown as {
        errors?: Record<string, string>;
    };

    const elegirFoto = (file: File | null) => {
        elegirArchivo(file);
        form.setData('ticket', file);
    };

    const enviar = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(depositar(cobro.id).url, { forceFormData: true });
    };

    return (
        <>
            <Head title="Depositar un cobro en el banco" />

            <div className="space-y-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Multas"
                    title="Depositar un cobro en el banco"
                    description={`${multa.caption} · Expte. ${multa.caseLabel}. El cobro va entero y solo, en su propio depósito.`}
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
                        <dl className="grid gap-3 rounded-lg bg-muted/40 p-4 sm:grid-cols-3">
                            <div>
                                <dt className="text-xs text-field-label">
                                    Importe a depositar
                                </dt>
                                <dd className="font-mono text-lg font-semibold tabular-nums">
                                    {money(cobro.amount)}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs text-field-label">
                                    Cobro
                                </dt>
                                <dd className="text-sm">
                                    {PAYMENT_MEDIUM[cobro.medium]}
                                    {cobro.chequeNumber &&
                                        ` n.º ${cobro.chequeNumber}`}
                                    {cobro.chequeBank &&
                                        ` (${cobro.chequeBank})`}
                                    {cobro.receivedOn && (
                                        <span className="block text-xs text-muted-foreground">
                                            Cobrado el {date(cobro.receivedOn)}
                                        </span>
                                    )}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs text-field-label">
                                    Recibo emitido
                                </dt>
                                <dd className="font-mono text-sm">
                                    {cobro.receiptNumber ?? '—'}
                                </dd>
                            </div>
                        </dl>

                        <div className="grid gap-1">
                            <p className="text-xs text-field-label">
                                Cuenta del organismo
                            </p>
                            <p className="text-sm">{cuenta}</p>
                            <p className="text-xs text-muted-foreground">
                                Es la cuenta con la que opera la caja de Multas:
                                todo depósito va a ella.
                            </p>
                        </div>

                        <p className="text-xs text-muted-foreground">
                            Depositar no es cobrar otra vez: la multa ya tiene
                            este pago aplicado y su recibo. Solo cambia dónde
                            está la plata.
                        </p>

                        <DepositTicketFields
                            data={form.data}
                            setData={(campo, valor) =>
                                form.setData(campo, valor)
                            }
                            errors={form.errors}
                        />

                        <InputError message={errors?.receiptId} />

                        <div className="flex items-center gap-3">
                            <Button
                                type="submit"
                                disabled={form.processing || foto === null}
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
