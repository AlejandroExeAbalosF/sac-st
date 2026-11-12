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
  exige motivo de la lista; una `completion` no lleva. **Base.**
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
- El año de un expediente o un documento va de 1990 a 2100. **Base:** CHECK
  `*_year_check`. Un año de dos cifras toma el siglo de la **fecha del papel**: el
  que lo deja más cerca de ella —con fecha de 2026, «/29» es 2029 y «/98», 1998—.
  Un acta sin fecha usa la de su resolución. El expediente no tiene fecha y usa el
  pivote del año próximo: hasta ahí este siglo, por encima el anterior
  (`TwoDigitYear`). Solo se rechaza lo que queda fuera del rango sin otra lectura
  posible, con el mensaje en el campo del número. **Action.**
- Si el año del número no coincide con el de la fecha del documento, la pantalla
  avisa mientras se escribe y deja guardar: una resolución numerada a fin de año
  puede firmarse en enero. Es un aviso, no una regla.
- Una fecha con precisión `month` se guarda como el primer día del mes; `unknown`
  es fecha nula. **Base:** CHECK `*_known_check` y `*_month_check`.
- Una multa no cita un documento de otro expediente. **Base:** las dos FK
  compuestas de `fine_document_links`.
- Una multa tiene **una sola resolución de origen**: la que la impone. Las
  posteriores la reducen, la revocan o la ratifican y van como modificación o
  ratificación; las actas de origen pueden ser varias. Es lo que muestra el Excel
  histórico: 135 multas con dos resoluciones, y en las que lo aclaran la segunda es
  posterior (reducción del Ministerio, fallo de la Cámara, corrección de un número).
  No está confirmado por el área. **Base:** trigger
  `fine_document_links_single_origin_resolution`, que cuenta solo los vínculos
  vigentes. **Action:** `LinkFineDocument` dice qué hacer en el campo del papel.
- Un vínculo de documento con multa **no se borra ni se edita: se anula**, una
  vez, con fecha, motivo y autor. Desvincular pide el permiso de ajustar. El
  vínculo anulado queda a la vista y el documento sigue en el expediente.
  **Base:** `fine_document_links_revoke_only` y CHECK
  `fine_document_links_revocation_check`. **Action:** `RevokeFineDocumentLink`.
- Multa, documento y papel son únicos **entre los vínculos vigentes**: un
  documento desvinculado por error se vuelve a vincular. **Base:** índice único
  parcial `fine_document_links_active_unique`.
- Cada movimiento vigente exige que su documento conserve **su papel**: el
  importe original, el de origen; un aumento o una reducción, el de
  modificación. Ese vínculo no se anula aunque el documento tenga otros —el
  respaldo o la ratificación del mismo documento sí se desvinculan—, y un
  ajuste se revierte antes de quitarle la modificación. La ficha no ofrece
  desvincular el papel exigido (`requiredRoles`). **Base:**
  `fine_document_links_revoke_only`, con bloqueo de la multa, y
  `fine_debt_entry_document_role`. **Action:** `RevokeFineDocumentLink` dice
  qué hacer.
- El tipo de un documento y el texto del papel no se corrigen: un acta cargada
  como resolución es otro documento, y se corrige desvinculándolo y vinculando el
  correcto. **Base:** `fine_identity_guard`.
- La resolución de origen del importe original se **reemplaza** en un acto
  propio, con su permiso (`multas.reemplazar-origen`) y un motivo de su lista. En
  una transacción: una revisión cambia el documento —y la fecha, que es la de
  la resolución— del importe original; el origen de la vieja queda anulado; la
  nueva queda vinculada como origen. El importe no cambia, y una multa cargada a
  mano exige la fecha exacta de la nueva. **Base:** `fine_identifier_revision_apply`
  admite revisar `fine_document_id` solo en el importe original;
  `fine_debt_entries_keep_document_role`, diferida, exige al cierre el vínculo
  con su papel. **Action:** `ReplaceFineOriginResolution`.

