# Multas

Qué hace hoy la Caja de Multas. Describe lo implementado; las reglas con su
garantía en la base están en [reglas de Multas](multas-reglas.md).

**Estado: en construcción.** Está la cuenta corriente —expedientes, documentos,
multas, su obligación y los pagos anteriores al sistema— y la apertura de la caja
con su cuenta bancaria compartida. Todavía **no hay cobros** ni recibos: llegan en
la etapa siguiente.

---

<a id="idea"></a>

## La idea

Multas reemplaza la cuenta corriente que el área lleva en Excel: una fila por
multa, con su expediente, su resolución, sus actas, la carátula, el importe y lo
que se pagó.

Tres decisiones ordenan el módulo:

- **El papel se guarda como vino.** Cada número —expediente, resolución, acta— y la
  carátula se guardan tal como se leyeron, y al lado su forma normalizada (número,
  año, sufijo). El texto original no cambia nunca; la lectura se completa o se
  corrige con su rastro.
- **La obligación es un libro.** El importe de la multa no es una columna: es su
  importe original más los ajustes que dispongan las resoluciones. Nada se edita;
  lo cargado por error se revierte entero.
- **El saldo se calcula.** Lo que se debe, lo pagado y el excedente salen de la
  vista `fine_balances` cada vez que se miran.

Multas es hermano de Haberes: usa el mismo motor (Ledger, Banking, Shared) y no lo
conoce. El test de fronteras lo exige.

---

<a id="pantallas"></a>

## Pantallas

| Pantalla | Ruta | Permiso |
| --- | --- | --- |
| Cuenta corriente | `/multas` | `multas.ver` |
| Nueva multa | `/multas/nueva` | `multas.registrar` |
| Ficha de la multa | `/multas/{id}` | `multas.ver` |
| Expediente | `/multas/expedientes/{id}` | `multas.ver` |
| Exportar la cuenta corriente | `/multas/exportar` | `multas.ver` |
| Caja de Multas | `/multas/caja` | `multas.ver`; abrirla, `multas.caja.abrir` |

### Cuenta corriente · `/multas`

Busca por lo que trae quien viene a pagar: número de expediente («169607/24»
encuentra «169607/2024-0»), de resolución, de acta, o la carátula. Filtra por
deuda (con o sin) y por año de registro. Cada fila dice el importe vigente, el
saldo y la situación: pendiente, con pagos parciales, cancelada o sin efecto, y si
tiene excedente.

**Exportar a Excel** baja lo que está filtrado, entero y sin paginar: una fila por
multa con expediente, carátula, persona, documentos de origen, fecha de registro,
importe vigente, pagado en el sistema, pagado antes del sistema, saldo, excedente,
estado y procedencia. Los importes van escritos («1.400.000,00»), como en pantalla,
no como número de Excel. Todo el archivo sale de una misma foto de los datos: lo
que otro carga o corrige mientras se baja —una fecha, una carátula, un ajuste— no
repite ni saltea filas ni mezcla momentos, y quien corrige no espera.

### Nueva multa · `/multas/nueva`

Una multa se carga con todo lo que trae el papel:

- **Expediente.** Al escribir el número se pregunta si ya está cargado. Si está, la
  multa se le agrega y se ofrecen sus documentos —un acta compartida por dos
  multas se carga una vez—. Si hay otro con el mismo número y año y distinto
  sufijo, se avisa: no se fusionan solos.
- **Resolución.** Una del expediente o una nueva, con su fecha: es la fecha del
  importe original.
- **Actas.** Las del expediente o nuevas, con su tipo (laboral o higiene y
  seguridad). Pueden no tener año.
- **Carátula, persona y fecha de registro.** La persona es opcional: la carátula
  alcanza para identificar al sancionado y es la que va a llevar el recibo. El mes
  de la fecha de registro es el período de origen de la multa.

### Ficha · `/multas/{id}`

Arriba, el saldo: importe vigente, pagado (separando lo anterior al sistema),
saldo y estado. Abajo:

- **Datos**, con la carátula y el expediente tal como vinieron.
- **Documentos**, con el papel que cumple cada uno: origen, modificación,
  ratificación o respaldo. Vincular una ratificación no mueve la obligación.
- **Obligación**: el importe original y cada ajuste, con su resolución, su fecha,
  su motivo y quién lo cargó. Un ajuste revertido queda tachado.
- **Archivos**: los de la multa y, debajo, los del expediente.
- **Pagos**: los cobrados en el sistema, con su medio, su recibo, si fue el
  cancelatorio, si se presentó en término y, si sobró, cuánto quedó de
  excedente. «Cobrar en caja» abre el cobro.
