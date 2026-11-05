# Aranceles

Qué hace hoy la Caja de Aranceles. Describe lo implementado.

**Estado:** están el catálogo, el cobro en efectivo con su recibo, la anulación, el reemplazo
del recibo, la corrección, la caja del día con su arqueo, cierre y planilla, el depósito del
efectivo, el pago por depósito y la consulta del mes.

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
  cobro, y es lo que permite depositarlo.
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
- **Foto del talonario:** al escribir el número aparece un campo para adjuntar, de manera
  opcional, una foto o PDF de la hoja. Se guarda como adjunto privado del recibo (`receipt` /
  `talonario_photo`) dentro del mismo acto; si el cobro vuelve atrás, el archivo se elimina. Se
  abre desde la fila del cobro, también si después se reemplaza el recibo, para quien tiene
  `adjuntos.ver`. Un archivo dañado se marca en el campo, sin escribir nada. **Action y
  validación HTTP.**
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
| Nunca entró | **Anular**, sin traspaso vigente | `aranceles.anular` | Se revierte; el recibo queda anulado con su número |
| Error en nombre, CUIT o redacción | **Reemplazar el recibo**, también después de depositar | `aranceles.reemplazar-recibos` | No se mueve; hay número nuevo, y el anterior queda `replaced` |
| Otro tipo o cantidad, mismo importe | **Corregir** | `aranceles.corregir` | No se mueve: la misma recepción se aplica al cobro nuevo |
| Otro importe, sin traspasar | **Corregir** | `aranceles.corregir` | Se revierte la recepción y se registra la correcta |
| Otro importe, traspaso sin acreditar | Cancelar todo el traspaso y **corregir** | | |
| Otro importe, depósito acreditado | **Sin operación**: pendiente con el área | | |
| Pago por banco: el crédito no era de este cobro | **Anular** | `aranceles.anular` | Se revierte; el crédito vuelve a quedar libre y el recibo queda anulado |
| Pago por banco: otro importe | **Corregir** | `aranceles.corregir` | Los mismos créditos pagan el cobro nuevo; lo que sobra queda libre, y si no alcanzan queda pendiente (**provisorio**) |

Corregir pasa en una transacción: el cobro anterior queda anulado y a la vista, nace otro
vinculado a él (`fee_charge_corrections`) y usa la fecha de tarifa del original. Anular y
corregir usan la fecha de hoy, y no se pueden con el día de hoy cerrado. Un reemplazo conserva
la fecha y los hechos del recibo original: la recaudación no cambia de día ni de mes.
Una devolución de dinero no existe todavía: no se resuelve anulando.

**En el libro del día,** anular o corregir el importe otro día deja el recibo en la fecha en que
entró el dinero y agrega una fila «Anulación del recibo N» que resta en la fecha de la reversión.
Con el mismo importe el dinero no se mueve: el renglón de ese día pasa a ser el del recibo nuevo.

<a id="pago-por-deposito"></a>

## Pago por depósito · `/aranceles/pendientes`

Para quien paga por depósito o transferencia. Es una decisión del usuario, **sin relevar con el
área**. No se imprime constancia: el usuario la sacó del alcance.

1. **Alta** (`aranceles.cobrar`): en `/aranceles/nuevo`, «Depósito o transferencia». El cobro queda
   pendiente, con el precio de hoy. No mueve dinero ni emite recibo. No vence y conserva su precio,
   aunque el precio cambie después (**provisorio**).
2. **Identificar** (`aranceles.verificar-pagos`): desde Pendientes se eligen uno o más créditos, o
   desde **Banco › Movimientos**, «Identificar como arancel». Solo créditos desde la apertura de la
   caja y dentro de la vigencia de la cuenta. Se usan del más antiguo al más nuevo y de cada uno se
   toma lo necesario.
3. **Recibo:** sale al completar el total, uno solo con todos los hechos, con fecha del día.

- **De más:** lo que sobra del crédito queda libre, sin identificar, para otro cobro o para otra
  caja. Un crédito usado en parte se sigue ofreciendo por lo libre, solo en Aranceles.
