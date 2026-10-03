# Haberes: reglas y modelo vigente

Complementa el [circuito de Haberes](haberes.md). Describe controles implementados,
no una aprobación normativa. Cada sección identifica su evidencia; una restricción
de un Action no debe presentarse como garantía de PostgreSQL sin comprobar su DDL.

<a id="modelo"></a>

## Relaciones principales

| Entidad | Relación y responsabilidad |
| --- | --- |
| `expedientes` | Agrupa haberes; referencia al empleador en `people` |
| `haberes` | Pertenece a un expediente y a un beneficiario; reconoce un importe |
| `beneficiary_installments` | Pertenece a un haber; distribuye el importe en cuotas |
| `deposit_tickets` | Evidencia del depósito asociada a la cuota; no es un asiento |
| `fund_receipts` | Recepción monetaria de Ledger |
| `funding_allocations` | Une recepción, haber y cuota; conserva asignaciones y reversiones en Haberes |
| `receipts` | Comprobantes de ingreso/egreso de Shared con snapshots y numeración |
| `payment_orders` | Solicitud de transferencia de una cuota, con recibo y cuenta de destino |
| `payment_order_funding_sources` | Conserva los orígenes que respaldan la Orden |
| `pases` | Nota asociada a la Orden |
| `disbursements` | Pago al beneficiario: entrega o transferencia validada |
| `cash_to_bank_transfers` / `cash_to_bank_transfer_items` | Traslado de Banking y sus imputaciones de Haberes |
| `legacy_settlements` | Que una cuota se pagó fuera del circuito, y cómo; append-only con anulación |
| `legacy_documents` | Papeles del sistema anterior de la cuota, con número de talonario, fecha e importe impresos |
| `fund_receipts.origin` | Si la recepción entró por el circuito, es un cheque de la apertura o se apartó del sistema anterior |

`financial_events` y `journal_lines` registran los hechos contables. Un ticket,
una Orden o un informe de transferencia no deben confundirse con el asiento que
reconoce el ingreso o el egreso. Ledger no depende de los modelos de Haberes.

<a id="identidad-y-plan"></a>

## Identidad y plan de cuotas

- El número canónico del expediente tiene índice único; el ordinal del haber es
  único dentro del expediente y el de la cuota dentro del haber.
- Haber y cuota exigen importes positivos. El trigger diferido
  `beneficiary_installments_sum` verifica, al cambiar cuotas, que la suma de las no
  anuladas no supere el importe del haber. No exige igualdad mientras el plan se carga.
- `UpdateInstallment` exige haber y cuota activos, versión vigente y respeto del total.
  Si reducir una cuota deja saldo en un plan completo, aumenta la cantidad prevista
  para reflejar una cuota pendiente; rechaza ese caso al alcanzar el máximo de 60.

Evidencia: [esquema de expedientes](../database/migrations/2026_08_11_000000_create_expedientes_tables.php),
[UpdateInstallment](../app/Modules/Haberes/Actions/UpdateInstallment.php),
[gestión de cuotas](../tests/Feature/Haberes/GestionarCuotasTest.php) y
[numeración del haber](../tests/Feature/Haberes/NumeroDelHaberTest.php).

<a id="financiacion"></a>

## Financiación y saldos derivados

`InstallmentFunding` calcula lo asignado neto de reversiones. No hay un contador
editable del saldo de la cuota. El pendiente es `max(esperado - asignado, 0)`;
el excedente es `max(asignado - esperado, 0)`. Por ello, `isFullyFunded()` también
es verdadero cuando hay sobreasignación: significa que no falta dinero.

Los triggers de `funding_allocations` controlan el saldo de la recepción, el tope
ordinario de la cuota, el medio único, la moneda y que la recepción no esté revertida.
La FK compuesta asegura que la cuota pertenezca al haber indicado. Las asignaciones
no se borran ni se reescriben: se compensan mediante filas `reversal`.

El tope excluye `cash_rounding_surplus`, previsto como excepción. Que el esquema
admita ese tipo no implica que exista una operación de interfaz para reconocer
cualquier excedente. No confundirlo con `residual_status` de la recepción.

