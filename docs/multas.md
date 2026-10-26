# Multas

Qué hace hoy la Caja de Multas. Describe lo implementado; las reglas con su
garantía en la base están en [reglas de Multas](multas-reglas.md).

**Estado: en construcción.** Están los expedientes y sus multas —documentos,
obligación y pagos anteriores al sistema—, la apertura de la caja
con su cuenta bancaria compartida, el cobro en caja con su recibo, los pagos por
banco, el recurso del 20 %, el depósito al banco de lo cobrado y el cheque
rechazado con su reemplazo, la caja del día con su arqueo, su cierre y su
planilla, la cola de créditos sin identificar de la cuenta compartida y la
consulta operativa del mes. Falta la rendición oficial, que espera el modelo del
área (P02).

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
| Expedientes y multas | `/multas/expedientes` | `multas.ver` |
| Nueva multa | `/multas/nueva` | `multas.registrar` |
| Ficha de la multa | `/multas/{id}` | `multas.ver` |
| Expediente | `/multas/expedientes/{id}/{número}` | `multas.ver` |
| Agregar multa al expediente | `/multas/expedientes/{id}/{número}/agregar-multa` | `multas.registrar` |
| Exportar las multas | `/multas/exportar` | `multas.ver` |
| Caja de Multas | `/multas/caja` | `multas.ver`; abrirla, `multas.caja.abrir` |
| Caja del día | `/multas/caja/dia` | `multas.caja.ver` |
| Arqueos | `/multas/caja/arqueos` | `multas.caja.ver`; contar, `multas.caja.arquear` |
| Cierres | `/multas/caja/cierres` | `multas.caja.ver`; cerrar, `multas.caja.cerrar` |
| Calendario | `/multas/caja/calendario` | `multas.caja.ver` |
| Movimientos del mes | `/multas/movimientos` | `multas.ver` |

<a id="listado"></a>

### Expedientes y multas · `/multas/expedientes`

Dos lecturas del mismo material, como el listado de Haberes: la pestaña
**Multas**, que es la que abre, y la pestaña **Expedientes**
(`?vista=expedientes`). Comparten el buscador, los filtros y la paginación.

Busca por lo que trae quien viene a pagar: número de expediente («169607/24»
encuentra «169607/2024-0»), de resolución, de acta, o la carátula. Filtra por
deuda (con o sin) y por año de registro.

- **Multas**: una fila por multa, con el importe vigente, el saldo y la situación:
  pendiente, con pagos parciales, cancelada o sin efecto, y si tiene excedente.
- **Expedientes**: una fila por expediente, con las carátulas de sus multas,
  cuántas tiene y cuántas deben, el importe vigente y el saldo sumados —uno por
  moneda: pesos y dólares no se suman en una cifra— y si debe o no. Un expediente
  aparece si **alguna** de sus multas coincide con la búsqueda o se registró en el
  año pedido; en cambio, «sin deuda» quiere decir que **ninguna** de sus multas
  debe.

**Exportar a Excel**, solo en la pestaña Multas, baja lo que está filtrado,
entero y sin paginar: una fila por multa con expediente, carátula, persona, documentos de origen, fecha de registro,
importe vigente, pagado en el sistema, pagado antes del sistema, saldo, excedente,
estado y procedencia. Los importes van escritos («1.400.000,00»), como en pantalla,
no como número de Excel. Todo el archivo sale de una misma foto de los datos: lo
que otro carga o corrige mientras se baja —una fecha, una carátula, un ajuste— no
repite ni saltea filas ni mezcla momentos, y quien corrige no espera.

### Nueva multa · `/multas/nueva`

Una multa se carga con todo lo que trae el papel:

- **Expediente.** Al escribir el número se pregunta si ya está cargado. Si está,
  un aviso al lado del campo lo dice y lleva a su ficha; la multa se le agrega y
  se ofrecen sus documentos —un acta compartida por dos multas se carga una vez—. Si hay otro con el mismo número y año y distinto
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
  ratificación o respaldo, y debajo de cada uno su archivo. Vincular una
  ratificación no mueve la obligación.
