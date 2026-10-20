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
