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
operational_paid   = 0 hasta que existan los cobros
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