- **Obligación**: el importe original y cada ajuste, con su resolución, su fecha,
  su motivo y quién lo cargó. Un ajuste revertido queda tachado.
- **Archivos de la multa**: los de la multa y, debajo, los del expediente.
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

### Expediente · `/multas/expedientes/{id}/{número}`

La dirección lleva el id y, en otro tramo, el número del papel con guiones:
`/multas/expedientes/75/169607-2024-0`. **El id identifica; el número solo
describe.** Si falta, o quedó viejo porque el número se corrigió, la pantalla
redirige a la dirección actual: una dirección guardada sigue andando. Un expediente
importado sin número normalizado va solo con su id. Un número suelto en la barra
no es una dirección: para buscar por número está el buscador.

No sigue la forma de Haberes (`125958-2026-7`, el id pegado al número) a
propósito: allá el número tiene un solo guion, pero acá el sufijo también lleva
guion, y `169607-2024-75` no decía si 75 era el sufijo o el id. Los enlaces de las
pantallas reciben el número del servidor (`routeNumber`) y llegan directo a la
dirección actual.

**Agregar multa** abre el mismo formulario de Nueva multa con el expediente fijo:
el número se muestra pero no se escribe, no se ofrecen observaciones ni archivo del
expediente, y el alta lo identifica por id —así funciona también con un expediente
importado sin número—.

Arriba, los **archivos del expediente**: la carátula u otro papel suelto. Después,
sus documentos —se pueden cargar antes que la multa—, cada uno con su archivo y su
botón para adjuntarlo, y las multas que originó.

---

<a id="adjuntos"></a>

## Archivos

La foto o el PDF de un papel se adjunta a quien es dueño de ese papel:

- **Documento**: la foto o el PDF de una resolución o de un acta va en ese
  documento. Como el documento se comparte, lo ven cada expediente y cada
  multa que lo usan, debajo del documento, sin duplicarse.
- **Expediente**: un papel del expediente que no es la resolución ni un acta
  —la carátula, una nota—. Lo muestran todas sus multas. Lo que se subió acá
  antes de que cada documento tuviera su archivo queda donde está: un adjunto
  no se mueve.
- **Multa**: lo que respalda esa multa en particular.
- **Pago anterior**: su comprobante —recibo, boleta, planilla—, que ayuda a
  validarlo.

**Nueva multa** ofrece un archivo opcional en cada bloque: el del expediente, el
de la resolución —nueva o ya cargada— y el de cada acta nueva. Las actas que
el expediente ya tenía reciben el suyo desde la ficha del expediente. Todo entra
en el mismo acto que la multa: si algo falla no queda la multa a medias ni un
archivo suelto en el disco.

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
puede repartir entre las dos (M08, M12): cada caja reconoce solo lo que
identifica. Por eso la caja no declara un saldo bancario:

- **La apertura de la caja** (`/multas/caja`, permiso `multas.caja.abrir`): como la
  de Haberes —fecha, efectivo contado por billete, cheques detallados, total
  calculado—, pero **sin saldo bancario** y diciendo con qué cuenta opera. Su
  fecha es la frontera: lo que el extracto trae antes ya estaba en el saldo que el
  área traía y **no entra al libro como cobro**; solo puede respaldar un pago
  anterior de una multa.