- **Pagos anteriores al sistema**, con su respaldo, su revisión, su comprobante y,
  si vino del banco, el crédito que lo respalda.

### Expediente · `/multas/expedientes/{id}`

Sus documentos —se pueden cargar antes que la multa—, sus archivos y las multas que
originó.

---

<a id="adjuntos"></a>

## Archivos

La foto o el PDF de un papel se adjunta a quien es dueño de ese papel:

- **Expediente**: la resolución, las actas, cualquier papel del expediente. Lo
  muestran todas sus multas.
- **Multa**: lo que respalda esa multa en particular.
- **Pago anterior**: su comprobante —recibo, boleta, planilla—, que ayuda a
  validarlo.

No se pide adjuntar nada para cargar: se completa cuando aparece el papel. Se
adjunta con el permiso de cargar (`multas.registrar`) y se abre con el de ver
adjuntos (`adjuntos.ver`); sin él se lista el nombre, sin enlace.

Entra por el mismo camino que el resto de los adjuntos del sistema: disco privado,
validado por su contenido (JPG, PNG, WEBP o PDF, hasta 10 MB), las fotos
normalizadas a WEBP y la huella del archivo guardado. Un archivo no se edita ni se
borra: si se subió el equivocado, se sube el correcto con una descripción que lo
diga. Cada uno queda en la auditoría; el comprobante de un pago anterior, sobre su
multa.

---

<a id="ajustes"></a>

## Ajustes de la obligación

Cuando una resolución cambia el importe se registra un **aumento** o una
**reducción**, con la resolución que lo dispone (del expediente o nueva) y el
motivo. El importe original no se toca.

Si la obligación queda por debajo de lo pagado, la deuda es cero y la diferencia es
**excedente**: queda a la vista, no se devuelve ni se acredita sola.

Un ajuste cargado por error se **revierte entero**, por el mismo importe. Si solo
una parte estaba mal, se registra otro ajuste. El importe original no se revierte:
una multa que queda sin efecto se reduce a cero con su resolución.

---

<a id="antecedentes"></a>

## Pagos anteriores al sistema

Un pago que se hizo antes de este sistema —el que figura en una columna mensual del
Excel, un recibo de papel— se registra con su importe, su fecha (puede ser solo el
mes) y el respaldo que lo prueba.

Si el pago fue una transferencia que el extracto ya trae —un crédito de la cuenta
de Multas **anterior a la apertura de la caja**—, se registra eligiendo ese crédito:
el importe y la fecha son los del banco, por el crédito entero (un pago no se
reparte), y el mismo crédito no respalda otro pago ni se cobra después. Si se
descarta, el crédito queda libre para el pago correcto.

No es plata que entra hoy: ya se rindió. Por eso **no genera asientos**. Queda
pendiente de revisión y cuenta en el saldo recién cuando alguien con permiso de
ajustar lo **valida**. Descartarlo pide el motivo. Se revisa una sola vez.

Una **devolución** anterior se valida solo si hay pagos anteriores validados que
la cubran; si no, queda pendiente hasta validar el pago que devuelve.

---

<a id="caja"></a>

## Caja y cuenta bancaria

La cuenta bancaria de Multas es **compartida con Aranceles**, y su saldo no se
puede repartir entre las dos (M08, M12). Por eso hay dos aperturas distintas:

- **El corte de la cuenta** (Banco › Cuentas, permiso `banco.cuentas.declarar-corte`):
  el saldo que informa el banco al cierre de un día, una sola vez y sin repartir,
  con su comprobante. Si un extracto importado atraviesa ese día —tiene filas
  antes y después—, el saldo tiene que ser el suyo; si no, se acepta y la pantalla
  dice que la verificación queda pendiente hasta que llegue ese archivo. Lo que el
  extracto trae hasta ese día queda adentro y **no entra a ningún libro como
  cobro**. La pantalla muestra si el saldo al corte más lo movido hasta el último
  saldo informado cierra contra ese saldo, y cuántos movimientos posteriores
  todavía no se pueden contrastar.
- **La apertura de la caja** (`/multas/caja`, permiso `multas.caja.abrir`): como la
  de Haberes —fecha, efectivo contado por billete, cheques detallados, total
  calculado—, pero **sin saldo bancario** y diciendo con qué cuenta opera. Abre
  después del corte de esa cuenta; la pantalla sugiere el día siguiente.

Abierta la caja, la pantalla muestra la cuenta en **dos números que no se suman
nunca**: el saldo según el banco —la cuenta entera, compartida— y los cobros de
Multas en el banco —lo que el libro de Multas tiene ahí, en cero hasta que existan
los cobros—. Debajo, los créditos posteriores al corte que todavía nadie
identificó: es la cola de «pendiente de clasificación de caja». Un crédito que ya
respalda un pago anterior vigente sale de la cola; si ese pago se descarta,
vuelve.

