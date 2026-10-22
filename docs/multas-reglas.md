# Multas: reglas y modelo vigente

Complementa el [circuito de Multas](multas.md). Describe controles implementados en
la migración `2026_10_19_000000_create_fines_core_tables`, no una aprobación
normativa. Cada regla dice si la garantiza la base o solo el Action.

<a id="modelo"></a>

## Relaciones principales

| Entidad | Relación y responsabilidad |
| --- | --- |
| `fine_cases` | Expediente de Multas: texto original y número, año y sufijo normalizados |
| `fine_documents` | Resolución, acta u otro papel: texto original, número, año, tipo de acta y fecha con precisión |
| `fine_case_documents` | El documento pertenece al expediente |
| `fines` | La multa: expediente, carátula original y vigente, persona opcional, fecha de registro con precisión |
| `fine_document_links` | Qué papel cumple cada documento en la multa: origen, modificación, ratificación, respaldo |
| `fine_debt_entries` | Importe original, aumentos, reducciones y reversiones de la obligación |
| `fine_historical_movements` | Pagos y devoluciones anteriores al sistema, con su revisión |
| `fine_identifier_revisions` | Cada dato normalizado que se completó o corrigió, y cada fecha de movimiento o de antecedente completada o corregida |
| `fine_balances` (vista) | Obligación, pagado, deuda y excedente, calculados |

No hay `fines.category`: el tipo laboral / higiene y seguridad vive en el acta
(`fine_documents.act_type`).

<a id="original-y-normalizado"></a>

## Original y normalizado

- `original_text`, `caption_original` y `provenance` no cambian nunca. **Base:**
  `fine_identity_guard`.
- Los datos normalizados (número, año, sufijo, tipo de acta, fechas, carátula
  vigente, persona) solo cambian desde una fila de `fine_identifier_revisions`, que
  se aplica sola. **Base:** la guarda acepta el cambio únicamente con
  `pg_trigger_depth() > 1`, es decir, desde `fine_identifier_revision_apply`. No se
  usa una variable de sesión: cualquier rol, la app incluida, podría fijarla.
- La revisión exige que el valor anterior siga siendo el actual, que una fecha
  viaje con su precisión, y que una `completion` solo llene vacíos o precise una
  fecha dentro del mismo mes. Fecha y precisión se juzgan como un par: pasar de
  un día a «solo el mes» es corregir aunque el día no cambie. Una `correction`
  exige motivo. **Base.**
- El valor anterior es **el que vio el formulario** (`expected`), no el que hay al
  recibirlo. Solo entran en la revisión los campos que el operador cambió respecto
  de lo que veía: un campo que no tocó no pisa lo que otro corrigió mientras tanto,
  y uno que sí tocó se rechaza si ya no es lo que vio. **Action** arma la revisión;
  **base** rechaza un valor anterior que no es el actual.
- De un movimiento de deuda y de un antecedente se revisa solo la fecha. Importe,
  tipo, documento, reversión y estado no cambian. **Base:**
  `fine_debt_entries_date_only` y `fine_historical_review_once`.
- Que corregir requiera `multas.ajustar` lo decide el Action
  (`ReviseFineIdentifiers`). **Solo Action.**

<a id="identidad"></a>

## Identidad y carga manual

- Un expediente con número y año es único por número, año y sufijo exactos, con
  `NULLS NOT DISTINCT`: un sufijo ausente no se equipara a «0». **Base:**
  `fine_cases_identity_unique`.
- Resoluciones y actas no tienen unicidad todavía: el mismo número aparece en
  expedientes distintos y la pantalla avisa la coincidencia.
- Una carga manual exige: expediente con número y año; resolución con número y año;
  acta con número y tipo; multa con fecha de registro exacta; movimientos de deuda
  con fecha exacta. Una importada puede llegar incompleta. **Base:** CHECK
  `*_manual_*`.
- Una fecha con precisión `month` se guarda como el primer día del mes; `unknown`
  es fecha nula. **Base:** CHECK `*_known_check` y `*_month_check`.
- Una multa no cita un documento de otro expediente. **Base:** las dos FK
  compuestas de `fine_document_links`.

<a id="obligacion"></a>

## Obligación

- Toda multa confirma con exactamente una constitución. **Base:**
  `fines_have_constitution` (diferido) y `fine_debt_entries_one_constitution`.
- Una multa manual nace citando la resolución que la origina. **Base.**
- Importes siempre positivos; el tipo dice el efecto. Un ajuste lleva motivo.
  **Base:** CHECK.