No hay un corte declarado para la cuenta: el saldo del banco lo trae el extracto
(ver [corte de la cuenta compartida](#sin-corte-de-cuenta)).

Abierta la caja, la pantalla muestra la cuenta en **dos números que no se suman
nunca**: el saldo según el banco —el último que informó un extracto, de la cuenta
entera y compartida— y lo **reconocido en el libro de Multas** —lo que el libro
tiene asentado ahí: los depósitos de la caja ya acreditados y los pagos por banco
ya verificados, menos los débitos de cheques rechazados—. Lo segundo no es la
parte de Multas en el saldo del banco: de las salidas de la cuenta solo se conocen
las que el libro registró. Debajo, los créditos desde la apertura que todavía nadie
identificó: es la cola de «pendiente de clasificación de caja». Un crédito que ya
respalda un pago anterior vigente sale de la cola; si ese pago se descarta,
vuelve.

La importación de extractos no cambia: es por cuenta, con su control de
continuidad, vista previa y movimientos repetidos, y no depende de la caja. Un
mismo crédito no lo pueden consumir dos cajas por encima de su importe.

<a id="sin-corte-de-cuenta"></a>

### Sin corte de la cuenta compartida

Hubo un «corte» que declaraba una vez, para la cuenta entera, el saldo del banco
a una fecha. Se sacó el 23/10/2026: no lo necesita ni Multas ni Aranceles. El
saldo compartido no se reparte, cada caja reconoce solo lo que identifica, la
frontera entre lo viejo y lo nuevo la pone la apertura de cada caja, y el saldo
del banco lo trae el extracto. La migración `drop_bank_account_cutoffs` se niega
a correr si encuentra un corte cargado.

<a id="sin-identificar"></a>

### Créditos sin identificar

Debajo de la cuenta, la caja lista los créditos desde su apertura que nadie
reconoció, del más viejo al más nuevo y de a cincuenta por página (M08): los de
Aranceles se quedan en la cola, y sin recorrerla los de atrás no se alcanzarían.
Lo anterior a la apertura no está en la cola: se registra como pago anterior
desde la ficha de la multa. Son **de la cuenta
compartida, no una deuda de Multas**: lo que no sea de Multas queda en la cola, sin
asignarle una caja por descarte. El número del panel sale de la misma consulta, así
que no cuenta lo ya imputado —la acreditación de un depósito de la caja, un pago
verificado— ni lo que respalda un pago anterior vigente.

**Identificar** (`multas.verificar-pagos`) tiene dos caminos:

- **Alguien lo informó**: la cola ofrece primero los pagos informados del mismo
  importe. Se verifica ese pago, que conserva su presentación —la que decide el
  término— y su respaldo. Un pago presentado el 29/09 e identificado el 02/10, con
  septiembre cerrado el 30/09, queda en término.
- **Nadie lo informó**: se busca la multa por expediente, resolución, acta o
  carátula y se registra el pago verificado, presentado ese día.

Identificar no crea otro movimiento bancario ni duplica nada: registra por primera
vez en el libro de Multas el crédito que ya estaba en el extracto, con su
aplicación y su recibo. Siguen el crédito entero, una sola multa, la clave contra
el doble clic y la base frente a dos operadores con el mismo crédito; si el recibo
falla, no queda nada a medias.

<a id="movimientos"></a>

## Movimientos del mes · `/multas/movimientos`

Una **consulta operativa, no la rendición oficial** (P02): lo dicen la pantalla y el
Excel. Va por secciones, cada una con la fecha que filtra y su propio total, **sin
total general** (M10):

| Sección | Filtra por |
| --- | --- |
| Cobros en caja | fecha de recepción |
| Aplicaciones a la deuda, con su término: lo que redujo la deuda | fecha de aplicación |
| Excedentes al aplicar: lo que el pago trajo de más (M05) | fecha de aplicación |
| Depósitos al banco | fecha del depósito |
| Cheques rechazados | fecha del rechazo |
| Reemplazos de cheques | fecha de recepción del reemplazo |
| Créditos del banco identificados | fecha de verificación |

Un cobro, su aplicación, su depósito y el reemplazo de su cheque son hechos
distintos del mismo dinero: sumarlos lo contaría varias veces. El depósito es un
traslado y el reemplazo no es recaudación. Un depósito **cancelado** queda en la
lista como antecedente pero no suma —el dinero no salió de la caja, y volver a
depositarlo lo contaría dos veces—; la sección dice aparte cuánto quedó fuera de su
total, y el Excel lo marca fila por fila. Uno devuelto por un rechazo sí ocurrió y
suma. La pantalla muestra las primeras cien
filas de cada sección; el Excel, que se arma por cursor, las trae todas.

---

<a id="caja-del-dia"></a>

## Caja del día, arqueo y cierre

Son **las pantallas de Caja de Haberes**, con la caja de Multas y sus permisos
propios (`multas.caja.*`): cerrar una caja no habilita a cerrar la otra, y los
botones de cada pantalla se encienden con el permiso de la caja que se mira. Se
entra desde `/multas/caja`, que enlaza al día, los arqueos, los cierres y el
calendario, y desde la barra lateral.

Lo que cambia respecto de Haberes:

- **Solo pesos**: Multas no cobra en dólares. No hay selector de moneda, el
  servidor muestra pesos aunque la dirección pida dólares, y rechaza un arqueo o
  un cierre en dólares.
- **Sin saldo del sistema anterior**: la apertura de Multas no reserva por cuota.
  Abrir los libros lleva a `/multas/caja`.
- **Cada recibo del libro abre su multa.** Los cheques en custodia muestran qué
  multa paga cada uno —el reemplazo, la del cheque original— y un cheque de la
  apertura muestra lo que declaró la apertura. Quien opera la caja sin
  `multas.ver` ve los mismos datos sin enlaces a las fichas, y encuentra la caja
  del día y los cierres en la barra lateral aunque no vea los expedientes.
- **La columna de banco** son los pagos por banco verificados, el día en que se
  verifican, sin los movimientos ficticios que usa el Excel del área. Es lo que el
  libro de Multas reconoció, **no el saldo de la cuenta compartida**: la pantalla y
  la planilla lo aclaran. Un crédito sin identificar de esa cuenta no impide cerrar.
- **La planilla** es la misma, con el título de la caja de Multas y el reverso con
  expediente, carátula y librador ([rechazo](#rechazo) para los renglones del
  cheque rechazado y su reemplazo). La copia firmada no bloquea el cierre (M10).

Con el cierre mensual, el término de un pago (M08) se juzga contra él y queda
guardado con la evidencia del cierre: reabrir el mes no reclasifica lo ya juzgado.
Inicio lista los días y los meses de Multas sin cerrar a quien puede cerrarlos.

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
  el banco y el crédito ya no queda libre para otro pago. Si el servidor rechaza
  el crédito elegido, el diálogo dice por qué y deja la lista para elegir otro.
- **Cancelar** (`multas.verificar-pagos`): un depósito que no ocurrió o se cargó
  mal, con su motivo, mientras no se acreditó. El cobro vuelve a la caja y se
  puede depositar de nuevo; el cancelado queda a la vista con su motivo.

Depositar no es cobrar otra vez: la deuda no se mueve y el recibo no cambia.

<a id="rechazo"></a>

## Cheque rechazado y reemplazo

Un cheque se puede rechazar en la caja, depositado o ya acreditado. **El pago, su
aplicación y su recibo no cambian**: la multa no vuelve a deber, y la ficha
muestra aparte lo **pendiente de regularización** —el cheque entero, excedente
incluido—. Con saldo cero lo dice: «saldo cero, con pago pendiente de
regularización» (M03).

- **Registrar el rechazo** (`multas.gestionar-rechazos`): el día, el motivo y el
  **respaldo** —el aviso del banco o la constancia— obligatorio. Lo registra
  el usuario: la ausencia de crédito en el extracto no demuestra un rechazo.
  El rechazo es del cheque vigente: el original o su último reemplazo.
- **Recibir el reemplazo** (`multas.cobrar`): solo otro cheque del **mismo
  importe**; el cambio de medio no es un reemplazo (P13). Vuelve a custodia y se
  deposita como cualquier cobro, sin recibo nuevo ni nueva recaudación. Si también
  se rechaza, se repite el circuito y la ficha conserva toda la cadena.
- **Completar** (`multas.gestionar-rechazos`): cuándo se avisó a la empresa y qué
  se hizo con el papel —anulado y devuelto, o acta—, con su constancia. Una vez
  cada uno; se puede completar con la regularización ya cerrada.

**La revisión bancaria queda pendiente para una etapa posterior** (decisión del
usuario, 22/10/2026). Registrar el rechazo no revierte el crédito original ni
imputa un débito del extracto:

| Dónde estaba el cheque | Resultado del rechazo |
| --- | --- |
| En la caja | Sale de custodia y queda pendiente el reemplazo; no hay movimiento bancario |
| Depositado, sin acreditación registrada | El depósito queda devuelto y sale de tránsito; la revisión bancaria sigue pendiente, sin afirmar «nunca acreditado» |
| Acreditado | Conserva el crédito y su vinculación; muestra «Rechazo con acreditación previa: revisión bancaria pendiente» |

No se ofrecen «Imputar crédito/débito» ni «El banco no lo acreditó». El servidor
también bloquea esas operaciones, incluido enviar un débito junto con el rechazo.
El motor bancario conserva sus controles y pruebas para el trabajo futuro; no
forma parte de la operación habilitada. El reemplazo no cierra la revisión bancaria.

Un depósito devuelto no se cancela: el cheque no vuelve a custodia, se reemplaza.
La caja lista los rechazos con reemplazo, revisión bancaria o papeles pendientes.

**En la planilla de caja** el rechazo y el reemplazo van sin recibo y con el
importe de lo que de verdad movieron:

- rechazado en la caja, la columna de cheques baja con un renglón de egreso
  «Cheque n.º X rechazado»;
- rechazado depositado o acreditado, no hay renglón: el cheque ya había salido de
  la caja con el depósito, y el banco conserva el crédito hasta la revisión
  bancaria;
- el reemplazo entra a custodia con un renglón de ingreso «Reemplazo del cheque
  n.º X — no es recaudación». Va en ingresos porque la planilla no tiene otro
  renglón donde crezca la custodia; **no está relevado** y queda para confirmar
  con el área.

El inventario del reverso es el de la fecha del cierre: cerrar tarde un día en
que el cheque estaba en la caja lo lista aunque después se haya rechazado. El
reemplazo se identifica con la multa del cheque original, y un cheque de la
apertura figura aunque todavía no pague ninguna multa.

**Pendientes:** asociar un cheque de apertura al pago anterior de una multa sin
volver a ingresar dinero; P13, regularización con otro medio; conciliación del
crédito/débito y rectificación auditada de una confirmación equivocada de «nunca
acreditado». Cargar la multa y un pago anterior no vincula automáticamente un
cheque de apertura. No registrar ese cheque como un cobro nuevo: duplicaría fondos.

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
  que ser de la cuenta de Multas, desde la apertura de la caja, libre y **por
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
| `multas.ver` | Consultar los expedientes y las multas | administrador, contador, administrativo, consulta |
| `multas.registrar` | Cargar expedientes, documentos y multas; completar datos; registrar pagos anteriores; adjuntar archivos | administrador, administrativo |
| `multas.ajustar` | Ajustar y revertir; corregir datos ya cargados; validar o descartar pagos anteriores | administrador, contador |
| `multas.cobrar` | Cobrar en la caja en efectivo o con cheque y emitir el recibo; informar un pago por banco; registrar un recurso | administrador, administrativo |
| `multas.depositar` | Depositar en el banco un cobro de la caja, con el ticket del cajero | administrador, administrativo |
| `multas.gestionar-rechazos` | Registrar el rechazo de un cheque y completar el aviso y la disposición | administrador, contador |
| `multas.verificar-pagos` | Informar, verificar contra el extracto y descartar pagos por banco; ver la cola de pagos por verificar; acreditar o cancelar un depósito de la caja | administrador, contador |
| `multas.caja.abrir` | Abrir la caja de Multas, una sola vez | administrador |
| `multas.caja.ver` | Ver la caja del día, los arqueos, los cierres y el calendario de Multas | administrador, contador, administrativo, consulta |
| `multas.caja.arquear` | Registrar arqueos de Multas | administrador, contador, administrativo |
| `multas.caja.revisar-arqueo` | Revisar arqueos de Multas | administrador, contador |
| `multas.caja.ajustar-diferencia` | Imputar la diferencia de un arqueo de Multas | administrador, contador |
| `multas.caja.cerrar` | Cerrar un día o un mes de Multas | administrador, contador |
| `multas.caja.reabrir` | Reabrir un período de Multas, con motivo | administrador, contador |
| `multas.caja.exportar` | Descargar la planilla de un cierre de Multas | administrador, contador, administrativo, consulta |
| `multas.caja.regenerar-planilla` | Rehacer la planilla de un cierre de Multas | administrador |

Un permiso que se crea por primera vez llega con su asignación de fábrica también a
los roles ya configurados (`RolesAndPermissionsSeeder`); uno que ya existía no se
toca.