Evidencia: [InstallmentFunding](../app/Modules/Haberes/Support/InstallmentFunding.php),
[esquema de asignaciones](../database/migrations/2026_08_16_150000_create_funding_allocations_table.php)
y [financiación de cuota](../tests/Feature/Haberes/FinanciacionDeCuotaTest.php).

<a id="correccion-y-sobreasignacion"></a>

## Corrección, bloqueo y sobreasignación

El trigger `allocation_within_installment` se ejecuta sobre **asignaciones**, no al
reducir `beneficiary_installments.expected_amount`. La reducción por debajo de lo
imputado está admitida expresamente por los tests del Action y de escritura directa
en base.
La tarjeta recibe `overAllocatedAmount` y muestra el excedente.

La edición se bloquea en la aplicación cuando hay una Orden activa con Pase no anulado.
`UnlockInstallmentEdit` registra el motivo y abre la ventana; guardar la cierra.
No basta con tener un recibo emitido para bloquear la cuota.

`UnallocateFunds` exige importe positivo, saldo vigente y motivo; registra una
reversión con fecha del día, sin editar la imputación original. Rechaza asignaciones
que respaldan una Orden no rechazada ni anulada, incluidas las completadas, y las
incluidas en un traslado no cancelado. Los controles del libro también siguen vigentes.

Como la reversión inserta una asignación, vuelve a ejecutar el tope. Si después
de esa inserción la suma ordinaria todavía supera lo esperado, la base la rechaza.
La cobertura citada prueba liberar el excedente completo del caso preparado;
no demuestra que cualquier secuencia de liberaciones parciales sea admisible.

Evidencia: [edición](../app/Modules/Haberes/Actions/UpdateInstallment.php),
[bloqueo](../app/Modules/Haberes/Support/InstallmentEditLock.php),
[liberación](../app/Modules/Haberes/Actions/UnallocateFunds.php),
[cuota con recibo](../tests/Feature/Haberes/CuotaConReciboTest.php),
[desasignación](../tests/Feature/Haberes/DesasignarFondosTest.php) y
[visualización del excedente](../resources/js/features/haberes/components/installment-income.tsx).

<a id="recibo-de-ingreso"></a>

## Recibo de ingreso

Se emite con la cuota completamente financiada y conserva los datos impresos
en snapshots. En mostrador, el cobro y la emisión pueden ser un solo acto atómico;
la cantidad a cobrar se deriva del pendiente de la cuota. En banco, el ticket
requiere identificar el crédito y registrar su recepción/asignación.

Una corrección posterior de cuota no modifica el comprobante anterior. Esto explica
por qué el recibo no congela la cuota; no autoriza editar un recibo emitido.