- **De menos:** queda como ingreso parcial, vinculado y protegido, con lo que falta a la vista. Ese
  dinero espera sin identificar y no es recaudación hasta el recibo.
- **Ajustar** (`aranceles.modificar-importe`): cambia el importe de cada renglón de un pendiente,
  de más o de menos, con motivo. El original se conserva. Si lo recibido alcanza el total nuevo, sale
  el recibo. El total no puede quedar por debajo de lo recibido. Se envían solo los renglones
  editados: si otra persona cambió uno mientras tanto, se rechaza con un aviso.
- **Anular** (`aranceles.anular`): con un ingreso parcial, revierte la recepción y el crédito vuelve a
  quedar libre, y no se puede con el día de hoy cerrado. Sin dinero no hay asiento, así que se puede
  aunque el día esté cerrado, igual que dar de alta un pendiente (**provisorio**).
- **Sin ventanilla** (`aranceles.verificar-pagos`): desde el crédito se carga quién pagó y qué, y el
  cobro nace ahí. Usa el precio de la fecha del más antiguo de los créditos elegidos (**provisorio**);
  uno que se sume después no lo recalcula.

**En el libro del día,** cada día muestra lo que entró al banco ese día: «Ingreso por banco, sin
recibo todavía» y, al emitirlo, el mismo importe con el número del recibo. Un ingreso parcial anulado
otro día agrega su reversión en esa fecha.

**Cuenta compartida:** cada crédito ofrece lo de cada caja, y quien identifica decide. Identificar no
hace falta para cerrar el día. Lo que no sea de Aranceles queda sin identificar.

**Base:** `fee_charge_settled` exige que un pendiente no tenga recibo, fecha de pago ni dinero
aplicado, y que un pagado tenga todo aplicado; `fee_charge_funding_coherent` impide usar dos veces una
recepción; `fee_charge_funding_born_unapplied` hace nacer un vínculo sin aplicar;
`fund_receipts_fee_bank_has_a_charge` exige que una recepción por banco de la caja esté vinculada por
todo su importe o revertida. Los bloqueos van en orden: cobro, créditos y recepciones.

<a id="movimientos"></a>

## Movimientos del mes · `/aranceles/movimientos`

Se llega desde el mes del calendario de la caja, con `aranceles.ver`. **Es una consulta con Excel, no
una rendición formal:** el área todavía no dio un modelo (**provisorio**). Cada sección dice qué fecha
filtra y se suma sola, sin total general: un cobro y su traspaso son hechos distintos del mismo dinero.
Pantalla y Excel leen todas las secciones de una misma foto: si alguien cobra mientras se arma, el
resumen y el detalle siguen coincidiendo.

- **Recaudación por tipo:** cantidad, efectivo, banco y total de cada arancel, con su detalle por
  renglón. Cuenta por la fecha del primer recibo, con el importe vigente. Coincide con lo que el libro
  aplicó en el mes.
- **Anulaciones y correcciones:** son filas negativas en su propio mes. El mes anterior no cambia, y
  anulado en el mismo mes da cero. Un reemplazo no mueve nada: solo muestra el recibo y el nombre vigentes.
- **Ingresos parciales:** lo que entró por el banco y no tenía recibo al terminar el mes. No es
  recaudación. Sigue en su mes aunque el cobro se complete después, y su reversión resta en la suya.
- **Traspasos y acreditaciones:** movimiento de fondos, no recaudación. Un traspaso figura en su mes
  y su cancelación es una fila negativa en la suya: el mes del traspaso no cambia, y cancelado en el
  mismo mes da cero.
- **Importes distintos de lo calculado:** cada renglón que cambió alguna vez, por el mes del recibo,
  con su neto y lo que aportó cada motivo. También uno que volvió a lo calculado: el neto da cero, pero
  hubo cambios. Los totales por motivo suman cada paso por separado, así que ajustar de $ 120 a $ 100
  por pago menor y a $ 99,50 por redondeo da − $ 20 y − $ 0,50, y entre todos dan el total de la
  sección. Es para control: la diferencia ya está dentro de la recaudación.