- La obligación vigente nunca es negativa. **Base:** `fine_debt_entries_coherent`,
  con bloqueo de la multa.
- Una reversión deshace un movimiento de la misma multa, por el mismo importe, una
  sola vez; no revierte la constitución ni otra reversión. **Base.**
- El documento que respalda un movimiento está vinculado a la multa. **Base.**
- Los movimientos no se editan ni se borran. **Base:** `forbid_mutation()`.

<a id="saldo"></a>

## Saldo

```text
obligation         = constitución + aumentos − reducciones ± reversiones
historical_paid    = pagos anteriores validados − devoluciones anteriores validadas
operational_paid   = suma de fine_payment_applications.amount (cada recepción entera)
debt               = max(obligation − operational_paid − historical_paid, 0)
excess             = max(operational_paid + historical_paid − obligation, 0)
operational_excess = max(operational_paid − max(obligation − historical_paid, 0), 0)
```

El histórico consume la obligación primero porque es anterior: `operational_excess`
es la parte del excedente hecha de plata que está en el libro.

<a id="antecedentes"></a>

## Pagos anteriores al sistema

- No generan `financial_events`.
- Cuentan en el saldo solo validados. Se revisan una vez y la revisión no modifica
  sus datos; descartar exige motivo. **Base:** `fine_historical_review_once` y CHECK.
- Una devolución se valida solo si la cubren pagos anteriores ya validados de la
  misma multa: si no, la multa terminaría debiendo más que su obligación. Queda
  pendiente hasta que se valide el pago que devuelve. **Base:**
  `fine_historical_refund_covered`, con bloqueo de la multa.
- No se borran. **Base.**

<a id="adjuntos"></a>

## Archivos

- Un archivo de Multas es un `attachments` con sujeto `fine_case`, `fine` o
  `fine_historical_movement`. **Base:** `attachments_subject_type_check`.
- No se edita ni se borra. **Base:** `attachments_immutable`; solo el archivo de un
  extracto importado se borra, al revertir la importación.
- Que el sujeto exista: `subject_id` es polimórfico y no tiene FK, como en el resto
  de los adjuntos. **Solo Action** (`AttachFineFile` recibe el modelo ya resuelto
  por la ruta).
- Tipo y tamaño por contenido, no por nombre (`ScannedDocument`); las fotos se
  normalizan a WEBP. **Solo Action/Request.**
- Si la fila vuelve atrás, el archivo ya escrito en el disco se borra. **Solo
  Action.**

<a id="exporte"></a>

## Exporte

- Mismos filtros y mismo orden que la pantalla (`FineListQuery`). El orden usa
  `COALESCE(registered_on, '-infinity')` para que el cursor del exporte compare
  lo mismo que ordena; equivale a `NULLS LAST`.
- Recorre por cursor dentro de una sola transacción `REPEATABLE READ, READ ONLY`
  que abarca todos los bloques y sus relaciones: el conjunto, el orden, los saldos
  y los filtros son los del momento de la primera lectura. El cursor usa la fecha
  de registro, que es corregible; sin la foto única, una multa corregida durante la
  descarga salía dos veces o ninguna. Leer no bloquea a quien corrige. **Base**
  (aislamiento de PostgreSQL); regresión con dos conexiones en
  `ExporteConcurrenteDeMultasTest`.
- Los importes van como texto formateado (`Decimal::format`), nunca como número de
  Excel.

<a id="caja-y-banco"></a>

## Caja y cuenta compartida

- El corte de una cuenta es uno, append-only (`bank_account_openings`, UNIQUE por
  cuenta, `forbid_mutation()`). **Base.**
- No hay corte sobre una cuenta cuyo saldo ya declaró la apertura de una caja
  (pata `BANK_ACCOUNT` de un asiento de apertura), ni sobre una con créditos
  imputados hasta esa fecha, ni que caiga en la vida de un libro que opera con
  ella. **Base:** `bank_account_openings_guard`, con el candado de la cuenta.
- Una apertura no declara saldo bancario sobre una cuenta con corte. **Base:**
  `journal_lines_opening_without_shared_bank`.
- El libro dice con qué cuenta opera, de su misma moneda. **Base:** FK compuesta
  `cash_book_openings (bank_account_id, currency) → bank_accounts (id, currency)`.
- Un libro que opera con una cuenta con corte abre después del corte. **Base:**
  `cash_book_openings_after_bank_cutoff`.
