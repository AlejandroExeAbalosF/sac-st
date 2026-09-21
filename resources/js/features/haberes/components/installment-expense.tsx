import { router } from '@inertiajs/react';
import {
    Banknote,
    CircleCheck,
    CircleDashed,
    Eye,
    HandCoins,
    Landmark,
    Megaphone,
    Undo2,
} from 'lucide-react';
import { useState } from 'react';
import Money from '@/components/money';
import StatusBadge from '@/components/status-badge';
import type { StatusTone } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { date } from '@/lib/format';
import { unlinkDebit as desvincular } from '@/routes/haberes/installments/disbursement';
import PayBeneficiaryDialog from './pay-beneficiary-dialog';
import ReceiptViewerDialog from './receipt-viewer-dialog';
import TransferDebitDialog from './transfer-debit-dialog';
import TransferReportDialog from './transfer-report-dialog';
import ValidateTransferDialog from './validate-transfer-dialog';

type Cuota = App.Modules.Haberes.Data.InstallmentListItemData;
type Estado = App.Modules.Haberes.Data.InstallmentDisbursementData;
type Egreso = App.Modules.Haberes.Data.DisbursementSummaryData;
type EgresoStatus = App.Modules.Haberes.Enums.DisbursementStatus;

const TONO: Record<EgresoStatus, StatusTone> = {
    pending: 'progress',
    report_received: 'action',
    bank_debit_observed: 'action',
    ready_for_validation: 'action',
    confirmed: 'done',
    reversed: 'neutral',
    failed: 'blocked',
};

const ETIQUETA: Record<EgresoStatus, string> = {
    pending: 'Preparado',
    report_received: 'Transferencia informada',
    bank_debit_observed: 'Débito observado',
    ready_for_validation: 'Listo para validar',
    confirmed: 'Pagado',
    reversed: 'Revertido',
    failed: 'Fallido',
};

/**
 * El tramo de egreso de una cuota: el pago al beneficiario y su recibo.
 *
 * **Lo primero que decide es por dónde sale el dinero**, y de eso depende
 * todo lo demás. Una cuota cuyo efectivo sigue en la caja se entrega en
 * mano contra el recibo firmado, en un solo acto. Una cuya plata está en
 * la cuenta del organismo recorre tres (§2.3): el SAF avisa, el débito
 * aparece en el extracto, y el contador coteja los dos contra la Orden.
 *
 * No son dos formas de hacer lo mismo: son dos circuitos, y el §2.4.6 los
 * separa de cómo entró el dinero. Una cuota cobrada en efectivo pasa del
 * primero al segundo sola en cuanto la contadora deposita.
 */