<a id="motivos"></a>

## Motivos

- Los actos que deshacen o corrigen algo ya cargado piden un **motivo de una
  lista** y guardan su código; el texto es un **detalle opcional**, salvo con
  «Otro». Son: ajustar la obligación, revertir un ajuste, descartar un pago,
  desvincular un documento, corregir un dato y descartar un pago anterior.
  **Base:** columnas `*_reason_code` con CHECK por tabla. **Action:** el motivo
  llega como `Reason`, de Shared, el mismo que usa Haberes; la lista de cada acto es
  `FineReasonAct`.
- Cada acto acepta solo los códigos de su lista. **Base:** el CHECK de cada
  columna repite la lista; en `fine_debt_entries` depende del tipo de
  movimiento. **Form Request:** `Reason::rules()`.
- Hay motivo exactamente cuando el acto lo pide: un movimiento que no es el
  importe original, un pago descartado, un vínculo anulado, una revisión que
  corrige y un pago anterior descartado. Completar un dato no lleva motivo.
  **Base.**
- Un detalle, si está, no es solo espacios. **Base.**
- El pago reemplazado por su corrección queda con el código «Reemplazado por su
  corrección», que pone el sistema y no se ofrece en la pantalla; el detalle es
  lo que se corrigió. **Base** y **Action:** `ReportFineBankPayment`,
  `RegisterFineAppeal`.
- En la ficha y en la auditoría el motivo se lee junto: «Error de carga: el
  importe tenía un cero de más». El evento de auditoría guarda además el código
  en `motivo_codigo`, para agrupar; el historial no lo muestra.
- Las listas no salen del relevamiento: son un punto de partida para ajustar con
  el área. El rechazo de un cheque y la cancelación de un depósito siguen con
  texto libre: guardan su motivo en tablas que también usa Haberes.

<a id="obligacion"></a>

## Obligación

- Toda multa confirma con exactamente una constitución. **Base:**
  `fines_have_constitution` (diferido) y `fine_debt_entries_one_constitution`.
- Una multa manual nace citando la resolución que la origina. **Base.**
- Importes siempre positivos; el tipo dice el efecto. Un ajuste lleva motivo.
  **Base:** CHECK.
- Un aumento o una reducción es una de dos cosas: con documento, lo dispone una
  resolución y lleva un motivo de la lista del ajuste; sin documento, es una
  corrección del importe y lleva un motivo de su propia lista y siempre el
  detalle. **Base:** `fine_debt_entries_reason_check`. Que la corrección sea solo
  del administrador (`multas.corregir-importe`), también para revertirla, lo
  decide la ruta y el controlador. Que el importe que vio el formulario siga
  siendo el vigente lo decide el **Action**, con la multa bloqueada.
- La obligación vigente nunca es negativa. **Base:** `fine_debt_entries_coherent`,
  con bloqueo de la multa.
- Una reversión deshace un movimiento de la misma multa, por el mismo importe, una
  sola vez; no revierte la constitución ni otra reversión. **Base.**
- El documento que respalda un movimiento está vinculado a la multa con un
  vínculo vigente y con el papel que el movimiento exige: origen para el
  importe original, modificación para un ajuste. **Base:**
  `fine_debt_entries_coherent` y `fine_debt_entries_cite_active_link`.