Una cuota financiada con plata del sistema anterior no lleva recibo del sistema:
su recibo es el de papel ([fondos anteriores](#fondos-anteriores)).

Evidencia: [cobro y emisión](../app/Modules/Haberes/Actions/CollectAndIssueReceipt.php),
[emisión](../app/Modules/Haberes/Actions/IssueIncomeReceipt.php),
[recepción desde ticket](../app/Modules/Haberes/Actions/ReceiveAndAllocateTicket.php),
[recibo de ingreso](../tests/Feature/Haberes/ReciboDeIngresoTest.php) y
[conservación del recibo](../tests/Feature/Haberes/CuotaConReciboTest.php).

<a id="canal-de-pago"></a>

## Canal y traslado al banco

`PaymentOrderSources::channel()` deriva el canal: ingreso bancario implica
transferencia; efectivo/cheque sin traslado vigente implica mostrador; traslado
acreditado implica transferencia; sin medio conocido o con traslado pendiente,
el canal es indeterminado.

El traslado no es un ingreso nuevo ni cambia el medio del recibo original.
Pasa por `CASH_IN_TRANSIT` hasta la confirmación. La condición administrativa
`blocks_payment` se comprueba para Orden y entrega, no para cobrar.

El traslado lleva **todas** las asignaciones con saldo de la cuota, cada una por
lo que le queda en pie: una cuota cubierta con dos cheques los deposita a los dos.
El cheque sigue al traslado: `deposited` mientras está en tránsito, `cleared`
cuando el extracto lo acredita y de vuelta `in_custody` si el traslado se cancela.
Cancelar invierte el asiento del depósito línea por línea, así que el cheque
vuelve a `CHEQUES_IN_CUSTODY` y el efectivo a `CASH_ON_HAND`. El rechazo de un
cheque depositado (`rejected`) no tiene circuito todavía.

| Regla | Action | Base |
|---|---|---|
| El estado del cheque coincide con el de su traslado vigente, y sin traslado no figura en el banco | `DepositCashToBank`, `CancelCashToBankTransfer`, `ConfirmCashDepositCredit` vía `TransferredCheques` | `cheque_follows_transfer`, diferido, desde el ítem, el traslado y el cheque |
| Un cheque se deposita entero: la cuota tiene que tenerlo completo | `DepositCashToBank` | — |
| El traslado sale de una sola caja, a una cuenta en la moneda del haber | `DepositCashToBank` | `journal_lines_bank_currency` (moneda) |
| La acreditación deja el dinero en el banco de la caja del traslado | `ConfirmCashDepositCredit` | — |

Banking no conoce los ítems del traslado: avisa por el contrato
`CashTransferContents`, que Haberes implementa y `AppServiceProvider` registra.

Evidencia: [canal](../app/Modules/Haberes/Support/PaymentOrderSources.php),
[traslado](../app/Modules/Haberes/Actions/DepositCashToBank.php),
[cheques del traslado](../app/Modules/Haberes/Support/TransferredCheques.php),
[elegibilidad de egreso](../app/Modules/Haberes/Support/DisbursementEligibility.php),
[pruebas de traslado](../tests/Feature/Haberes/TrasladoDeEfectivoTest.php) y
[cheques en el traslado](../tests/Feature/Haberes/ChequeEnElTrasladoTest.php).

<a id="moneda"></a>

### Moneda de las líneas

Cada línea va en la moneda de lo que mueve: la de la recepción al asignar, la del
haber al entregar, trasladar o validar una transferencia, la de la cuenta al
registrar una recepción bancaria. Las reversiones invierten el asiento original
copiando su moneda (`InverseEntry`).

| Regla | Action | Base |
|---|---|---|
| Una línea con cuota va en la moneda del haber | los Actions que asientan sobre una cuota | `journal_lines_installment_currency` |
| Las líneas del asiento de una recepción van en la moneda de la recepción | `RegisterCashFundReceipt`, `RegisterBankFundReceipt` | `fund_receipts_currency_matches_event`, diferido |
| La moneda de una recepción no se edita | — | `fund_receipts_append_only` |

Hoy ninguna pantalla crea un haber en dólares: la columna `haberes.currency` vale
pesos salvo que se cargue por otra vía. Evidencia:
[moneda del asiento](../tests/Feature/Haberes/MonedaDelAsientoTest.php) y el
circuito en dólares de
[egreso por transferencia](../tests/Feature/Haberes/EgresoPorTransferenciaTest.php).

<a id="orden-y-pase"></a>

## Orden y Pase

`IssuePaymentOrder` crea ambos documentos en una transacción. El importe, las partes
y los orígenes se derivan en el servidor; los campos elegibles están declarados en
`IssuePaymentOrderData`. No existe un campo `order_kind` en ese contrato.

PostgreSQL impide más de una Orden activa por cuota y protege los datos monetarios
y de identidad impresos. `EditPaymentOrderDetails` permite la corrección accesoria;
`VoidPaymentOrder` anula también el Pase, con motivo y responsable, dentro de sus
condiciones de admisión. Reemitir enlaza la reemplazada y toma un número nuevo.

El enum distingue estados activos de `completed`, `rejected` y `voided`. La emisión
usa `draft`, la anulación `voided` y la validación del egreso `completed`. No se debe
afirmar que todos los estados intermedios declarados tengan una transición expuesta.

Evidencia: [emisión](../app/Modules/Haberes/Actions/IssuePaymentOrder.php),
[datos de emisión](../app/Modules/Haberes/Data/IssuePaymentOrderData.php),
[corrección](../app/Modules/Haberes/Actions/EditPaymentOrderDetails.php),
[anulación](../app/Modules/Haberes/Actions/VoidPaymentOrder.php),
[esquema de órdenes](../database/migrations/2026_08_29_110000_create_payment_orders_table.php)
y [pruebas de Orden](../tests/Feature/Haberes/OrdenDePagoTest.php).

<a id="foja-cbu"></a>

## Foja del CBU y OBS

La misma foja alimenta el Pase y la observación de la Orden mediante
`PaymentOrderObservation`. El formulario de emisión exige la foja; la vista previa
admite un borrador incompleto. No se acepta un OBS independiente que contradiga la foja.

Evidencia: [composición](../app/Modules/Haberes/Support/PaymentOrderObservation.php),
[validación de emisión](../app/Modules/Haberes/Http/Requests/IssuePaymentOrderRequest.php)
y [validación de corrección](../app/Modules/Haberes/Http/Requests/EditPaymentOrderDetailsRequest.php).

<a id="egreso"></a>

## Egreso, confirmación y recibo

Por mostrador, la entrega genera el egreso confirmado. Por transferencia,
`TransferStage` distingue `pending`, `report_received`, `bank_debit_observed` y
`ready_for_validation` según la presencia del informe y del débito. Ambos pueden
llegar en cualquier orden. `confirmed` requiere el acto de validación.

`ValidateTransferDisbursement` registra el asiento, la imputación al débito bancario,
la confirmación del egreso, la cuota `paid` y la Orden `completed`. La fecha contable
se toma del débito. No se debe confundir la etapa del egreso con el estado de la Orden.

PostgreSQL exige Orden en la transferencia y datos de informe, validación y débito
para confirmarla; impide más de un egreso vivo por cuota. `confirmed` sigue siendo
un estado vivo para esa unicidad. El recibo de egreso requiere un egreso confirmado
y solo puede existir uno vigente por cuota. Los estados `reversed` y `failed` están
declarados; eso no demuestra por sí solo un circuito completo de reversión expuesto.

Evidencia: [etapas](../app/Modules/Haberes/Support/TransferStage.php),
[validación](../app/Modules/Haberes/Actions/ValidateTransferDisbursement.php),
[esquema de egresos](../database/migrations/2026_08_30_100000_create_disbursements_table.php),
[egreso por transferencia](../tests/Feature/Haberes/EgresoPorTransferenciaTest.php) y
[egreso por mostrador](../tests/Feature/Haberes/EgresoPorMostradorTest.php).

<a id="historicos"></a>

## Cuotas pagadas fuera del circuito

Una cuota saldada afuera está en `legacy_settled` y tiene un `legacy_settlement`
vigente. Las dos modalidades —`before_opening` y `legacy_disbursement`— comparten
las reglas; cambia de dónde salen la fecha y el medio del pago.

| Regla | Action | Base |
| --- | --- | --- |
| Estado y registro dicen lo mismo; el importe de la cuota no cambia mientras está saldada | `RecordLegacySettlement`, `VoidLegacySettlement` | `legacy_settlement_coherence`, diferido |
| Solo se salda una cuota pendiente sin asignaciones, recibos, Orden, egreso ni comprobante de depósito | `InstallmentMovements` | `legacy_settlement_requires_clean_installment`, con `FOR UPDATE` sobre la cuota |
| Una cuota saldada no acepta asignaciones, Órdenes, egresos, comprobantes ni recibos nuevos | `AllocateFundsToInstallment`, elegibilidades, `RegisterDepositTicket` | `reject_movement_on_legacy_settled_installment`, con `FOR SHARE` |
| Recibo de ingreso de papel obligatorio y por la cuota entera | `RecordLegacySettlement` | La obligatoriedad, en la coherencia; el importe, solo en el Action |
| Desde Pagos anteriores no hay recibo de egreso de papel | `RecordLegacySettlement` | coherencia |
| Papeles y pago anteriores a la primera apertura de la caja de Haberes; sin apertura no se cargan | `LegacyCutoff`, `LegacyPaperCheck` | `legacy_paper_before_opening` y `opening_after_legacy_papers` |
| El mismo papel —tipo, número y fecha— no se carga dos veces | `LegacyPaperCheck` | índice `legacy_documents_paper_unique` |
| El mismo número con otra fecha, o una foto ya cargada, avisa y pasa con confirmación | `LegacyPaperCheck` | — |
| El recibo vinculado es un egreso de Pagos anteriores vigente, del beneficiario, en la moneda del haber, y no se vincula más que su importe | `LegacyDisbursementReceipts` | `legacy_settlement_receipt_link`, con `FOR UPDATE` sobre el recibo |
| Un recibo con vínculos vigentes no se anula | — | `receipts_keep_legacy_settlement_links` |
| Registros y papeles no se borran ni se editan: se anulan una vez | `VoidLegacySettlement` | `legacy_records_append_only` |
| Anular un haber o un expediente no barre cuotas saldadas | `CancelHaber`, `CancelExpediente` | coherencia |

Anular el registro es documental: si la cuota se pagó desde Pagos anteriores, el
asiento sigue y el recibo recupera su disponible. Los papeles que el registro
trajo se anulan con él; un recibo de ingreso cargado antes, por otra vía, no.

La carrera entre dos vínculos al mismo recibo no se reproduce en los tests —el
recibo nace dentro de la transacción del test y otra conexión no lo ve—; se
verifica que el Action lo bloquee antes de leer su disponible.

Evidencia: [esquema](../database/migrations/2026_10_01_010000_create_legacy_settlement_tables.php),
[RecordLegacySettlement](../app/Modules/Haberes/Actions/RecordLegacySettlement.php),
[VoidLegacySettlement](../app/Modules/Haberes/Actions/VoidLegacySettlement.php) y
[cuota histórica](../tests/Feature/Haberes/CuotaHistoricaTest.php).

<a id="fondos-anteriores"></a>

## Fondos del sistema anterior apartados para una cuota

`FundInstallmentFromLegacy` asienta, por cada fuente, un `legacy_funds_allocated`:
débito `LEGACY_FUNDS`, crédito `BENEFICIARY_FUNDS`, con la fecha de hoy. La
recepción de la que se asigna dice su origen en `fund_receipts.origin`:
`received` (entró por el circuito), `opening` (un cheque de la cartera de la
apertura) o `legacy` (efectivo, depósito directo —con su cuenta en
`bank_account_id`— o un cheque que la apertura no detalló y se identifica al
apartar).

Lo sin detallar es el saldo de `CHEQUES_IN_CUSTODY` menos los cheques en custodia
que tienen recepción. Vive solo en el Action. Un cheque depositado sale de los dos
lados a la vez —el asiento lo saca de la cuenta y el traslado le cambia el
estado ([canal y traslado](#canal-de-pago))—, así que no altera la resta.

| Regla | Action | Base |
| --- | --- | --- |
| El asiento solo debita `LEGACY_FUNDS` y acredita `BENEFICIARY_FUNDS`: no mueve dinero de lugar | `SetAsideLegacyFunds` | `legacy_allocation_shape`, diferido |
| `LEGACY_FUNDS` no queda negativo | `SetAsideLegacyFunds`, con `LegacyFundsLock` | `legacy_funds_balance_check` |
| El origen de la recepción corresponde al tipo de su evento | — | `fund_receipts_origin_matches_event` |
| Lo del circuito se asigna con `funds_allocated`; lo anterior, con `legacy_funds_allocated` | `AllocateFundsToInstallment` | `allocation_respects_origin` |
| Una recepción `legacy` no se reutiliza: solo admite la asignación de su propio evento | — | `allocation_respects_origin` |
| Una cuota no mezcla dinero anterior y actual, en ningún sentido | `FundInstallmentFromLegacy`, `AllocateFundsToInstallment` | `allocation_respects_origin`, con `FOR UPDATE` sobre la cuota |
| Un cheque identificado al apartar sale de lo que la apertura declaró sin detallar, y no repite uno que ya está en la cartera | `SetAsideLegacyFunds`, `UndetailedCheques` | — |
| Un cheque del sistema anterior se asigna y se libera entero: lo asignado es cero o el cheque completo | `FundInstallmentFromLegacy`, `UnallocateFunds` | `legacy_cheque_stays_whole`, diferido |
| Un cheque identificado y liberado vuelve a poder apartarse; el efectivo y el depósito apartados son de un solo uso | `SetAsideLegacyFunds`, `LegacyFundsOptions` | `allocation_respects_origin` |
| El depósito directo se aparta del saldo de la cuenta elegida, activa y en la moneda del haber | `SetAsideLegacyFunds`, `CashBalance::ofBankAccount` | `fund_receipts_bank_account_currency` (moneda) |
| Un recibo de papel suelto se anula con motivo solo si ya no respalda plata ni lo cita una Orden | `VoidLegacyIncomeDocument` | `legacy_income_document_keeps_backing` |
| El saldo libre de un cheque no se asigna dos veces | `FundInstallmentFromLegacy`, que bloquea los cheques en orden | `allocation_within_receipt`, ahora con `FOR UPDATE` sobre la recepción |
| Se aparta con financiación cero y por el importe completo | `FundInstallmentFromLegacy` | — |
| Recibo de ingreso de papel por el importe de la cuota, anterior a la apertura | `FundInstallmentFromLegacy`, `LegacyPaperCheck` | `legacy_paper_before_opening`, `legacy_documents_paper_unique` |
| Recibo de papel o del sistema, nunca los dos | `IssueIncomeReceipt` | `income_receipt_paper_or_system` |
| La Orden cita uno de los dos recibos e imprime el talonario si es de papel | `IssuePaymentOrder` | `payment_orders_one_income_receipt_check`, `payment_orders_paper_prints_talonario_check` |
| El papel no se anula mientras lo cite una Orden o respalde plata apartada | — | `legacy_income_document_keeps_backing` |
| Liberar devuelve a `LEGACY_FUNDS`; la recepción no se revierte ni se anula su cobro | `UnallocateFunds`, `ReverseFundReceipt`, `VoidCashCollection` | `allocation_respects_origin` |

El recibo de ingreso se lee igual sea del sistema o de papel (`IncomeEvidence`): la
Orden, la entrega, el traslado y las etapas preguntan si hay recibo sin saber cuál
es. La Orden por banco toma la cuenta del organismo de la recepción cuando no hay
movimiento del extracto que la confirme.

Lo apartado no figura en la recaudación del día ni en `/recepciones`: ya estaba en
la caja. Se ve en Pagos anteriores, con lo liberado al lado.

Evidencia: [esquema](../database/migrations/2026_10_01_020000_allow_allocating_legacy_funds.php),
[FundInstallmentFromLegacy](../app/Modules/Haberes/Actions/FundInstallmentFromLegacy.php),
[SetAsideLegacyFunds](../app/Modules/Ledger/Actions/SetAsideLegacyFunds.php) y
[fondos anteriores](../tests/Feature/Haberes/FondosAnterioresTest.php).

<a id="mantenimiento"></a>

## Cómo mantener esta referencia

Actualizar regla, evidencia y cobertura en la misma tanda cuando cambie el circuito.
Conservar las anclas explícitas aunque se reordenen las secciones. Las migraciones
prueban el esquema esperado; no certifican por sí solas el esquema desplegado.
Una nueva decisión del área debe registrar su fuente y su estado de implementación.
La ausencia de una función en la interfaz no se deduce de la sola presencia de un enum.

El DER y Correcciones quedan como antecedentes para las reglas consolidadas, y como
referencias todavía necesarias para los temas no cubiertos. No borrar una cita
histórica hasta tener un destino que conserve su regla y su justificación.