export default function InstallmentExpense({
    cuota,
    estado,
    puedePagar,
    puedeValidar,
}: {
    cuota: Cuota;
    estado: Estado;
    puedePagar: boolean;
    puedeValidar: boolean;
}) {
    const [pagando, setPagando] = useState(false);
    const [viendo, setViendo] = useState(false);
    const [informando, setInformando] = useState(false);
    const [buscandoDebito, setBuscandoDebito] = useState(false);
    const [validando, setValidando] = useState(false);

    const egreso = estado.disbursement;
    const pagado = egreso?.status === 'confirmed';

    const dialogos = (
        <>
            {viendo && estado.receipt !== null && (
                <ReceiptViewerDialog
                    recibo={estado.receipt}
                    titulo="Recibo de egreso"
                    abierto
                    onCerrar={() => setViendo(false)}
                />
            )}

            {pagando && (
                <PayBeneficiaryDialog
                    cuota={cuota}
                    estado={estado}
                    abierto
                    onCerrar={() => setPagando(false)}
                />
            )}

            {informando && (
                <TransferReportDialog
                    cuota={cuota}
                    estado={estado}
                    abierto
                    onCerrar={() => setInformando(false)}
                />
            )}

            {buscandoDebito && (
                <TransferDebitDialog
                    cuota={cuota}
                    estado={estado}
                    abierto
                    onCerrar={() => setBuscandoDebito(false)}
                />
            )}

            {validando && (
                <ValidateTransferDialog
                    cuota={cuota}
                    estado={estado}
                    abierto
                    onCerrar={() => setValidando(false)}
                />
            )}
        </>
    );

    /* ── El depósito que todavía no acreditó ─────────────────────────── */
    if (!estado.applies) {
        return (
            <p className="flex items-start gap-2 text-xs text-muted-foreground">
                <Landmark
                    className="mt-0.5 size-3.5 shrink-0"
                    aria-hidden="true"
                />
                {estado.blockedReason ??
                    'El depósito al banco todavía no está acreditado.'}
            </p>
        );
    }

    /* ── El pago ya hecho ────────────────────────────────────────────── */
    if (pagado && egreso !== null) {
        return (
            <>
                <div className="space-y-2">
                    <PagoHecho egreso={egreso} />

                    {estado.receipt !== null ? (
                        <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                            <span className="font-mono text-xs">
                                {estado.receipt.formattedNumber}
                            </span>
                            {estado.receipt.talonarioNumber !== null && (
                                <span className="font-mono text-xs text-muted-foreground">
                                    Talonario {estado.receipt.talonarioNumber}
                                </span>
                            )}
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="min-h-11 text-xs sm:min-h-8"
                                onClick={() => setViendo(true)}
                            >
                                <Eye className="size-3.5" />
                                Ver el recibo
                            </Button>
                        </div>
                    ) : (
                        <div className="space-y-2">
                            <p className="flex items-start gap-2 text-xs text-muted-foreground">
                                <CircleDashed
                                    className="mt-0.5 size-3.5 shrink-0"
                                    aria-hidden="true"
                                />
                                El pago está asentado y falta el recibo de
                                egreso, que es lo que el beneficiario firma.
                            </p>
                            {puedePagar && (
                                <Button
                                    variant="default"
                                    size="sm"
                                    className="min-h-11 text-xs sm:min-h-8"
                                    onClick={() => setPagando(true)}
                                >
                                    <Banknote className="size-3.5" />
                                    Emitir el recibo de egreso
                                </Button>
                            )}
                        </div>
                    )}
                </div>

                {dialogos}
            </>
        );
    }

    /* ── Lo que traba, en cualquiera de los dos circuitos ─────────────── */
    if (estado.blockedReason !== null) {
        return (
            <p className="flex items-start gap-2 text-xs text-muted-foreground">
                <CircleDashed
                    className="mt-0.5 size-3.5 shrink-0"
                    aria-hidden="true"
                />
                {estado.blockedReason}
            </p>
        );
    }

    /* ── Mostrador: un solo acto ──────────────────────────────────────── */
    if (estado.isCounter) {
        return (
            <>
                <div className="space-y-2">
                    <p className="flex items-start gap-2 text-xs text-muted-foreground">
                        <HandCoins
                            className="mt-0.5 size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        {estado.method === 'cheque'
                            ? 'El cheque está en custodia y se le entrega al beneficiario contra el recibo firmado.'
                            : 'El efectivo está en la caja y se le entrega al beneficiario contra el recibo firmado.'}
                    </p>

                    {puedePagar && estado.canPay && (
                        <Button
                            variant="default"
                            size="sm"
                            className="min-h-11 text-xs sm:min-h-8"
                            onClick={() => setPagando(true)}
                        >
                            <Banknote className="size-3.5" />
                            Entregar y emitir el recibo
                        </Button>
                    )}
                </div>

                {dialogos}
            </>
        );
    }

    /* ── Transferencia: los tres pasos del §2.3 ───────────────────────── */
    return (
        <>
            <div className="space-y-3">
                <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                        <Landmark className="size-3.5" aria-hidden="true" />
                        Transferencia del organismo
                    </span>
                    {estado.orderNumber !== null && (
                        <span className="font-mono text-xs">
                            Orden {estado.orderNumber}
                        </span>
                    )}
                    <Money value={estado.amount} className="text-sm" />
                    {egreso !== null && (
                        <StatusBadge
                            label={ETIQUETA[egreso.status]}
                            tone={TONO[egreso.status]}
                        />
                    )}
                </div>

                {/*
                 * Los dos hechos, uno debajo del otro. Ninguno alcanza
                 * solo —invariantes 12 y 13— y por eso se muestran juntos
                 * con lo que le falta a cada uno.
                 */}
                <div className="space-y-1.5">
                    <Paso
                        hecho={egreso?.reportedAt != null}
                        titulo="El organismo informó"
                        detalle={
                            egreso?.reportedAt == null
                                ? 'Todavía no avisó que transfirió.'
                                : date(egreso.reportedAt) +
                                  (egreso.transferReference == null
                                      ? ''
                                      : ` · Ref. ${egreso.transferReference}`)
                        }
                    />
                    <Paso
                        hecho={egreso?.debitTransactionId != null}
                        titulo="El débito en el extracto"
                        detalle={
                            egreso?.debitDate == null
                                ? 'Todavía no se reconoció el movimiento.'
                                : date(egreso.debitDate) +
                                  (egreso.debitOperationId == null
                                      ? ''
                                      : ` · Op. ${egreso.debitOperationId}`)
                        }
                    />
                </div>

                {estado.pendingStep !== null && (
                    <p className="text-xs text-muted-foreground">
                        {estado.pendingStep}
                    </p>
                )}

                <div className="flex flex-wrap gap-1">
                    {puedePagar && estado.canReportTransfer && (
                        <Button
                            variant="outline"
                            size="sm"
                            className="min-h-11 text-xs sm:min-h-8"
                            onClick={() => setInformando(true)}
                        >
                            <Megaphone className="size-3.5" />
                            Registrar el informe
                        </Button>
                    )}

                    {puedePagar && estado.canLinkDebit && (
                        <Button
                            variant="outline"
                            size="sm"
                            className="min-h-11 text-xs sm:min-h-8"
                            onClick={() => setBuscandoDebito(true)}
                        >
                            <Landmark className="size-3.5" />
                            Buscar el débito
                        </Button>
                    )}

                    {puedePagar && estado.canUnlinkDebit && (
                        <Button
                            variant="ghost"
                            size="sm"
                            className="h-8 text-xs"
                            onClick={() =>
                                router.post(
                                    desvincular(cuota.id).url,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            <Undo2 className="size-3.5" />
                            Era otro débito
                        </Button>
                    )}

                    {/*
                     * Validar es del contador y va destacado: es el acto
                     * que convierte dos papeles en un pago.
                     */}
                    {puedeValidar && estado.canValidate && (
                        <Button
                            variant="default"
                            size="sm"
                            className="min-h-11 text-xs sm:min-h-8"
                            onClick={() => setValidando(true)}
                        >
                            <CircleCheck className="size-3.5" />
                            Validar el pago
                        </Button>
                    )}
                </div>
            </div>

            {dialogos}
        </>
    );
}

/** El pago ya asentado, con quién lo respalda. */
function PagoHecho({ egreso }: { egreso: Egreso }) {
    return (
        <>
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <span className="flex items-center gap-1.5 text-sm font-semibold">
                    <HandCoins
                        className="size-3.5 text-muted-foreground"
                        aria-hidden="true"
                    />
                    {egreso.method === 'cheque'
                        ? 'Cheque entregado'
                        : egreso.method === 'bank_transfer'
                          ? 'Transferido por el organismo'
                          : 'Entregado en efectivo'}
                </span>
                <Money value={egreso.amount} className="text-sm" />
                {egreso.paymentDate !== null && (
                    <span className="text-xs text-muted-foreground">
                        {date(egreso.paymentDate)}
                    </span>
                )}
            </div>

            {egreso.deliveredByName !== null && (
                <p className="text-xs text-muted-foreground">
                    Entregó {egreso.deliveredByName}.
                </p>
            )}

            {egreso.validatedByName !== null && (
                <p className="text-xs text-muted-foreground">
                    Validó {egreso.validatedByName}
                    {egreso.validatedAt === null
                        ? ''
                        : ` el ${date(egreso.validatedAt)}`}
                    .
                </p>
            )}
        </>
    );
}

/** Uno de los dos hechos que el egreso bancario necesita. */
function Paso({
    hecho,
    titulo,
    detalle,
}: {
    hecho: boolean;
    titulo: string;
    detalle: string;
}) {
    return (
        <div className="flex items-start gap-2 text-xs">
            {hecho ? (
                <CircleCheck
                    className="mt-0.5 size-3.5 shrink-0 text-success-strong"
                    aria-hidden="true"
                />
            ) : (
                <CircleDashed
                    className="mt-0.5 size-3.5 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
            )}
            <span>
                <span
                    className={hecho ? 'font-medium' : 'text-muted-foreground'}
                >
                    {titulo}
                </span>
                <span className="text-muted-foreground"> — {detalle}</span>
            </span>
        </div>
    );
}