- Un movimiento hasta el corte de su cuenta no se imputa en ningún libro, además
  de la regla de la apertura de cada caja. **Base:** `bank_allocation_after_opening`.
- La caja de Multas abre en pesos, sin saldo bancario y con su cuenta. **Solo
  Action** (`OpenFineCashBook`): es política de la caja.
- El saldo declarado coincide con el del extracto al cierre de ese día **solo si
  un mismo archivo lo demuestra**: uno con filas antes y después del corte, leído
  en su orden cronológico (fecha y, dentro del día, de abajo hacia arriba). Un
  extracto que arranca después no dice nada del corte. **Solo Action**; sin ese
  archivo, la pantalla muestra la verificación pendiente y se completa cuando
  llega.
- La conciliación compara el último saldo informado con lo movido **hasta ese
  mismo movimiento**, incluido el orden dentro del día; lo posterior se cuenta
  aparte como no contrastable. **Solo pantalla.**
- El orden de un día sale de los extractos (`BankDayOrder`): cada archivo ordena
  sus filas y los movimientos que dos archivos comparten unen esos órdenes. Si no
  queda un único orden posible, el día es indeterminado y de él no se afirma ni
  un saldo final ni un salto. Cuál archivo se importó último no ordena nada. Lo
  usan el último saldo, la conciliación y el aviso de continuidad entre extractos.
- La cola de créditos sin clasificar excluye los que respaldan un pago anterior
  vigente. **Solo pantalla.**

<a id="credito-viejo"></a>

## Crédito viejo como pago anterior

- `fine_historical_movements.bank_transaction_id`: un crédito de la cuenta de
  Multas, anterior a la apertura de su caja (que tiene que existir), sin
  imputaciones; el pago es por su importe exacto y con su fecha. **Base:**
  `fine_historical_bank_credit`.
- Un crédito respalda un solo pago vigente. **Base:** UNIQUE parcial
  `WHERE status <> 'rejected'`.
- Un crédito vinculado no se imputa en un libro. **Base:**
  `bank_allocation_not_fine_history`.
- Su fecha no se revisa. **Base** (misma función, al actualizar) y **Action**.
- Revertir el extracto de un crédito vinculado se rechaza con mensaje. **Base:**
  la FK; **Action:** `RollbackBankStatementImport` traduce el 23503.

<a id="pagos"></a>

## Pagos y su aplicación

- `fine_payments`: un pago de una multa. Medio `cash` / `cheque` /
  `bank_transfer` / `direct_deposit` / `echeq`; el origen judicial es aparte y solo
  para transferencia o cheque, con su referencia. **Base:** CHECK.
