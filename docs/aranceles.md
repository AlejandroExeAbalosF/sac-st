# Aranceles

Qué hace hoy la Caja de Aranceles. Describe lo implementado.

**Estado: en construcción.** Están el catálogo, el cobro en efectivo con su recibo, la
anulación, el reemplazo del recibo, la corrección y la caja del día con su arqueo, cierre y
planilla. Todavía no están el depósito al banco, el pago por depósito ni el informe mensual.

Las reglas con su origen están fuera del repo, en `Relevamiento/Aranceles/Reglas-y-DER-Aranceles-v1.md`.
Varias son provisorias: no salen del relevamiento y están para confirmar con el área.

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

Un precio ya usado en un cobro no se anula, y un código ya usado no cambia. **Base:**
`fee_type_price_in_use`, `fee_type_code_in_use`.

<a id="caja"></a>

## Caja · `/aranceles/caja/*`

Son las pantallas de caja de siempre, con la caja de Aranceles y permisos propios
(`aranceles.caja.*`). No acepta cheques.

- **Apertura** (`aranceles.caja.abrir`): una sola vez, con el efectivo contado por billete o
  declarando que no hay, y la cuenta con la que opera. Sin saldo bancario: la cuenta es
  compartida con Multas y su saldo no se reparte. El efectivo declarado lleva una recepción
  sobre el mismo evento de apertura, sin asiento nuevo: no es recaudación ni respalda un
  cobro, y es lo que va a permitir depositarlo.
- **Serie:** los recibos numeran con `0110`, configurable en Numeración.
- **Arqueo:** además de los billetes de las otras cajas ofrece 20, 10, 5, 2 y 1, porque las tasas
  son chicas. Qué denominaciones circulan **no está relevado**. Un importe con centavos no se
  puede contar y queda como diferencia explicada, hasta que el área diga cómo se rinde.
- **Cierre y planilla:** sin columnas de cheques ni inventario. **Base:**
  `closing_evidence_matches_version` admite la lista vacía solo si la evidencia no trae cheques.

<a id="cobro"></a>

## Cobro en efectivo · `/aranceles/nuevo`

Con `aranceles.cobrar` y la caja abierta. Crear, recibir, aplicar y emitir el recibo van en
una sola transacción.

- **Quién paga:** nombre obligatorio y CUIT opcional. No se crea en el maestro de personas.
- **Renglones:** uno o más, sin tope, cada uno con su tipo. El precio lo pone el servidor,
  según la fecha: la pantalla solo muestra un anticipo. Cantidad por precio, o el porcentaje
  sobre un monto, redondeado una vez, al final, al centavo más cercano (**sin confirmar**).
- **Importe distinto del calculado:** con `aranceles.modificar-importe`, en cualquier renglón.
  Pide un motivo de la lista: Redondeo (solo menos de $ 1), Se acepta un pago menor, Se acepta
  un pago mayor, Excepción autorizada u Otro. Los dos últimos piden detalle. Si el cálculo da
  $ 0,00, hay que escribir el importe. **Base:** `fee_charge_items_change_has_reason`,
  `fee_charge_items_rounding_is_small`, `fee_charge_items_reason_detail`.
- **Fecha:** hoy. Un cobro de un día anterior se carga con el número de talonario de la hoja
  que se entregó ese día, y usa el precio de ese día. **Base:** `fee_charge_item_coherent`
  exige el precio vigente en la fecha de tarifa.
- **Revisar:** el servidor comprueba los datos y muestra el recibo tal como va a salir, sin
  escribir nada. El número es un anticipo.

Los asientos son los de Multas: `funds_received` (D efectivo / C sin identificar) y
`funds_applied` (D sin identificar / C recaudado). **Base:** `fee_charge_funding_coherent`
impide usar una recepción de otra caja, de otro medio, revertida o que supere su importe;
`fee_charge_settled` exige que un cobro pagado tenga su total vinculado y un recibo vigente por
ese total, y se comprueba también al agregar un renglón o un ajuste;
`fee_charge_items_not_on_voided` impide agregar renglones a un cobro anulado;
`funding_allocations_not_from_aranceles` impide financiar cuotas de Haberes.

<a id="recibo"></a>

## Recibo

En papel en blanco, A5 apaisado, con la misma plantilla para la pantalla y la impresora. El
diseño es provisorio: falta el visto bueno del área. Lleva el Expte. tachado, el Período vacío
y la firma vacía, u opcionalmente el nombre y cargo del firmante elegido. Si los renglones no
entran, sigue en otra hoja con el mismo número. Lo que dice queda congelado al emitir. Uno
anulado o reemplazado sale marcado así, para que no se imprima como si valiera.

<a id="errores"></a>

## Anular, reemplazar y corregir

Son hechos distintos:

| Situación | Operación | Permiso | El dinero |
|---|---|---|---|
| Nunca entró | **Anular**, sin depósito vigente | `aranceles.anular` | Se revierte; el recibo queda anulado con su número |
| Error en nombre, CUIT o redacción | **Reemplazar el recibo**, también después de depositar | `aranceles.reemplazar-recibos` | No se mueve; hay número nuevo, y el anterior queda `replaced` |
| Otro tipo o cantidad, mismo importe | **Corregir** | `aranceles.corregir` | No se mueve: la misma recepción se aplica al cobro nuevo |
| Otro importe, sin depositar | **Corregir** | `aranceles.corregir` | Se revierte la recepción y se registra la correcta |
| Otro importe, depósito sin acreditar | Cancelar el depósito y **corregir** | | |
| Otro importe, depósito acreditado | **Sin operación**: pendiente con el área | | |

Corregir pasa en una transacción: el cobro anterior queda anulado y a la vista, nace otro
vinculado a él (`fee_charge_corrections`) y usa la fecha de tarifa del original. Anular y
corregir usan la fecha de hoy, y no se pueden con el día de hoy cerrado. Un reemplazo conserva
la fecha y los hechos del recibo original: la recaudación no cambia de día ni de mes.
Una devolución de dinero no existe todavía: no se resuelve anulando.

**En el libro del día,** anular o corregir el importe otro día deja el recibo en la fecha en que
entró el dinero y agrega una fila «Anulación del recibo N» que resta en la fecha de la reversión.
Con el mismo importe el dinero no se mueve: el renglón de ese día pasa a ser el del recibo nuevo.
