import { Head, Link, useForm } from '@inertiajs/react';
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
import { money } from '@/lib/format';
import { index } from '@/routes/aranceles/cobros';
import { store } from '@/routes/aranceles/cobros/comprobante';

type Props = {
    cobro: {
        id: number;
        payerName: string;
        total: string;
        missing: string;
        pending: boolean;
    };
    today: string;
};

/**
 * El comprobante del depósito de un cobro, como en las otras cajas: se transcribe
 * mirándolo, con la foto al lado.
 *
 * El importe se escribe, no viene cargado: tiene que ser el del papel. Puede no
 * coincidir con el cobro, porque el dinero se verifica contra el extracto.
 */
export default function ComprobanteDeCobro({ cobro, today }: Props) {
    const {
        foto,
        vistaPrevia,
        inputRef: inputFoto,
        elegir: elegirArchivo,
    } = useTicketPhoto();

    const [clave] = useState(
        () => `comprobante-cobro:${cobro.id}:${crypto.randomUUID()}`,
    );

    const form = useForm({
        amount: '',
        depositDate: today,
        depositTime: '',
        operationNumber: '',
        terminal: '',
        notes: '',
        ticket: null as File | null,
        idempotencyKey: clave,
    });

    const elegirFoto = (file: File | null) => {
        elegirArchivo(file);
        form.setData('ticket', file);
    };

    const enviar = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(store(cobro.id).url, { forceFormData: true });
    };

    return (
        <>
            <Head title="Cargar el comprobante del depósito" />

            <div className="space-y-6 p-4 sm:p-6">
                <PageHeader
                    eyebrow="Aranceles"
                    title="Cargar el comprobante del depósito"
                    description="Sin él, el cobro no se puede identificar contra el extracto. Cargarlo no mueve dinero."
                />

                <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,26rem)]">
                    <form
                        onSubmit={enviar}
                        className="space-y-5 rounded-lg border bg-card p-5 shadow-raised"
                    >
                        <dl className="grid gap-3 rounded-lg bg-muted/40 p-4 sm:grid-cols-3">
                            <div>
                                <dt className="text-xs text-field-label">
                                    Quién paga
                                </dt>
                                <dd className="text-sm">{cobro.payerName}</dd>
                            </div>
                            <div>
                                <dt className="text-xs text-field-label">
                                    Total del cobro
                                </dt>
                                <dd className="font-mono text-lg font-semibold tabular-nums">
                                    {money(cobro.total)}
                                </dd>
                            </div>
                            {cobro.pending && (
                                <div>
                                    <dt className="text-xs text-field-label">
                                        Falta
                                    </dt>
                                    <dd className="font-mono text-lg font-semibold tabular-nums">
                                        {money(cobro.missing)}
                                    </dd>
                                </div>
                            )}
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

                        <div className="flex flex-wrap items-center gap-3">
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
                            <Button variant="ghost" asChild>
                                <Link
                                    href={index({ query: { cobro: cobro.id } })}
                                >
                                    Cargarlo después
                                </Link>
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
