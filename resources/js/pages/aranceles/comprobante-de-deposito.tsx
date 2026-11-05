import { Head, useForm, usePage } from '@inertiajs/react';
import { FileCheck } from 'lucide-react';
import { useState } from 'react';
import AmountInput from '@/components/amount-input';
import InputError from '@/components/input-error';
import PageHeader from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import DepositTicketFields from '@/features/banking/components/deposit-ticket-fields';
import TicketPhotoPanel, {
    useTicketPhoto,
} from '@/features/banking/components/ticket-photo-panel';
import { date, money } from '@/lib/format';
import { store } from '@/routes/aranceles/depositos/comprobante';

type Props = {
    traslado: {
        id: number;
        amount: string;
        transferDate: string;
        accountLabel: string;
    };
};

/**
 * El segundo paso del depósito (regla A83): se transcribe el comprobante
 * mirándolo, con la foto al lado.
 *
 * El importe se escribe, no viene cargado: tiene que ser el del papel. Si no
 * coincide con el traspaso, el servidor lo rechaza.
 */
export default function ComprobanteDeDeposito({ traslado }: Props) {
    const {
        foto,
        vistaPrevia,
        inputRef: inputFoto,
        elegir: elegirArchivo,
    } = useTicketPhoto();

    const [clave] = useState(
        () => `comprobante-arancel:${traslado.id}:${crypto.randomUUID()}`,
    );

    const form = useForm({
        amount: '',
        depositDate: traslado.transferDate,
        depositTime: '',
        operationNumber: '',
        terminal: '',
        notes: '',
        ticket: null as File | null,
        idempotencyKey: clave,
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
        form.post(store(traslado.id).url, { forceFormData: true });
    };

    return (
        <>
            <Head title="Cargar el comprobante del depósito" />

            <div className="space-y-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Aranceles"
                    title="Cargar el comprobante del depósito"
                    description="El efectivo ya salió de la caja. Cargar el comprobante no mueve dinero ni reabre el día del traspaso."
                />

                <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,26rem)]">
                    <form
                        onSubmit={enviar}
                        className="space-y-5 rounded-lg border bg-card p-5 shadow-raised"
                    >
                        <dl className="grid gap-3 rounded-lg bg-muted/40 p-4 sm:grid-cols-3">
                            <div>
                                <dt className="text-xs text-field-label">
                                    Importe del traspaso
                                </dt>
                                <dd className="font-mono text-lg font-semibold tabular-nums">
                                    {money(traslado.amount)}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs text-field-label">
                                    Fecha del traspaso
                                </dt>
                                <dd className="text-sm">
                                    {date(traslado.transferDate)}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs text-field-label">
                                    Cuenta
                                </dt>
                                <dd className="text-sm">
                                    {traslado.accountLabel}
                                </dd>
                            </div>
                        </dl>

                        <div className="grid gap-2">
                            <Label htmlFor="comprobante-importe">
                                Importe del comprobante
                            </Label>
                            <AmountInput
                                id="comprobante-importe"
                                value={form.data.amount}
                                onChange={(valor) =>
                                    form.setData('amount', valor)
                                }
                            />
                            <InputError message={form.errors.amount} />
                        </div>

                        <DepositTicketFields
                            data={form.data}
                            setData={(campo, valor) =>
                                form.setData(campo, valor)
                            }
                            errors={form.errors}
                        />

                        <InputError message={errors?.transferId} />

                        <div className="flex items-center gap-3">
                            <Button
                                type="submit"
                                disabled={form.processing || foto === null}
                            >
                                <FileCheck
                                    className="size-4"
                                    aria-hidden="true"
                                />
                                Cargar el comprobante
                            </Button>
                            {foto === null && (
                                <span className="text-xs text-warning-strong">
                                    Falta la foto del comprobante
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