- Los movimientos no se editan ni se borran. **Base:** `forbid_mutation()`.
- Con el recurso en trámite, o después de que prosperó, no entra ningún movimiento
  de importe salvo la reducción del veredicto ([recurso](#recurso)). **Base:**
  `fine_debt_entries_follow_appeal`; **Action:** `FineAppeals::assertAmountEditable`
  en `AdjustFineDebt` y `ReverseFineDebtEntry`.

<a id="saldo"></a>

## Saldo

```text
obligation         = constitución + aumentos − reducciones ± reversiones
historical_paid    = pagos anteriores que cuentan − devoluciones anteriores que cuentan
operational_paid   = suma de fine_payment_applications.amount (cada recepción entera)
debt               = max(obligation − operational_paid − historical_paid, 0)
excess             = max(operational_paid + historical_paid − obligation, 0)
operational_excess = max(operational_paid − max(obligation − historical_paid, 0), 0)
```

El histórico consume la obligación primero porque es anterior: `operational_excess`
es la parte del excedente hecha de plata que está en el libro. Cuentan los
antecedentes `validated` y los `provisional` de la [importación](#importacion).

<a id="antecedentes"></a>

## Pagos anteriores al sistema

- No generan `financial_events`.
- Cuentan en el saldo validados o provisionales. Se revisan una vez —de pendiente o
  provisional a validado o descartado— y la revisión no modifica sus datos;
  descartar exige motivo. **Base:** `fine_historical_review_once` y
  `fine_historical_movements_reviewed_check`.
- Una devolución cuenta solo si la cubren pagos anteriores que cuentan en la misma
  multa: si no, la multa terminaría debiendo más que su obligación. Queda pendiente
  hasta que se valide el pago que devuelve. **Base:**
  `fine_historical_refund_covered`, con bloqueo de la multa.
- Validar un provisional no reclasifica nada: ya contaba. Rechazarlo baja lo pagado,
  corre la conciliación del excedente y se impide si deja sin cubrir una devolución
  que cuenta. **Base:** `fine_historical_rejection_keeps_refunds`; **Action:**
  `ReviewFineHistoricalMovement`.
- No se borran. **Base.**

<a id="importacion"></a>

## Importación de la planilla histórica

Circuito en [multas.md › Importar la planilla histórica](multas.md#importacion).

- **Lote.** Pasa `reading → ready | failed`, `ready | consolidated → consolidating`
  y `consolidating → consolidated | ready`, nada más; el corte es un mes entero y
  el hash del archivo tiene forma de SHA-256. **Base:**
  `fine_import_batches_guard`, `fine_import_batches_cutoff_check` y
  `fine_import_batches_sha_check`. Que haya apertura y que el corte sea su mes lo
  pone el **Action** (`StartFineImport`); lo que la base mira al grabar un pago es la
  apertura misma (`fine_historical_import_guard`).
- **Lo que dijo el archivo no cambia.** Las celdas originales, los datos
  normalizados, la clave de identidad y el tipo de fila no se editan; los problemas y
  la fila anterior solo se agregan al final, y solo mientras el lote se lee. Un pie
  de control no tiene identidad ni multa. **Base:** `fine_import_rows_guard`,
  `fine_import_rows_footer_check`.
- **Decisiones.** Una por problema y fila, sobre un problema que bloquea esa fila, con
  una acción que ese problema admite; excluir pide motivo; vincular y elegir
  expediente llevan su destino. **Base:** `fine_import_resolutions_guard`,
  `fine_import_action_allowed` y los CHECK de `fine_import_resolutions`; **Action:**
  `ResolveFineImportIssue` (que el destino sea uno de los candidatos que encontró el
  importador, y que «agregar meses» solo valga cuando cambiaron solo meses), y
  `ResolveFineImportIssueInBulk`, solo para los problemas de total y saldo
  (`FineImportIssue::bulk()`).
- **Consolidación.** Una fila recibe su multa una vez, solo mientras el lote
  consolida, sin exclusión y con todos sus bloqueos resueltos. **Base:**
  `fine_import_rows_guard`. Una misma identidad no se consolida dos veces, aunque la
  consoliden dos lotes a la vez: **Base:** `fine_import_rows_one_fine_per_identity`.
  Que la multa vinculada sea la que se eligió y que no haya asientos ni recibos es
  del **Action** (`ConsolidateFineImportRow`).
- **Pagos importados.** Un antecedente con origen `import` lleva su fila
  (`fine_import_rows`, misma multa) y entra provisional, con precisión de mes y antes
  del mes del corte; provisional solo puede ser uno importado; hay uno por multa,
  tipo y mes. **Base:** `fine_historical_movements_import_row_check`,
  `fine_historical_movements_import_row_fk`,
  `fine_historical_movements_provisional_check`, `fine_historical_import_guard` y
  `fine_historical_movements_one_import_per_month`.
- **Celdas desde el corte.** Nacen pendientes y pasan una vez a identificadas o
  descartadas, con quién y motivo. **Base:** `fine_import_cells_guard` y
  `fine_import_cells_resolved_check`.
- **No se borran.** Lotes, filas, decisiones y celdas. **Base:** los guards de cada
  tabla.
- **No relevado con el área:** qué son las actas «RUBRICA» y «OBST», los pagos
  mayores que la multa (¿intereses?), los sobrepagos y si el corte también vale para
  los pagos anteriores cargados a mano, que hoy no lo tienen.

<a id="adjuntos"></a>

## Archivos

- Un archivo de Multas es un `attachments` con sujeto `fine_case`, `fine`,
  `fine_historical_movement` o `fine_import` (la planilla de un lote). **Base:** `attachments_subject_type_check`.
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

- No hay corte de la cuenta compartida: se sacó el 23/10/2026
  (`drop_bank_account_cutoffs`). El saldo no se reparte y la frontera entre lo
  viejo y lo nuevo es la apertura de cada caja; ver
  [multas.md#sin-corte-de-cuenta](multas.md#sin-corte-de-cuenta).
- El libro dice con qué cuenta opera, de su misma moneda. **Base:** FK compuesta
  `cash_book_openings (bank_account_id, currency) → bank_accounts (id, currency)`.
- Un movimiento anterior a la apertura de una caja no se imputa en su libro.
  **Base:** `bank_allocation_after_opening`.
- La caja de Multas abre en pesos, sin saldo bancario y con su cuenta. **Solo
  Action** (`OpenFineCashBook`): es política de la caja.
- El orden de un día sale de los extractos (`BankDayOrder`): cada archivo ordena
  sus filas y los movimientos que dos archivos comparten unen esos órdenes. Si no
  queda un único orden posible, el día es indeterminado y de él no se afirma ni
  un saldo final ni un salto. Cuál archivo se importó último no ordena nada. Lo
  usan el último saldo informado (`LatestStatementBalance`) y el aviso de
  continuidad entre extractos.
- La cola de créditos sin clasificar arranca en la apertura de la caja y excluye
  los que respaldan un pago anterior vigente. **Solo pantalla.**

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
  pago es el del recibo externo; solo efectivo o depósito directo; uno por multa,
  como primer pago, y congela la multa hasta su veredicto. **Base:** CHECK, índices
  únicos parciales, `fine_payments_guard` y las guardas del trámite. Detalle en
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
  el importe del cheque, sin otro uso y desde la apertura. **Base:**
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

- Se juzga al aplicar: `on_time` si la presentación, en el día de Salta, es
  hasta el último día del mes de `fines.registered_on`; si no, `late`. El cierre
  mensual no interviene (decisión del usuario, 29/10/2026). **Action**
  (`FinePaymentTimeliness`).
- Sin fecha de registro o sin presentación: `undetermined`.
- Se guarda el día contra el que se juzgó (`term_ends_on`). **Base:**
  `fine_payment_applications_timeliness_check` lo exige salvo en `undetermined`.

<a id="cuentas-de-la-caja"></a>

## Cuentas de la caja

- `cash_box_bank_accounts`: caja, cuenta, moneda (la de la cuenta), `valid_from`
  (nulo = inicio histórico desconocido), `valid_until` (incluye ese día),
  `is_default`, quién y cuándo. Una fila por **período**: una cuenta que vuelve a la
  caja suma otra. **Base:** los períodos de una cuenta en una caja no se superponen
  (`cash_box_bank_account_periods`, con candado transaccional); no se borra ni
  cambia de caja o cuenta; sin fechas futuras; la predeterminada no tiene baja;
  `UNIQUE` parcial de una predeterminada por caja y moneda, y el constraint trigger
  diferido `cash_box_has_one_default` exige exactamente una mientras haya alguna
  vigente.
- `cash_box_bank_account_window`: cambiar la vigencia de un período no puede dejar
  afuera depósitos, identificaciones ni antecedentes vigentes que ese período cubría
  y ningún otro cubre. **Base**; las Actions lo dicen con palabras.
- Cambiar la vigencia y usar la cuenta se esperan entre sí: comparten el candado
  transaccional `lock_cash_box_account` (caja y cuenta). Lo toman las guardas del
  depósito, la identificación y el antecedente antes de leer la vigencia, el trigger
  de períodos, y la baja y el cambio de inicio antes de revisar los usos. **Base**;
  lo prueba `CuentasConcurrentesDeMultasTest` con dos procesos.
- Una cuenta vinculada a una caja, o con la que abrió un libro, no cambia de moneda
  aunque no tenga movimientos: el vínculo y la apertura tomaron la suya.
  `freeze_bank_account_currency`. **Base**; `SaveBankAccountRequest` lo explica. Bajo
  concurrencia lo sostiene la clave foránea compuesta `(bank_account_id, currency)`
  del vínculo, como la de la apertura; `LinkBankAccountToCashBox` bloquea la cuenta
  antes de leer su moneda.
- La primera cuenta de la caja la crea `LinkOpeningBankAccount` (Banking) al escuchar
  `CashBookOpened` (Ledger), dentro de la transacción de la apertura. **Action.**
- Un depósito nuevo va a una cuenta de la caja vigente en su fecha:
  `cash_transfer_follows_box_accounts` (solo al insertar; acreditación, reversión y
  rechazo siguen contra la cuenta del depósito). **Base.**
- Identificar un crédito (imputación de una recepción por banco) exige que caiga en
  la ventana de una cuenta de la caja: `bank_allocation_follows_box_accounts`.
  **Base.** Una caja sin vínculos no se controla.
- Un antecedente respaldado por el banco: crédito anterior a la apertura y dentro de
  la vigencia de una cuenta de la caja (`fine_historical_bank_credit`). **Base.**
- Administrarlas es de `multas.caja.cuentas`. **Ruta.**

<a id="recibo"></a>

## Recibo de Multas

- Serie `0210` de la caja Multas. **Base:** `document_series_active_type_unique`
  por tipo y caja.
- Sin persona del maestro: «recibí de» = carátula en `counterparty_name_snapshot`.
  **Base:** `receipts_belong_to_cash_box`.
- El concepto cita la resolución de origen de la multa («Pago parcial de la multa
  por Resolución 649/2026»), nunca un acta, aunque las actas también sean de
  origen. **Action:** `ApplyFinePayment`.
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
- La foto o PDF de la hoja es opcional y exige su número de talonario. Se guarda
  como adjunto privado del recibo (`receipt` / `talonario_photo`) dentro del mismo
  acto; si el cobro vuelve atrás, el archivo se elimina. **Action y validación HTTP.**
- Un cobro ordinario de caja no admite un adjunto genérico del pago: lo respalda
  el recibo del sistema, y el ticket del cajero pertenece al depósito posterior.
  Los pagos bancarios y los recursos sí admiten su comprobante o respaldo.
  **Action:** `AttachFineFile`.

<a id="banco"></a>

## Pagos por banco

- Lo bancario nace `reported` y no toca la deuda. **Base:** `fine_payments_guard`.
- Se verifica con **un** crédito de la cuenta de la caja de Multas, desde la
  apertura de la caja, libre y por el importe entero del
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
- El depósito del recurso conserva su presentación anterior con respaldo; el
  efectivo se presenta en el momento. **Base:** CHECK
  `fine_payments_presentation_check`; **Action:** mensaje en el campo.

**El trámite** (`fine_appeals`, decisión del área del 03/11/2026). El pago es la
condición; el trámite, lo que pasa con la multa hasta el veredicto: `open` (en
trámite), `upheld` (prosperó), `rejected` (no prosperó), `dropped` (cayó).

- Se recurre una multa con deuda, sin pagos ni antecedentes vivos —uno descartado
  no cuenta— y sin otro recurso que no haya caído. **Base:** `fine_appeals_guard`
  (bloquea la multa) e índice único `fine_appeals_one_per_fine`; **Action:**
  `FineAppeals::assertAppealable`, que suma esto al control de saldo, también ante
  dos registros simultáneos.
- El pago de recurso cita su trámite, siempre de la misma multa, y un trámite tiene
  un solo pago vivo. **Base:** FK compuesta `fine_payments_appeal_fk`,
  `fine_payments_appeal_link_check` e índice `fine_payments_one_appeal_per_fine`.
- Una corrección queda en el mismo trámite que el pago que reemplaza, y solo con el
  trámite abierto. **Base:** `fine_payments_follow_appeal`; **Action:**
  `RegisterFineAppeal`.
- **Congelamiento.** Con el trámite abierto no entra otro pago ni un antecedente,
  ni un movimiento de importe. Siguen la verificación, la corrección y el descarte
  del depósito del recurso, el depósito y la acreditación del efectivo, los
  documentos, el reemplazo de la resolución de origen (no cambia el importe) y la
  revisión de la carátula. **Base:** `fine_payments_follow_appeal`,
  `fine_historical_follow_appeal` y `fine_debt_entries_follow_appeal`, cada una
  con la multa bloqueada; **Action:** `FineAppeals::assertNotFrozen` en
  `CollectFinePayment`, `ReportFineBankPayment`, `RecordFineBankCreditPayment` y
  `RecordFineHistoricalMovement`.
- **Caída.** Descartar el depósito de un trámite abierto, o anular su cobro, lo hace
  caer; la multa se descongela y admite otro recurso. **Base:**
  `fine_appeal_consistent` (diferido: abierto exige exactamente un pago vivo; caído,
  ninguno); **Action:** `DiscardFinePayment` y `VoidFineCollection`.
- **Un veredicto exige el pago aplicado**, en los dos sentidos: el 20 % cobrado o
  verificado, con su recibo. **Base:** `fine_appeal_consistent` (resuelto: un pago
  vivo y aplicado, y el veredicto vigente del mismo tipo); **Action:**
  `ResolveFineAppeal`.
- **Prosperó.** Deja la deuda en cero con lo cobrado según el recibo externo. Si eso
  es menos que la obligación, una reducción con `fine_appeal_verdict_id`, sin
  documento y motivo `appeal_admitted`, la lleva exactamente a lo cobrado; si alcanza
  o sobra, no hay reducción y el excedente queda. Hay una reducción por veredicto, y
  mientras esté vigente no entra ningún otro movimiento de importe ni un antecedente.
  **Base:** `fine_debt_entries_follow_appeal`, `fine_debt_entries_reason_check`,
  `fine_debt_entries_verdict_fk`, `fine_debt_entries_one_per_verdict` y
  `fine_appeal_consistent`; **Action:** `ResolveFineAppeal`.
- **No prosperó.** La multa se descongela, lo pagado queda a cuenta y no se admite
  otro recurso. **Base:** `fine_appeals_one_per_fine`.
- **El veredicto es una fila** (`fine_appeal_verdicts`): a lo sumo uno vigente por
  recurso, y de una fila solo se completa su reversión, una vez. `fine_appeals.status`
  es el estado actual. El trámite y el veredicto no se borran. **Base:**
  `fine_appeal_verdicts_one_current`, `fine_appeal_verdicts_guard`,
  `fine_appeals_guard` (transiciones) y `fine_appeal_verdicts_appeal_fk`. Lo da y lo
  revierte quien tiene `multas.cobrar` (ruta).
- **Revertir un veredicto** vuelve el recurso a trámite y congela la multa; si
  prosperó con reducción, una reversión con el mismo veredicto y motivo
  `appeal_verdict_reverted` la deshace, y solo así. Exige que desde el veredicto no
  haya otros pagos vivos, antecedentes vivos ni movimientos de importe sin deshacer.
  **Base:** `fine_appeal_consistent` (con `debt_entries_until_id`, que escribe la base,
  y `fine_amount_moved_since`), `fine_debt_entries_follow_appeal`; **Action:**
  `RevertFineAppealVerdict`, con un mensaje por cada operación que bloquea.
- El veredicto se da y se revierte sobre lo que se vio: si el recurso cayó y se
  registró otro, una corrección cambió el pago o el veredicto ya no es el vigente, se
  rechaza y se pide revisar la multa. **Solo Action:** `ResolveFineAppeal` y
  `RevertFineAppealVerdict` comparan los ids que trae el formulario con los vigentes,
  con la multa bloqueada.
- La multa en trámite figura «En recurso» (`FineDebtStatus::InAppeal`), deba lo que
  deba. **Base:** `fine_balances.appeal_status`.
- **Pendiente, no relevado:** vincular el recurso con la resolución que lo resuelve.

<a id="anulacion"></a>

## Anulación de un cobro

- Se anula un cobro de la caja, en efectivo o con cheque, ya aplicado y que **sigue en
  la caja**: sin traslado vigente y sin rechazo en la cadena de su cheque. **Base:**
  `fine_payment_voids_coherent`, que bloquea la multa y la recepción, la misma fila que
  toma un depósito (`transfer_follows_box_policy`); **Action:** `VoidFineCollection`,
  que toma el mismo bloqueo antes de comprobarlo.
- Una recepción anulada no viaja al banco. **Base:** `fine_transfer_items_not_voided`;
  **Action:** `DepositWholeReceiptToBank`.
- Se escribe en este orden, en una transacción: las dos reversiones del libro, la fila
  de `fine_payment_voids`, la recepción revertida, el recibo anulado y el pago `voided`.
  **Base:** `fund_receipts_keep_fine_applications` (es BEFORE UPDATE: la anulación ya
  tiene que estar) y `fine_payment_voids_coherent` (los dos eventos revierten la
  aplicación y la recepción de ese cobro).
- Al confirmar, un pago `voided` tiene su aplicación, su anulación y el recibo anulado;
  uno `applied`, su aplicación y el recibo vigente. El recibo de un pago vigente no se
  anula por su cuenta. **Base:** `fine_payment_settled`, `fine_payment_voids_settled` y
  `receipts_multas_voided_with_payment`.
- `voided` es final, y la anulación no se edita ni se borra. **Base:**
  `fine_payments_guard` y `fine_payment_voids_append_only`.
- El saldo no cuenta un cobro anulado, y el excedente que mandó ya salió del libro con
  su reversión; si lo pagado cambió, lo que sobraba de otro cobro se reclasifica en el
  mismo acto. **Base:** `fine_balances` y `fine_excess_settled`; **Action:**
  `ReconcileFineExcess`.
- El motivo sale de la lista del acto. **Base:** `fine_payment_voids_reason_check`.
- Un recurso con veredicto no deja anular su cobro: primero se revierte. **Base:**
  `fine_appeal_consistent`; **Action:** `VoidFineCollection`.
- **P13 sigue parcial.** Pendiente: corregir un cobro ya depositado.
- **No relevado con el área:** la lista de motivos, quién anula, que un recurso resuelto
  exija revertir el veredicto antes de anular su cobro y las condiciones para revertir.

<a id="anular-cheque-rechazado"></a>

## Anular un cheque rechazado

- Se anula si el último cheque de la cadena está rechazado y sin reemplazo, y ningún
  rechazo de la cadena figura `debit_pending` ni `debit_recorded`. **Base:**
  `fine_payment_voids_coherent`; **Action:** `VoidFineCollection` con
  `FineDeposits::rejectionVoidBlock`.
- Los dos toman el último cheque y después sus rechazos, en el orden de un reemplazo: el
  segundo espera y ve lo que hizo el primero. **Base:** `fine_payment_voids_coherent` y
  `fund_receipts_replacement_matches`.
- La recepción no se revierte y sale lo que había que regularizar, por el importe entero.
  **Base:** `fine_payment_voids_coherent` (asiento `rejected_cheque_voided`) y
  `fine_payment_voids_settled` (recepción sin revertir).
- Un cobro anulado no se reemplaza. **Base:** `fund_receipts_replacement_matches`;
  **Action:** `ReplaceFineCheque`.
- El motivo «Se paga por otro medio» es solo para un cheque rechazado. **Solo Action:**
  `VoidFineCollection`; la base acepta el código en toda anulación.
- La planilla conserva el renglón del cheque, aunque su recibo esté anulado. **Solo
  lectura:** `CashDayBook`.
- **No relevado con el área:** anular sin saber si el banco lo acreditó (`unverified`),
  quién anula y la lista de motivos.

<a id="pago-verificado-corregir"></a>

## Corregir un pago bancario verificado

- Un pago anulado deja revertidas la aplicación y la recepción, y su crédito sin
  imputar. **Base:** `fine_payment_voids_coherent` y `fine_payment_voids_settled`;
  **Action:** `VoidVerifiedFinePayment`, con `ReleaseFundReceiptAllocations`.
- El crédito liberado se puede volver a usar: lo que tiene reversa ya no respalda nada.
  **Base:** `fine_payment_application_bank_credit`.
- El importe del pago nuevo es el del crédito entero. **Base:**
  `fine_payment_application_bank_credit`; **Action:** `VerifyFineBankPayment`.
- `fine_payment_corrections` une un pago bancario anulado con otro aplicado del mismo
  medio, conserva la presentación y cambia la multa o el crédito; es append-only. **Base:**
  `fine_payment_corrections_coherent` y `fine_payment_corrections_append_only`;
  **Action:** `CorrectVerifiedFinePayment`.
- El pago de un recurso en trámite se corrige dentro de su recurso y de su multa, sin
  dejar de tener un solo pago vivo al confirmar. **Base:**
  `fine_payment_corrections_coherent`, `fine_payments_follow_appeal` y
  `fine_appeal_consistent`; **Action:** `CorrectVerifiedFinePayment`.
- La multa de destino de un pago sin recurso no está congelada. **Base:**
  `fine_payments_follow_appeal`; **Action:** `FineAppeals::assertNotFrozen`. Que tenga
  deuda y la misma moneda es **solo del Action**.
- Con veredicto, primero se revierte. **Base:** `fine_appeal_consistent`; **Action:**
  `VoidVerifiedFinePayment`.
- En la misma multa se conservan el término y su fecha de corte. **Solo Action:**
  `CorrectVerifiedFinePayment`, porque la aplicación no sabe de dónde viene.
- El permiso no se delega. **Base:** `role_has_permissions_guard_non_delegable`;
  **Aplicación:** `PermissionCatalog`.
- **No relevado con el área:** salvo que lo haga solo el administrador, ninguna de estas
  reglas, la lista de motivos ni la regla provisoria de la [rendición](multas.md#rendicion).

<a id="correccion"></a>

## Corrección de un informado

- Solo un pago `reported` se corrige: se descarta como «Reemplazado por su
  corrección», con lo que se corrigió como detalle, y el nuevo
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
  como excedente al verificarlo. **Solo Action.** Un recurso, en cambio, se corrige
  solo mientras su trámite está abierto ([recurso](#recurso)).
