# Aranceles

Qué hace hoy la Caja de Aranceles. Describe lo implementado.

**Estado: en construcción.** Está el catálogo de tipos de arancel y sus precios. Todavía
no se cobra: el cobro, el recibo y la caja son la etapa siguiente.

<a id="catalogo"></a>

## Tipos de arancel · `/aranceles/tipos`

Lo que se cobra y cuánto vale desde cada fecha. Lo mira quien tiene `aranceles.ver`; lo
cambia quien tiene `aranceles.catalogo`, sensible y de entrada solo del administrador.

El catálogo arranca con las 25 tasas del Anexo del Decreto 1202/2019 (Boletín Oficial
N.º 20.577, págs. 13-14), con `FeeTypeSeeder`, que no pisa lo que el área haya cambiado.
Dos puntos están **sin confirmar con el área**: que el código numérico sea la posición en
el anexo (n = 14, y = 25), como sugiere la foto de un recibo, y que esos valores sigan
vigentes. La vigencia inicial es la fecha del decreto, el 02/09/2019: desde cuándo se
aplicó no está relevado.

- **Buscar:** el select busca por código exacto, inciso o palabras del concepto, sin
  importar mayúsculas ni tildes. Elegir un arancel abre su ficha.
- **Tipo:** código, inciso opcional, concepto, modo de cálculo y unidad opcional. Se puede
  corregir y desactivar; **no se borra**. Lo que ya se haya cobrado no va a cambiar: cada
  recibo guardará su copia.
- **Modo de cálculo:** cantidad por precio, o porcentaje sobre un monto. Se elige en el
  alta y no cambia, porque decide qué valor llevan los precios.
- **Precio:** un valor desde una fecha, con referencia legal opcional. El tipo nace con su
  primer precio. Rige el último ya empezado: una fecha futura queda «Programado» hasta que
  llegue. Un cambio de precio es otro precio: el historial no se edita.
- **Anular un precio:** para uno mal cargado. Pide motivo, queda a la vista y libera su
  fecha para cargar el correcto. El único precio de un tipo no se anula: primero se carga
  el correcto.

**Base:** `fee_type_prices_append_only`, `fee_type_prices_type_date_unique`,
`fee_type_price_matches_mode`, `fee_types_mode_frozen`, `fee_types_have_a_price`, y los
`CHECK` de valores positivos y de un solo valor por precio. Altas, correcciones y cambios
de precio quedan en la auditoría.
