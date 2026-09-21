type Cuota = App.Modules.Haberes.Data.InstallmentListItemData;

/**
 * Por dónde va a salir el dinero, cuando ya no es por donde entró.
 *
 * Una cuota cobrada en efectivo que el beneficiario no retiró termina
 * depositada en la cuenta del organismo, y desde ahí se paga por
 * transferencia. El §2.4.6 separa **cómo entró** de **cómo sale**, y son
 * tres datos distintos que conviven en la misma tarjeta:
 *
 * | Dato | Qué contesta | Cambia |
 * |---|---|---|
 * | Medio previsto | lo que el expediente anticipó | se edita a mano |
 * | «entró por X» | cómo llegó la plata | nunca: va en el recibo |
 * | esto | dónde está hoy | se deriva del traslado |
 *
 * Por eso no reemplaza al medio sino que lo acompaña: el previsto sigue
 * diciendo «Efectivo» porque lo es, y el recibo de ingreso también.
 *
 * **En tránsito se dice, no se esconde.** Entre el depósito y su
 * acreditación el dinero no está ni en el cajón ni en la cuenta, y en esa
 * ventana no se puede pagar de ninguna de las dos formas. Mostrar solo
 * «Depósito» ahí haría creer que el circuito bancario ya está disponible.
 */
export function salidaDe(cuota: Cuota): string | null {
    const traslado = cuota.cashTransfer;

    if (traslado === null) {
        return null;
    }

    return traslado.status === 'bank_confirmed'
        ? '(→ Depósito)'
        : '(→ Depósito, en tránsito)';
}
