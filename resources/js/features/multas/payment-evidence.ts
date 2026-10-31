type PaymentWithEvidence = {
    medium: App.Modules.Multas.Enums.FinePaymentMedium;
    appealKind: App.Modules.Multas.Enums.AppealKind | null;
};

/**
 * Qué pagos tienen un papel externo propio para adjuntar.
 *
 * En efectivo y cheque ordinarios, el pago ya queda respaldado por el
 * recibo del sistema; el ticket posterior pertenece al depósito de la
 * recaudación. Un recurso sí conserva su recibo externo, aun cuando se
 * cobre en efectivo.
 */
export function acceptsPaymentEvidence(payment: PaymentWithEvidence): boolean {
    return (
        payment.appealKind !== null ||
        (payment.medium !== 'cash' && payment.medium !== 'cheque')
    );
}
