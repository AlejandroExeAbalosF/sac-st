# Multas

Qué hace hoy la Caja de Multas. Describe lo implementado; las reglas con su
garantía en la base están en [reglas de Multas](multas-reglas.md).

**Estado: en construcción.** Está la cuenta corriente —expedientes, documentos,
multas, su obligación y los pagos anteriores al sistema—, la apertura de la caja
con su cuenta bancaria compartida, el cobro en caja con su recibo, los pagos por
banco, el recurso del 20 % y el depósito al banco de lo cobrado. Faltan el cheque
rechazado y su reemplazo, y la caja del día con su arqueo y cierre.

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
- **Pagos**: los del sistema, con su medio, su estado, su recibo, si fue el
  cancelatorio, si se presentó en término, su comprobante y, si sobró, cuánto
  quedó de excedente. Desde acá se cobra en caja, se informa, corrige o verifica
  un pago por banco y se registra el recurso.
- **Pagos anteriores al sistema**, con su respaldo, su revisión, su comprobante y,
  si vino del banco, el crédito que lo respalda.

### Pagos por verificar · `/multas/pagos`

La cola de quien concilia (`multas.verificar-pagos`): los pagos informados por el
banco que esperan su crédito, del más viejo al más nuevo. Cada fila lleva a la
ficha, donde se verifica, se corrige o se descarta.

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
Multas en el banco —lo que el libro de Multas tiene ahí: los depósitos de la caja
ya acreditados y los pagos por banco ya verificados—. Debajo, los créditos posteriores al corte que todavía nadie
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

<a id="deposito"></a>

## Depósito al banco

Lo cobrado en la caja va al banco **entero y solo**: cada cobro en su propio
depósito, por su importe completo —el excedente incluido—, sin fraccionarlo ni
juntarlo con otros (M06). La caja de Multas lista abajo de la cuenta los **cobros
por depositar** y lo **depositado, sin acreditar**; la ficha de la multa dice en
cada cobro de caja dónde está la plata.

- **Depositar** (permiso `multas.depositar`): una pantalla para transcribir el
  ticket del cajero —fecha, hora, operación, terminal— con la **foto obligatoria**
  al lado, como en Haberes. El importe no se escribe: es el cobro entero. La
  cuenta no se elige: es la del libro de la caja. La fecha no puede ser futura ni
  anterior al cobro. El cheque sale de custodia; el efectivo, de la caja; los dos
  quedan en tránsito.
- **Acreditar** (`multas.verificar-pagos`): se busca el crédito del extracto, como
  en Haberes, y se confirma. Tiene que ser un **crédito entero y sin otro uso**: el
  remanente de un crédito mayor no es este depósito. Acreditado, la plata está en
  el banco y el crédito ya no queda libre para otro pago.
- **Cancelar** (`multas.verificar-pagos`): un depósito que no ocurrió o se cargó
  mal, con su motivo, mientras no se acreditó. El cobro vuelve a la caja y se
  puede depositar de nuevo; el cancelado queda a la vista con su motivo.

Depositar no es cobrar otra vez: la deuda no se mueve y el recibo no cambia.

<a id="banco"></a>

## Pagos por banco

«Pago por banco», en la ficha, registra una **transferencia, un depósito directo o
un cheque electrónico** (M02):

- **Informar** (quien cobra o quien concilia): el pago queda *informado*, sin tocar
  el saldo ni llevar recibo. Se carga el importe, cuándo se presentó el comprobante
  —hoy, o un día anterior con su respaldo: el sello de Mesa de Entradas, una nota—,
  la fecha de pago que dice el comprobante y, si es una transferencia judicial, el
  expediente judicial. El comprobante se adjunta al pago («Comprobante»).
- **Verificar** (`multas.verificar-pagos`): se elige el crédito del extracto. Tiene
  que ser de la cuenta de Multas, posterior al corte y a la apertura, libre y **por
  el importe entero del pago**: un pago, un crédito, hasta que el área responda P14.
  Al verificar, el pago se aplica, sale el recibo (casilla «Depósito Cta.») y el
  crédito deja de estar en la cola de la caja.
- **Reconocerlo del extracto**: quien concilia puede marcar «el crédito ya está en
  el extracto» y hacer las dos cosas en un acto, por el importe del crédito. La
  presentación es ese momento: identificar después del cierre del mes de la multa
  es fuera de término (M08).
- **Corregir** (quien informa): un informado mal cargado —el importe, el medio, la
  fecha de pago— se reemplaza por otro. El anterior queda descartado con el motivo,
  y el corregido conserva la misma presentación: la fecha de presentación no se
  corrige. Se puede corregir aunque otro pago ya haya cancelado la multa; lo que
  sobre queda como excedente al verificarlo. Un depósito de recurso se corrige
  como depósito: si se pagó en efectivo, se descarta y se cobra en la caja. Lo ya
  aplicado tiene recibo y no se corrige así: anular un pago está pendiente (P13).
- **Descartar** (`multas.verificar-pagos`): un informado que no corresponde, con su
  motivo. Nunca tocó el saldo.

Una transferencia es de una sola multa (M06). El cheque físico sigue entrando solo
por la caja (P15).

<a id="recurso"></a>

## Recurso del 20 %

«Recurso (20 %)» registra el pago para recurrir, jerárquico o en apelación (M07).
**Se cobra el importe del recibo externo** que trae el sancionado: la liquidación
la hace otra oficina. El sistema muestra al lado el 20 % que calcula sobre el
importe vigente, con sus ajustes y sin descontar pagos, **sin centavos**, con la
base congelada; si difieren, se cobra igual y la diferencia queda a la vista en la
multa y en la auditoría.

- En **efectivo** se cobra en el acto y sale el recibo; por **depósito directo**
  queda informado y se verifica como cualquier pago por banco. El depósito puede
  haberse presentado antes de cargarlo: se indica el día y su respaldo, y eso
  decide el término.
- **Una multa admite un solo recurso.** Con uno registrado, la ficha no ofrece otro y
  el sistema lo rechaza. Un intento descartado no cuenta.
- Un depósito de recurso mal cargado se **corrige** como cualquier informado.

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
| `multas.cobrar` | Cobrar en la caja en efectivo o con cheque y emitir el recibo; informar un pago por banco; registrar un recurso | administrador, administrativo |
| `multas.depositar` | Depositar en el banco un cobro de la caja, con el ticket del cajero | administrador, administrativo |
| `multas.verificar-pagos` | Informar, verificar contra el extracto y descartar pagos por banco; ver la cola de pagos por verificar; acreditar o cancelar un depósito de la caja | administrador, contador |
| `multas.caja.abrir` | Abrir la caja de Multas, una sola vez | administrador |
| `banco.cuentas.declarar-corte` | Declarar el corte de una cuenta compartida, una sola vez | administrador |

Un permiso que se crea por primera vez llega con su asignación de fábrica también a
los roles ya configurados (`RolesAndPermissionsSeeder`); uno que ya existía no se
toca.