La importación de extractos no cambia: es por cuenta, con su control de
continuidad, vista previa y movimientos repetidos, y no depende de la caja. Un
mismo crédito no lo pueden consumir dos cajas por encima de su importe.

---

<a id="cobro"></a>

## Cobro en caja

«Cobrar en caja», en la ficha (permiso `multas.cobrar`, con la caja abierta en la
moneda de la multa y saldo pendiente), registra un pago en **efectivo o con
cheque** y en el mismo acto lo aplica a la multa y emite el recibo (M02):

- **Importe**: arranca en el saldo, pero se cobra lo que se recibe. Un pago
  parcial deja saldo; uno que lo supera cancela la multa y lo que sobra queda como
  **excedente pendiente**, que no se devuelve ni se acredita solo (M05). El cheque
  se recibe por su importe entero y queda en custodia (M06); si es una **orden de
  pago judicial**, se marca y se anota el expediente judicial.
- **Fecha**: hoy. Un cobro de un día anterior se carga con el número del **recibo
  de talonario** que se entregó ese día: ese papel respalda la fecha de
  presentación.
- **Recibo**: de la serie de Multas (`0210/…`, configurable en Numeración),
  «recibí de» la carátula de la multa, con su concepto —pago parcial o
  cancelatorio de la multa por su resolución—, el expediente administrativo y el
  total de la multa. Se ve e imprime desde la fila del pago, sobre papel en blanco
  o sobre la hoja del talonario. El del pago que deja la deuda en cero es el
  **cancelatorio**: lista los pagos anteriores, con la fecha que se conoce de cada
  uno, y separa el excedente. Lo que dice queda congelado, incluida la cuenta de
  depósito: un ajuste posterior de la multa o un cambio en la cuenta no lo cambian
  (M09). Una hoja del talonario ya usada se rechaza en el campo.
- **Término** (M08): el pago se juzga contra el cierre mensual de la caja de
  Multas del mes en que se registró la multa. Sin cierre todavía, en término;
  presentado después del cierre, fuera de término; sin fecha de registro, o
  presentado el mismo día del cierre cuando solo se sabe el día, queda «a
  revisar». Se calcula una vez y no cambia si el mes se reabre.

Un ajuste de la obligación, su reversión o validar un pago anterior pueden
cambiar cuánto de lo ya cobrado es excedente: el sistema lo reclasifica en el mismo
acto, sin mover dinero.

Anular un pago o cambiar su medio (P13) no está habilitado.

---

<a id="correcciones"></a>

## Completar y corregir datos

- **Completar** es llenar un dato que faltaba o precisar una fecha sin moverla de
  mes. Lo hace quien carga y no pide motivo.
- **Corregir** es cambiar un dato que ya tenía valor. Pide motivo y el permiso de
  ajustar.

En los dos casos el cambio queda como una revisión con el valor anterior y el
nuevo. Dejar menos precisa una fecha —de un día a «solo el mes»— es corregir.

El formulario recuerda lo que mostraba al abrirse: lo que el operador no tocó no
pisa lo que otro cambió mientras tanto, y lo que sí tocó se rechaza si otro lo
cambió primero. Corregir el número de un expediente hacia uno que ya existe se
rechaza: los expedientes no se fusionan desde acá.

La **fecha** de un movimiento de la obligación o de un pago anterior también se
completa o corrige —«Fecha» en la ficha—, para los importados que llegaron sin
ella. El importe y su efecto sobre el saldo no cambian.

---

<a id="permisos"></a>

## Quién puede qué

| Permiso | Para qué | Roles por omisión |
| --- | --- | --- |
| `multas.ver` | Consultar la cuenta corriente | administrador, contador, administrativo, consulta |
| `multas.registrar` | Cargar expedientes, documentos y multas; completar datos; registrar pagos anteriores; adjuntar archivos | administrador, administrativo |
| `multas.ajustar` | Ajustar y revertir; corregir datos ya cargados; validar o descartar pagos anteriores | administrador, contador |
| `multas.cobrar` | Cobrar en la caja en efectivo o con cheque y emitir el recibo | administrador, administrativo |
| `multas.caja.abrir` | Abrir la caja de Multas, una sola vez | administrador |
| `banco.cuentas.declarar-corte` | Declarar el corte de una cuenta compartida, una sola vez | administrador |

Un permiso que se crea por primera vez llega con su asignación de fábrica también a
los roles ya configurados (`RolesAndPermissionsSeeder`); uno que ya existía no se
toca.