- Lo cobrado en la caja nace `applied`; lo bancario nace `reported`. Después solo
  avanza: `reported` → `applied` o `discarded`. Lo aplicado y lo descartado no
  cambian, y los datos del pago no se editan: un informado mal cargado se corrige
  con otro que lo reemplaza ([corrección](#correccion)). **Base:**
  `fine_payments_guard`.
- La presentación tiene fecha y precisión (`instant` / `day` / `unknown`). Si no es
  el instante de la carga, lleva respaldo. **Base:** CHECK.
- El recurso del 20 %: base = obligación vigente al registrarlo, congelada; el
  20 % calculado = `trunc(base × 0,20, 0)` queda como referencia; el importe del
  pago es el del recibo externo; solo efectivo o depósito directo; uno por multa.
  **Base:** CHECK, índice único parcial y `fine_payments_guard`. Detalle en
  [recurso](#recurso).
- `fine_payment_applications`: una por pago y una por recepción. El importe es la
  recepción entera, de la caja de Multas, en la moneda de la multa y por el mismo
  medio; `applied_amount + excess_amount = amount`; el asiento `funds_applied`
  debita `UNASSIGNED_FUNDS` por el total y acredita `COLLECTED_FUNDS` y
  `PENDING_EXCESS` por cada parte. **Base:** UNIQUE, CHECK y
  `fine_payment_application_coherent`. Append-only.
- Un pago aplicado tiene su aplicación y su recibo; uno no aplicado, ninguno.
  **Base:** `fine_payments_settled`, diferido.
- Un `funds_applied` o `excess_reclassified` sin su fila de Multas no se asienta.
  **Base:** `financial_events_fine_events_linked`, diferido.
- Lo cobrado para una multa no financia una cuota de Haberes ni se revierte por su
  cuenta. **Base:** `funding_allocations_not_from_multas` y
  `fund_receipts_keep_fine_applications`; las pantallas de Haberes no muestran
  recepciones ni recibos de otra caja (**Action**, 404).
- Cobrar exige la caja abierta en la moneda de la multa y saldo pendiente; un cobro
  de un día anterior exige el número de talonario. **Solo Action.**

<a id="deposito"></a>

## Depósito al banco

La política es de la caja (`cash_boxes.deposits_whole_receipts`, Multas en `true`),
no de Multas: Haberes deposita por cuota y no cambia.

- Un traslado lleva **un cobro entero**: un ítem, por el importe de la recepción,
  de la misma caja, recibida por el circuito (`origin = received`), efectivo o
  cheque, sin asignación a cuota. **Base:** `transfer_follows_box_policy`,
  diferido, sobre el traslado y sus ítems; **Action:** `DepositWholeReceiptToBank`.
- Un traslado vigente (no cancelado) por cobro. **Base:**
  `transfer_follows_box_policy`, que toma la recepción (`FOR NO KEY UPDATE`) antes
  de contar: dos escritores a la vez se serializan y el segundo se rechaza
  (`2026_10_23_005000`); **Action:** bloquea la recepción y da el mensaje en el
  campo.
- La cuenta es la del libro de la caja (`cash_book_openings.bank_account_id`), no
  cualquiera de la misma moneda. **Base:** `transfer_follows_box_policy`;
  **Action:** la toma del libro, no la recibe.
- El depósito no es anterior al cobro ni futuro. **Solo Action** y Form Request.
- La foto del ticket es obligatoria. **Solo Form Request** (`ScannedDocument`).
- La acreditación es un **crédito entero y sin otro uso**. **Base:**
  `cash_deposit_credit_whole` sobre `bank_transaction_allocations`; **Action:**
  `ConfirmCashDepositCredit` con la política, y los candidatos no ofrecen otro.
- El traslado solo avanza desde `deposited`, a acreditado o a cancelado. **Base:**
  `cash_to_bank_transfers_append_only` (para todas las cajas); **Action:**
  `CancelCashToBankTransfer` rechaza lo acreditado.
- Acreditar y cancelar a la vez dejan un solo resultado: los dos bloquean el
  traslado. Probado con dos procesos (`DepositoConcurrenteDeMultasTest`).
- Un traslado de otra caja no existe para las pantallas de una caja: 404 en Multas
  y en Haberes. **Solo controlador.**
- El estado del cheque sigue al traslado (`cheque_follows_transfer`); lo mueve
  Banking (`TransferredCheques`), sin saber de quién era.

<a id="rechazo"></a>

## Cheque rechazado y reemplazo

**Alcance vigente (22/10/2026):** rechazo manual con respaldo, reemplazo por otro
cheque del mismo importe y documentación posterior. La conciliación específica del
rechazo y «nunca acreditado» quedan diferidas. `config/multas.php` mantiene
`cheque_bank_followup_enabled = false`, sin variable de entorno de activación.
Los Actions de Multas bloquean movimientos y confirmación; rechazar tampoco
admite un débito. Las consultas de candidatos devuelven 404. Las pantallas no
ofrecen esas operaciones. El código de Banking y sus pruebas se conservan como
preparación; habilitarlo requiere terminar el flujo de rectificación y su interfaz.

Los estados y asientos bancarios avanzados descriptos debajo son infraestructura
preparada, no acciones habilitadas al operador. El rechazo acreditado no modifica
`BANK_ACCOUNT` ni su asignación original: la contrapartida provisoria se conserva
en `PENDING_BANK_DEBITS` hasta resolver la revisión bancaria. No es un débito
inventado en el extracto. Un reemplazo deja esa revisión abierta.

`cheque_rejections` (Banking): un rechazo por instrumento, con dos seguimientos
—la regularización de la empresa y el banco— y lo que se completa después.

- Solo para cheques de cajas que depositan cobros enteros, recibidos por el
  mostrador o de reemplazo. **Base:** `cheque_rejections_guard`; **Action:**
  `RejectCheque`. Haberes no registra rechazos.
- Un instrumento se rechaza una vez (`fund_receipt_id` UNIQUE); un rechazo
  posterior es del reemplazo. Rechazado es tener el rechazo registrado, y al
  revés. **Base:** UNIQUE y `cheque_follows_transfer`.
- El rechazo sigue al traslado vigente: sin traslado, `not_applicable`;
  depositado, el traslado pasa a `bounced` y el banco queda `unverified`;
  acreditado, `debit_pending` o `debit_recorded`. **Base:**
  `cheque_rejections_guard`, `cash_to_bank_transfers_bounced_by_rejection`.
- Asientos (fechados el día del registro): desde custodia D `REJECTED_CHEQUES` /
  C `CHEQUES_IN_CUSTODY`; desde tránsito / C `CASH_IN_TRANSIT`; acreditado /
  C `PENDING_BANK_DEBITS`. Crédito tardío D `BANK_ACCOUNT` / C
  `PENDING_BANK_DEBITS`; débito D `PENDING_BANK_DEBITS` / C `BANK_ACCOUNT`;
  reemplazo D `CHEQUES_IN_CUSTODY` / C `REJECTED_CHEQUES`. En cualquier orden,
  `REJECTED_CHEQUES` no queda acreedor ni `PENDING_BANK_DEBITS` deudor. **Action**
  y pruebas por orden.
- El seguimiento bancario solo avanza: `unverified` → `not_applicable` |
  `debit_pending` | `debit_recorded`; `debit_pending` → `debit_recorded`.
  `not_applicable` desde `unverified` lo afirma una persona, con nota y con el
  extracto del día del depósito importado y completado —un archivo rechazado no
  cuenta—. **Base:** `cheque_rejections_guard`; **Action:**
  `ConfirmChequeNeverCredited`.
- El crédito y el débito son movimientos enteros de la cuenta del traslado, por
  el importe del cheque, sin otro uso y posteriores al corte. **Base:**
  `cheque_rejection_movement_whole`; **Action:** `RejectedChequeMovements`.
- En el motor preparado, de fecha posible: no anteriores al día previo al depósito, y el débito no
  anterior al crédito. El aviso del rechazo no es un límite: puede llegar después
  del débito. **Solo Action**, y los candidatos no ofrecen otros.
- Un depósito devuelto no se cancela. **Base:** transiciones del traslado;
  **Action:** `CancelCashToBankTransfer`.
- El reemplazo es otro cheque del mismo importe, caja y moneda, en custodia, con
  origen `replacement`; un solo sucesor por cheque; los dos vínculos —el del
  reemplazo y el del rechazo— dicen lo mismo. **Base:**
  `fund_receipts_replacement_matches`, `replaces_fund_receipt_id` UNIQUE,
  `fund_receipts_replacement_linked` (diferido), `cheque_rejections_guard`.
- El reemplazo no se aplica a otro pago ni se asigna a una cuota. **Base:**
  `fine_payment_application_coherent` (origen `received`),
  `funding_allocations_not_from_replacements`.
- Lo pendiente de regularizar es el importe entero del instrumento, una vez por
  cadena, mientras su rechazo esté abierto. **Base:** vista `fine_balances`
  (`pending_regularization`, por `fund_receipt_root`).
- Aviso, disposición, crédito, débito, «no acreditado» y reemplazo se escriben
  una vez. **Base:** `cheque_rejections_guard`. La disposición lleva constancia:
  **Form Request** y **Action**. El respaldo del rechazo es obligatorio salvo con
  el débito del extracto en el motor preparado: **Action**. En el alcance vigente
  siempre se exige el respaldo porque la imputación del débito está deshabilitada.
- Rechazar bloquea el traslado y después el cheque, como acreditar y cancelar; dos
  reemplazos del mismo cheque se serializan. Probado con dos procesos.

<a id="excedente"></a>

## Excedente asentado

- Excedente asentado = `excess_amount` de las aplicaciones + reclasificaciones
  (`fine_excess_reclassifications.amount`, positivo hacia el excedente). Tiene que
  ser igual a `operational_excess`. **Base:** `fine_excess_settled`, diferido, al
  insertar aplicaciones, reclasificaciones, movimientos de la obligación y al
  insertar o revisar antecedentes.
- `AdjustFineDebt`, `ReverseFineDebtEntry` y `ReviewFineHistoricalMovement`
  (al validar) llaman a `ReconcileFineExcess` en la misma transacción: un
  `excess_reclassified` entre `COLLECTED_FUNDS` y `PENDING_EXCESS`, sin mover
  dinero. **Action.**

<a id="termino"></a>

## Término

- Se juzga al aplicar contra el cierre **mensual** de la caja de Multas, en la
  moneda de la multa, del mes de `fines.registered_on`, y se guarda con la versión
  de `period_closing_evidence` y el instante de cierre. **Action**
  (`FinePaymentTimeliness`); la base exige la evidencia para `late`.
- Sin cierre cerrado de ese mes (incluido uno reabierto), `on_time`; sin fecha de
  registro, sin presentación, cierre sin evidencia o, con precisión de día, el
  mismo día del cierre: `undetermined`.

<a id="recibo"></a>

## Recibo de Multas

- Serie `0210` de la caja Multas. **Base:** `document_series_active_type_unique`
  por tipo y caja.
- Sin persona del maestro: «recibí de» = carátula en `counterparty_name_snapshot`.
  **Base:** `receipts_belong_to_cash_box`.
- Todo recibo de la caja Multas documenta un pago, con su snapshot
  (`fine_payment_receipts`), por el importe del pago y vigente. **Base:**
  `fine_payment_receipt_coherent` y `receipts_multas_belong_to_a_payment`
  (diferido). Append-only.
- Vínculos del recibo con el libro: la recepción y la aplicación, en
  `receipt_financial_events`.
- La copia congelada (`snapshot`) guarda también el número de la cuenta que el
  formulario imprime en «Depósito Cta.» y la fecha de cada pago anterior **con su
  precisión**: un antecedente conocido por el mes sale «05/2026», no con un día.
  Las reimpresiones leen la copia, nunca el maestro ni el antecedente actuales.
  **Action.**
- Una hoja del talonario se usa una sola vez por caja, aunque el recibo se haya
  anulado. **Base:** `receipts_talonario_number_unique`. **Action:** lo dice en el
  campo, también si dos cobros simultáneos chocan en la base.

<a id="banco"></a>

## Pagos por banco

- Lo bancario nace `reported` y no toca la deuda. **Base:** `fine_payments_guard`.
- Se verifica con **un** crédito de la cuenta de la caja de Multas, posterior al
  corte de la cuenta y a la apertura de la caja, libre y por el importe entero del
  pago (P14 provisorio). **Action:** `VerifyFineBankPayment` con
  `FineBankCredits::pending`. **Base:** `bank_allocation_after_opening` (fechas) y
  `fine_payment_application_bank_credit`: una sola imputación vigente detrás de la
  recepción, por el crédito entero, y el crédito no respalda la aplicación de otro
  pago (una transferencia, una multa).
- La presentación anterior a la carga lleva respaldo. **Base:** CHECK
  `fine_payments_presentation_check`; **Action:** mensaje en el campo.
- Descartar exige motivo y solo un informado. **Base:** `fine_payments_guard` y CHECK
  `fine_payments_discard_check`.

<a id="recurso"></a>

## Recurso del 20 %

- El importe cobrado es el del recibo externo. El 20 % calculado
  (`appeal_required_amount = trunc(base × 0,20, 0)`, base = obligación vigente al
  registrarlo) queda como referencia. **Base:** CHECK `fine_payments_appeal_check` y
  `fine_payments_guard` (base congelada). **Action:** `RegisterFineAppeal` con
  `AppealAmount`. Una diferencia no frena el cobro (U, 21/10/2026).
- Solo efectivo o depósito directo, nunca de origen judicial. **Base:** CHECK.
- Una multa, un recurso vigente (no descartado). **Base:** índice único parcial
  `fine_payments_one_appeal_per_fine`; **Action:** mensaje en el campo, también ante
  dos registros simultáneos.
- El depósito del recurso conserva su presentación anterior con respaldo; el
  efectivo se presenta en el momento. **Base:** CHECK
  `fine_payments_presentation_check`; **Action:** mensaje en el campo.

<a id="correccion"></a>

## Corrección de un informado

- Solo un pago `reported` se corrige: se descarta con «Corregido: motivo» y el nuevo
  apunta a él (`replaces_fine_payment_id`, único). **Base:** `fine_payments_guard`
  exige que el reemplazado esté descartado, sea de la misma multa y del mismo tipo
  (recurso o no). **Action:** `ReportFineBankPayment` y `RegisterFineAppeal`.
- El corregido nace informado y hereda la presentación del original —instante y
  precisión—, que es lo que decide el término; una corrección que trae otra
  presentación se rechaza. **Base:** `fine_payments_guard`; **Action:** mensaje en
  el campo.
- La corrección de un recurso es otro depósito: no se corrige hacia el efectivo.
  **Base:** el corregido nace informado; **Action:** mensaje en el campo.
- Corregir no pide saldo pendiente: no mueve plata, completa los datos de un
  depósito que ya existe. Si otro pago canceló la multa, lo que sobre se reconoce
  como excedente al verificarlo. **Solo Action.**
