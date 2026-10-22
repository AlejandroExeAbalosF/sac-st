import { Link } from '@inertiajs/react';
import { Ban, Landmark, Undo2 } from 'lucide-react';
import { useState } from 'react';
import StatusBadge from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import CancelTransferDialog from '@/features/banking/components/cancel-transfer-dialog';
import CreditMatchDialog from '@/features/banking/components/credit-match-dialog';
import { AttachmentList } from '@/features/multas/components/attachments';
import { RejectChequeDialog } from '@/features/multas/components/cheque-rejection-dialogs';
import ChequeRejections from '@/features/multas/components/cheque-rejections';
import { COLLECTION_PLACE } from '@/features/multas/labels';
import { date } from '@/lib/format';
import { create as nuevoDeposito } from '@/routes/multas/pagos/deposito';
import {
    cancel as cancelarDeposito,
    candidates as candidatosDelDeposito,
    confirm as acreditarDeposito,
} from '@/routes/multas/traslados';

type Deposito = App.Modules.Multas.Data.FineDepositData;

/**
 * Dónde está la plata de un cobro de la caja, y lo que se puede hacer (M06).
 *
 * En la caja se deposita —entero, en su propio depósito—; depositado, se
 * acredita contra el extracto o se cancela si no ocurrió; acreditado, ya
 * está en el banco. Los depósitos cancelados quedan a la vista con su
 * motivo.
 *
 * Si el cobro fue con cheque, el cheque vigente se puede registrar como
 * rechazado —en la caja, depositado o acreditado— y debajo queda la historia
 * de sus rechazos y reemplazos (M03).
 */
export default function CollectionDeposit({
    paymentId,
    amount,
    deposito,
    desde,
    canDeposit,
    canConfirm,
    canViewAttachments,
    isCheque = false,
    canManageRejections = false,
    canReplaceCheque = false,
}: {
    paymentId: number;
    amount: string;
    deposito: Deposito;
    /** A dónde vuelve la pantalla del depósito. */
    desde: 'caja' | 'multa';
    canDeposit: boolean;
    canConfirm: boolean;
    canViewAttachments: boolean;
    isCheque?: boolean;
    canManageRejections?: boolean;
    canReplaceCheque?: boolean;
}) {
    const [dialogo, setDialogo] = useState<
        'acreditar' | 'cancelar' | 'rechazar' | null
    >(null);
    const lugar = COLLECTION_PLACE[deposito.place];
    const traslado =
        deposito.transferId === null || deposito.depositDate === null
            ? null
            : {
                  id: deposito.transferId,
                  amount,
                  depositDate: deposito.depositDate,
              };

    return (
        <div className="grid gap-1.5 text-xs">
            <div className="flex flex-wrap items-center gap-2">
                <StatusBadge label={lugar.label} tone={lugar.tone} />
                {/* Con rechazos, el cheque vigente puede ser un reemplazo. */}
                {deposito.rejections.length > 0 && deposito.chequeNumber && (
                    <span className="text-muted-foreground">
                        Cheque vigente n.º {deposito.chequeNumber}
                        {deposito.chequeBank && ` (${deposito.chequeBank})`}
                    </span>
                )}
                {deposito.depositDate && (
                    <span className="text-muted-foreground">
                        Depositado el {date(deposito.depositDate)}
                        {deposito.operationNumber &&
                            ` · operación ${deposito.operationNumber}`}
                        {deposito.creditedOn &&
                            ` · acreditado el ${date(deposito.creditedOn)}`}
                    </span>
                )}
                {deposito.place === 'in_custody' && canDeposit && (
                    <Button asChild size="sm" variant="outline">
                        <Link
                            href={nuevoDeposito(paymentId, {
                                query: { desde },
                            })}
                        >
                            <Landmark aria-hidden="true" />
                            Depositar
                        </Link>
                    </Button>
                )}
                {isCheque &&
                    deposito.place !== 'rejected' &&
                    canManageRejections && (
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => setDialogo('rechazar')}
                        >
                            <Ban aria-hidden="true" />
                            Registrar rechazo
                        </Button>
                    )}
                {deposito.place === 'in_transit' && canConfirm && traslado && (
                    <>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setDialogo('acreditar')}
                        >
                            <Landmark aria-hidden="true" />
                            Acreditar
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => setDialogo('cancelar')}
                        >
                            <Undo2 aria-hidden="true" />
                            Cancelar
                        </Button>
                    </>
                )}
            </div>

            {deposito.ticket && (
                <AttachmentList
                    adjuntos={[deposito.ticket]}
                    canView={canViewAttachments}
                />
            )}

            {deposito.cancelled.map((c) => (
                <p key={c.transferId} className="text-muted-foreground">
                    Depósito del {date(c.depositDate)} cancelado
                    {c.reason && `: ${c.reason}`}
                </p>
            ))}

            {deposito.rejections.length > 0 && (
                <ChequeRejections
                    rechazos={deposito.rejections}
                    amount={amount}
                    canManage={canManageRejections}
                    canReplace={canReplaceCheque}
                    canViewAttachments={canViewAttachments}
                />
            )}

            {dialogo === 'rechazar' && (
                <RejectChequeDialog
                    paymentId={paymentId}
                    chequeNumber={deposito.chequeNumber}
                    amount={amount}
                    onClose={() => setDialogo(null)}
                />
            )}
            {dialogo === 'acreditar' && traslado && (
                <CreditMatchDialog
                    traslado={traslado}
                    candidatesUrl={candidatosDelDeposito(traslado.id).url}
                    confirmUrl={acreditarDeposito(traslado.id).url}
                    abierto
                    onCerrar={() => setDialogo(null)}
                />
            )}
            {dialogo === 'cancelar' && traslado && (
                <CancelTransferDialog
                    traslado={traslado}
                    url={cancelarDeposito(traslado.id).url}
                    titulo="Cancelar el depósito"
                    consecuencia="El cobro vuelve a figurar en la caja, para depositarlo de nuevo."
                    ejemplo="Se cargó el ticket de otro depósito."
                    abierto
                    onCerrar={() => setDialogo(null)}
                />
            )}
        </div>
    );
}
