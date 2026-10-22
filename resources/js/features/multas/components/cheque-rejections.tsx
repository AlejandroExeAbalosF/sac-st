import { useState } from 'react';
import StatusBadge from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { AttachmentList } from '@/features/multas/components/attachments';
import {
    CompleteRejectionDialog,
    ReplaceChequeDialog,
} from '@/features/multas/components/cheque-rejection-dialogs';
import {
    CHEQUE_BANK_STATUS,
    CHEQUE_DISPOSITION,
} from '@/features/multas/labels';
import { date } from '@/lib/format';

type Rechazo = App.Modules.Multas.Data.FineChequeRejectionData;
type Dialogo = 'reemplazo' | 'completar';

/**
 * Los rechazos de la cadena de cheques de un cobro, del primero al último
 * (M03). Cada uno con sus dos seguimientos —la regularización de la empresa y
 * lo que dice el banco— y lo que falta completar del papel.
 */
export default function ChequeRejections({
    rechazos,
    amount,
    canManage,
    canReplace,
    canViewAttachments,
}: {
    rechazos: Rechazo[];
    amount: string;
    canManage: boolean;
    canReplace: boolean;
    canViewAttachments: boolean;
}) {
    return (
        <ul className="grid gap-2">
            {rechazos.map((r) => (
                <Rechazo
                    key={r.id}
                    rechazo={r}
                    amount={amount}
                    canManage={canManage}
                    canReplace={canReplace}
                    canViewAttachments={canViewAttachments}
                />
            ))}
        </ul>
    );
}

function Rechazo({
    rechazo: r,
    amount,
    canManage,
    canReplace,
    canViewAttachments,
}: {
    rechazo: Rechazo;
    amount: string;
    canManage: boolean;
    canReplace: boolean;
    canViewAttachments: boolean;
}) {
    const [dialogo, setDialogo] = useState<Dialogo | null>(null);
    const banco = CHEQUE_BANK_STATUS[r.bankStatus];
    const faltaPapel = r.companyNotifiedOn === null || r.disposition === null;
    const bancoAbierto =
        r.bankStatus === 'unverified' || r.bankStatus === 'debit_pending';

    return (
        <li className="grid gap-1.5 rounded-md border border-dashed p-2.5 text-xs">
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-medium">
                    Cheque n.º {r.chequeNumber}
                    {r.chequeBank && ` (${r.chequeBank})`} rechazado el{' '}
                    {date(r.rejectedOn)}
                </span>
                <StatusBadge
                    label={r.regularized ? 'Regularizado' : 'A regularizar'}
                    tone={r.regularized ? 'done' : 'blocked'}
                />
                <StatusBadge
                    label={banco.label}
                    tone={banco.tone}
                    className="max-w-full whitespace-normal"
                />
            </div>
            <p className="text-muted-foreground">
                {r.reason}
                {r.registeredByName && ` · registró ${r.registeredByName}`}
            </p>
            <p className="text-muted-foreground">
                {r.depositDate
                    ? `Depositado el ${date(r.depositDate)}`
                    : 'No había salido de la caja'}
                {r.creditOn && ` · crédito del ${date(r.creditOn)}`}
                {r.debitOn && ` · débito del ${date(r.debitOn)}`}
                {r.neverCreditedNote &&
                    ` · el banco no lo acreditó: ${r.neverCreditedNote}`}
            </p>
            <p className="text-muted-foreground">
                {r.replacementChequeNumber
                    ? `Reemplazado por el cheque n.º ${r.replacementChequeNumber}.`
                    : 'Sin reemplazo todavía.'}{' '}
                {r.companyNotifiedOn
                    ? `Se avisó a la empresa el ${date(r.companyNotifiedOn)}.`
                    : 'Falta registrar el aviso a la empresa.'}{' '}
                {r.disposition
                    ? `${CHEQUE_DISPOSITION[r.disposition]}.`
                    : 'Falta registrar qué se hizo con el cheque.'}
            </p>
            {r.attachments.length > 0 && (
                <AttachmentList
                    adjuntos={r.attachments}
                    canView={canViewAttachments}
                />
            )}

            {bancoAbierto && (
                <p className="text-muted-foreground">
                    La revisión bancaria queda pendiente. Registrar el rechazo o
                    recibir el reemplazo no modifica los movimientos del
                    extracto.
                </p>
            )}

            <div className="flex flex-wrap gap-1.5">
                {!r.regularized && canReplace && (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setDialogo('reemplazo')}
                    >
                        Recibir reemplazo
                    </Button>
                )}
                {faltaPapel && canManage && (
                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => setDialogo('completar')}
                    >
                        Completar
                    </Button>
                )}
            </div>

            {dialogo === 'reemplazo' && (
                <ReplaceChequeDialog
                    rejectionId={r.id}
                    amount={amount}
                    onClose={() => setDialogo(null)}
                />
            )}
            {dialogo === 'completar' && (
                <CompleteRejectionDialog
                    rejectionId={r.id}
                    noticeRecorded={r.companyNotifiedOn !== null}
                    dispositionRecorded={r.disposition !== null}
                    onClose={() => setDialogo(null)}
                />
            )}
        </li>
    );
}