Los filtros de medio y tipo aplican a la recaudación y a las diferencias; el resto muestra el mes
entero. La pantalla muestra hasta 100 filas por sección, y el Excel las trae todas, con el rótulo de
que es una consulta.

<a id="deposito"></a>

## Depósito del efectivo · `/aranceles/depositos`

El efectivo se acumula y se deposita junto, en dos pasos. El circuito es una decisión del
usuario, **sin relevar con el área**: falta saber cuándo sale el efectivo, quién lo registra y si
se lleva siempre todo.

1. **Traspaso** (`aranceles.depositar`): se registra cuando el efectivo sale. Propone todo lo
   disponible y se puede bajar el importe; el resto sigue en caja. El sistema lo reparte desde la
   recepción más antigua, incluida la de la apertura, y muestra de qué recibos sale antes de
   confirmar. Asienta `cash_deposited_to_bank`: D tránsito / C efectivo. No pide foto.
2. **Comprobante** (`aranceles.depositar`): se carga después, con foto obligatoria, sin asiento.
   El importe debe ser el del traspaso: si difiere se rechaza, y se cancela para registrar otro.
3. **Acreditación** (`aranceles.verificar-pagos`): con el comprobante cargado, se asocia con un
   crédito del extracto por el mismo importe. Se busca alrededor de la fecha del comprobante.

Con el comprobante pendiente el día se arquea y se cierra, y al otro día se sigue cobrando:
cargarlo no toca el día ya cerrado. Depositar no suma recaudación: solo cambia dónde está la plata.

- **Fecha:** la toma el sistema al iniciar el traspaso, con su hora de registro. Solo
  `aranceles.fechar-traspaso` (administrador) puede indicar un día pasado que siga abierto, y entonces
  solo toma recepciones hasta esa fecha. Un día cerrado se reabre o el traspaso se registra hoy.
- **Tope:** lo disponible por recepciones y el saldo de efectivo del libro **desde esa fecha en
  adelante, día por día** (`DepositableCash::maximum`). La pantalla, la vista previa y el traspaso
  usan el mismo cálculo. Así, un faltante ajustado no deja trasladar más de lo que hay, y una fecha
  pasada no deja negativo un día intermedio. Después de cancelar un traspaso, su asiento inverso
  queda con fecha de la cancelación: si no hay otro efectivo que lo cubra, el nuevo no puede ir con
  fecha anterior a ella.
- **Comprobante:** lleva la fecha del papel. Puede ser anterior al traspaso (un traspaso cancelado
  por error se registra de nuevo con la fecha del día), pero no al último cobro que lleva.
- **Sobrante de arqueo ajustado:** no tiene recepción y queda en la caja. **Sin decidir** si se
  puede depositar.
- **Cancelar** (`aranceles.cancelar-traspaso`: administrador, contador, administrativo): mientras no
  esté acreditado, con o sin comprobante, con motivo auditado. Vuelve el efectivo a la caja y libera
  todo el reparto, aunque el error sea de un solo cobro.
- **Mismo formulario enviado dos veces**, aun a la vez: devuelve el mismo traspaso.

La fecha automática, el permiso para otra fecha y quién cancela son decisión del usuario, **sin
relevar con el área**, igual que el tope y el comprobante anterior al traspaso (**provisorios**).

**Base:** `cash_transfer_item_within_receipt` impide que un traslado lleve recepciones de otra caja,
en todas las cajas, que el efectivo viaje por más que el importe de su recepción, y que salga de
esta caja efectivo que no sea vigente; `cash_transfer_ticket_after_cash` impide un comprobante
anterior al efectivo que lleva; `fund_receipts_keep_fee_transfers` impide revertir una
recepción con traslado vigente; `cash_transfer_items_match_amount_*` exige que los ítems sumen el
traslado, en todas las cajas. `cash_transfer_follows_two_steps` hace nacer `awaiting_ticket` solo a
las cajas con `deposits_in_two_steps`, y las transiciones de `cash_to_bank_transfers_append_only`
impiden acreditar sin comprobante y editar uno cargado. `cash_transfer_ticket_has_photo` exige la
foto.
