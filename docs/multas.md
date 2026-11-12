# Multas

Qué hace hoy la Caja de Multas. Describe lo implementado; las reglas con su
garantía en la base están en [reglas de Multas](multas-reglas.md).

**Estado: en construcción.** Están los expedientes y sus multas —documentos,
obligación y pagos anteriores al sistema—, la apertura de la caja
con su cuenta bancaria compartida, el cobro en caja con su recibo, los pagos por
banco, el recurso del 20 %, el depósito al banco de lo cobrado y el cheque
rechazado con su reemplazo, la caja del día con su arqueo, su cierre y su
planilla, la identificación de los créditos de la cuenta compartida desde Banco ›
Movimientos, los pendientes, la consulta operativa del mes y la rendición mensual a
la SAF, y la importación de la planilla histórica.

**La barra lateral** tiene cuatro entradas: Expedientes, Caja del día, Pendientes y
Calendario. La apertura se abre desde el día en que se hizo; los cierres, la
rendición y los movimientos del mes, desde el calendario; los créditos sin
identificar, desde Banco › Movimientos (decisión del usuario, 29/10/2026).

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

La ficha del expediente muestra hojas por resolución de origen. Dentro se
agrupan las actas, con los archivos de cada documento en su cabecera, y debajo
las multas con su número, carátula, estado, obligación y saldo. Si una multa
tiene varias actas o resoluciones de origen, se presentan juntas para mostrar
esa multa una sola vez. Los vínculos de modificación, ratificación y respaldo
se muestran en la multa correspondiente; los documentos sin multas vinculadas
y los archivos generales del expediente permanecen accesibles por separado.

Quien carga multas tiene un **«+»** al final de Adjuntar y Corregir, que abre
«Agregar multa» con documentos **fijos**: en la cabecera de la resolución, esa
resolución sin actas; en la de las actas de un grupo, la resolución y esas actas; en
un documento sin multa, ese documento. Es el caso de dos sancionados por la misma
inspección, o de un papel que llegó antes que la multa. Un tooltip dice qué va a
quedar fijo.

Lo fijo se muestra como texto, sin selects, casillas, «Agregar un acta» ni archivos:
solo se carga la multa. Con un acta sola, la resolución se elige o se escribe igual.
Viaja en la dirección (`?resolucion=…&actas[]=…`), el servidor solo fija documentos de
ese expediente y del tipo que corresponde, y el alta rechaza la que no los respete.
Para otra combinación está el «Agregar multa» de la cabecera, que es libre.

| Pantalla | Ruta | Permiso |
| --- | --- | --- |
| Expedientes y multas | `/multas/expedientes` | `multas.ver` |
| Nuevo expediente | `/multas/nueva` | `multas.registrar` |
| Ficha de la multa | `/multas/expedientes/{id}/{número}/multa/{n}` | `multas.ver` |
| Expediente | `/multas/expedientes/{id}/{número}` | `multas.ver` |
| Agregar multa al expediente | `/multas/expedientes/{id}/{número}/agregar-multa` | `multas.registrar` |
| Exportar las multas | `/multas/exportar` | `multas.ver` |
| Apertura de la caja | `/multas/caja/apertura` | `multas.ver`; abrirla, `multas.caja.abrir` |
| Pendientes | `/multas/pendientes` | `multas.ver`; pagos informados, `multas.verificar-pagos` |
| Identificar un crédito | `/multas/creditos/{movimiento}/identificar` | `multas.verificar-pagos` |
| Caja del día | `/multas/caja/dia` | `multas.caja.ver` |
| Arqueos | `/multas/caja/arqueos` | `multas.caja.ver`; contar, `multas.caja.arquear` |
| Cierres | `/multas/caja/cierres` | `multas.caja.ver`; cerrar, `multas.caja.cerrar` |
| Calendario | `/multas/caja/calendario` | `multas.caja.ver` |
| Movimientos del mes | `/multas/movimientos` | `multas.ver` |
| Rendiciones | `/multas/rendiciones` | `multas.ver`; emitir, `multas.rendicion.emitir` |
| Importar la planilla histórica | `/multas/importaciones` | `multas.importar` |

<a id="listado"></a>

### Expedientes y multas · `/multas/expedientes`

Dos lecturas del mismo material, como el listado de Haberes: la pestaña
**Multas**, que es la que abre, y la pestaña **Expedientes**
(`?vista=expedientes`). Comparten el buscador, los filtros y la paginación.

Busca por lo que trae quien viene a pagar: número de expediente («169607/24»
encuentra «169607/2024-0»), de resolución, de acta, o la carátula. Filtra por
deuda (con, sin o en recurso) y por año de registro.

- **Multas**: una fila por multa, con el importe vigente, el saldo y la situación:
  pendiente, con pagos parciales, cancelada, sin efecto o en recurso, y si tiene
  excedente.
- **Expedientes**: una fila por expediente, con las carátulas de sus multas,
  cuántas tiene y cuántas deben, el importe vigente y el saldo sumados —uno por
  moneda: pesos y dólares no se suman en una cifra— y si debe o no. Un expediente
  aparece si **alguna** de sus multas coincide con la búsqueda o se registró en el
  año pedido; en cambio, «sin deuda» quiere decir que **ninguna** de sus multas
  debe, y «en recurso», que alguna tiene su recurso en trámite.

**Exportar a Excel**, solo en la pestaña Multas, baja lo que está filtrado,
entero y sin paginar: una fila por multa con expediente, carátula, persona, documentos de origen, fecha de registro,
importe vigente, pagado en el sistema, pagado antes del sistema, saldo, excedente,
estado y procedencia. Los importes van escritos («1.400.000,00»), como en pantalla,
no como número de Excel. Todo el archivo sale de una misma foto de los datos: lo
que otro carga o corrige mientras se baja —una fecha, una carátula, un ajuste— no
repite ni saltea filas ni mezcla momentos, y quien corrige no espera.

### Nuevo expediente · `/multas/nueva`

El expediente se carga con su primera multa, con todo lo que trae el papel. Si
el número escrito ya está cargado, el título pasa a «Agregar multa» y la multa
se le agrega:

- **Expediente.** Al escribir el número se pregunta si ya está cargado. Si está,
  un aviso al lado del campo lo dice y lleva a su ficha; la multa se le agrega y
  se ofrecen sus documentos —un acta compartida por dos multas se carga una vez—. Si hay otro con el mismo número y año y distinto
  sufijo, se avisa: no se fusionan solos.
- **Resolución.** Si el expediente ya tiene resoluciones, primero se elige: el
  select no trae ninguna elegida de antemano, para que nadie atribuya la multa a
  una resolución por descuido, y al final ofrece «Otra resolución, que no está
  cargada», que abre los campos para escribirla con su fecha —es la fecha del
  importe original—. Sin resoluciones cargadas se escribe directo. Sin elegir ni
  escribir, el alta pide elegirla.
- **Actas.** Las del expediente, como casillas sin marcar, y «Agregar un acta que
  no está cargada» para las nuevas, con su tipo (laboral o higiene y seguridad).
  Pueden no tener año.
- **Cómo se lee.** Debajo del expediente, la resolución y cada acta nueva, una
  línea dice cómo los va a leer el sistema, con el año completo: «Se lee como
  169607/2024-0», «Se lee como Resolución 649/2026». El campo guarda el texto
  como figura en el papel; si el siglo no es el correcto, se escribe el año con
  cuatro cifras y la línea cambia. Vale también en vincular, agregar un
  documento y reemplazar el origen. Es solo presentación: la lectura que vale es
  la del servidor.
- **Documento ya cargado.** Al escribir una resolución o un acta nueva, si el
  expediente ya tiene una del mismo tipo con el mismo número y año, se avisa y se
  ofrece «Usar la cargada». Se compara el número leído, no el texto: «LAB. Nº
  05196/11» y «LAB Nº 5196/11» son la misma acta. Es solo un aviso —no bloquea ni
  lo impone la base— y vale también al agregar un documento al expediente, al
  vincular uno a la multa y al reemplazar la resolución de origen.
- **Carátula, persona y fecha de registro.** La persona es opcional: la carátula
  alcanza para identificar al sancionado y es la que va a llevar el recibo. El mes
  de la fecha de registro es el período de origen de la multa.

### Ficha · `/multas/expedientes/{id}/{número}/multa/{n}`

La multa cuelga de su expediente y va por su número dentro de él:
`/multas/expedientes/12/169607-2024-0/multa/2` es «la multa 2 del 169607». No va
el id global, que no le dice nada a nadie, ni la carátula, que puede ser el nombre
de una persona: igual que el haber en Haberes. El número lo pone la base al
insertar —también en la importación del Excel— y no cambia. Las acciones sobre la
multa (cobrar, adjuntar, ajustar) siguen por su id: no se leen ni se comparten. Los
enlaces de las pantallas reciben la dirección armada por el servidor.

Arriba, el saldo: importe vigente, pagado (separando lo anterior al sistema),
saldo y estado. Abajo:

- **Datos**, con la carátula y el expediente tal como vinieron.
- **Documentos**, con el papel que cumple cada uno: origen, modificación,
  ratificación o respaldo, y debajo de cada uno su archivo. Vincular una
  ratificación no mueve la obligación. Un documento nuevo se vincula con su
  archivo, que queda en el documento. Cada documento se adjunta y se corrige
  desde acá igual que en el expediente; como es del expediente, el cambio lo ve
  toda multa que lo cita. **Desvincular** anula el vínculo con un motivo: el
  documento pasa a «Desvinculados», con quién y por qué, y sigue en el
  expediente. El papel que exige un movimiento vigente —el origen de la
  resolución del importe original, la modificación de un ajuste— no se
  desvincula, y la ficha no lo ofrece; los otros papeles del mismo documento,
  sí. Si la resolución de origen era otro papel —no un número mal leído, que
  se corrige—, quien tenga el permiso la **reemplaza** desde ese documento: elige
  otra resolución del expediente o carga la que falta, con su fecha y su
  archivo, y un motivo. El importe no cambia; la vieja queda desvinculada como
  origen.
- **Obligación**: el importe original y cada ajuste, con su resolución, su fecha,
  su motivo y quién lo cargó. «Importe» es lo que cada fila sumó o restó, con su
  signo; «Vigente», cómo quedó la multa después de esa fila, en el orden de carga.
  El último vigente es el importe vigente de arriba. Un ajuste revertido queda
  tachado.
- **Archivos de la multa**: los de la multa y, debajo, los del expediente.
- **Pagos**: los del sistema, con su medio, su estado, su recibo, si fue el
  cancelatorio, si se presentó en término, su comprobante y, si sobró, cuánto
  quedó de excedente. Desde acá se cobra en caja, se informa, corrige o verifica
  un pago por banco y se registra el recurso.
- **Pagos anteriores al sistema**, con su respaldo, su revisión, su comprobante y,
  si vino del banco, el crédito que lo respalda.

<a id="pendientes"></a>

### Pendientes · `/multas/pendientes`

Lo que espera a alguien, por pestañas, del más viejo al más nuevo. Inicio cuenta
cada una y lleva directo a ella:

- **Pagos informados** (`multas.verificar-pagos`): los informados por el deudor que
  esperan su crédito. Cada fila lleva a la ficha, donde se verifica, se corrige o se
  descarta; el crédito también se identifica desde Banco › Movimientos.
- **Por depositar**: el efectivo y los cheques que siguen en la caja, con
  «Depositar» (`multas.depositar`). El depósito vuelve acá, a la pestaña siguiente.
- **Sin acreditar**: lo depositado que espera al extracto, con la confirmación de la
  acreditación (`multas.verificar-pagos`).
- **Rechazos**: los cheques rechazados con el reemplazo, un hecho del banco o los
  papeles todavía pendientes.

### Expediente · `/multas/expedientes/{id}/{número}`

La dirección lleva el id y, en otro tramo, el número del papel con guiones:
`/multas/expedientes/75/169607-2024-0`. **El id identifica; el número solo
describe.** Si falta, o quedó viejo porque el número se corrigió, la pantalla
redirige a la dirección actual: una dirección guardada sigue andando. Un expediente
importado sin número normalizado va solo con su id. Un número suelto en la barra
no es una dirección: para buscar por número está el buscador.

Es el mismo patrón que Haberes (`app/Support/Routing`). El id va en su propio
tramo porque el sufijo también lleva guion: pegado al número, `169607-2024-75` no
decía si 75 era el sufijo o el id. Los enlaces de las pantallas reciben la clave
armada por el servidor (`routeKey`) y llegan directo a la dirección actual.

**Agregar multa** abre el mismo formulario de Nuevo expediente con el expediente fijo:
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

**Nuevo expediente** ofrece un archivo opcional en cada bloque: el del expediente, el
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

«Ajustar» lleva la obligación a otro importe. Se escribe el **importe nuevo**, no
la diferencia: el sistema muestra si es un aumento o una reducción y por cuánto, y
lo registra así. Si mientras tanto otro cambió el importe, se rechaza en vez de
aplicar una diferencia pensada sobre otro número. El importe original no se toca:
cada ajuste es otra fila. Hay dos vías:

- **Por resolución** (`multas.ajustar`): la normal. Con la resolución que lo
  dispone (del expediente o nueva), su fecha y el motivo.
- **Corrección de importe** (`multas.corregir-importe`, solo administrador): para
  un importe mal cargado. Sin resolución —el área confirmó el 28/10/2026 que estos
  errores no se corrigen con un papel—, con motivo («Error de carga», «Se leyó mal
  el papel», «Otro») y **detalle obligatorio**. Rige desde el día en que se carga.
  En la ficha se lee «Corrección de importe (reducción)», y revertirla pide el
  mismo permiso.

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

Los pagos que trae la [planilla histórica](#importacion) entran distinto:
**provisionales**, cuentan en el saldo desde que se consolidan y la ficha los muestra
como «Importado, sin revisar», con su hoja, fila y mes. Validarlos no cambia el
saldo, porque ya contaban; rechazarlos baja lo pagado y reconoce el excedente que
deje de haber. Un provisional que cubre una devolución ya contada no se rechaza.

---

<a id="importacion"></a>

## Importar la planilla histórica · `/multas/importaciones`

> **En pausa** (`multas.historical_import_enabled = false`, decisión del usuario del
> 11/11/2026): construida y probada, pero sus rutas responden 404, el botón no aparece
> y `StartFineImport` y `StartFineImportConsolidation` la rechazan. Para retomarla:
> verificarla en el navegador y prender el interruptor en `config/multas.php`.

La cuenta corriente que el área llevaba en Excel entra al sistema por lotes. Solo el
administrador importa (`multas.importar`), desde «Importar planilla» en Expedientes.
Decisiones del usuario del 11/11/2026.

**Qué se lee.** Solo las hojas de año, de 2007 a 2026. Los planes de pago, `OTROS`
y `RESOL. MINISTERIAL` quedan para más adelante. Las columnas se reconocen por su
encabezado en cada hoja —el acta sin título, el total como «TOTAL PAGADO», «Total
Pago» o «PAGOS», los meses como fecha o como texto («SET-08», «En-15», «AGO.18»)—.
De cada celda se guarda la coordenada, el valor, la fórmula y el resultado que el
Excel dejó guardado; una fórmula sin resultado no se toma como cero. Las filas de
totales, con rótulo o sin él, se guardan como **control** y nunca son multas.

**Antes, la apertura.** Importar pide la caja de Multas abierta: el mes de la
apertura es el **corte**. Los pagos de meses anteriores entran; una celda de pago
desde el mes de la apertura no entra y queda **pendiente de identificar**, porque ese
cobro tendría que estar en el sistema. La multa se importa igual, mostrando más deuda
que el Excel por ese importe, hasta que alguien marque la celda como identificada
o descartada.

**El lote.** El archivo se guarda como adjunto del lote y se lee en la cola, una hoja
por vez. La pantalla del lote se actualiza sola mientras lee o consolida, y tiene
seis pestañas:

- **Conciliación**: por hoja, las filas de detalle, las que quedan para revisar, el
  importe y lo pagado según el detalle, al lado de los pies de control. Arriba, el
  lote entero: listas, consolidadas, excluidas, sin cambios, con error y celdas
  pendientes.
- **Revisión**: cada fila con un problema que bloquea, con hoja, fila, columna y
  valor, y las decisiones que admite. Se filtra por hoja y por problema y se exporta
  a Excel (`/multas/importaciones/{lote}/revision.xlsx`).
- **Pendientes de identificar**: las celdas desde el corte.
- **Para completar**: avisos que no frenan la fila (expediente, resolución o acta
  incompletos, sin fecha, pagado de más).
- **Importadas** y **Excluidas**.

**Qué se decide en Revisión.** Una fila entra solo con todos sus bloqueos resueltos.
Cada decisión se toma una vez, con quién y cuándo; excluir pide el motivo y siempre
está disponible.

| Problema | Qué se puede hacer |
| --- | --- |
| Sin importe, carátula o expediente | Excluir: se carga a mano |
| Ya cargada a mano | Vincular la fila a esa multa sin tocarla, o excluir. Nunca se crea otra |
| Cambió desde una importación anterior | Agregar solo los meses nuevos anteriores al corte, o excluir. Un cambio de importe o de identidad se hace en la multa |
| Posible coincidencia (identidad incompleta o el mismo texto en otro lote) | Es el mismo expediente (se elige), es otro, o excluir |
| Repetida en la planilla | Quedarse con una: las otras se excluyen |
| Total o saldo que no coincide o falta, fórmula sin resultado | Aceptar los meses como están, importar sin pagos, o excluir. **En bloque**, por problema |
| Pago mayor que la multa | Aceptarlo, importar sin pagos, o excluir, fila por fila: puede ser con intereses o un tipeo |
| Pago negativo o nota en la celda | Importar sin pagos, o excluir |
| Importe en plan de pagos, restitución o una columna sin identificar | Importar sin esas columnas, o excluir |

**Consolidar** es un paso explícito y se repite cuando hay más filas listas. Cada
fila, en su transacción, crea o reutiliza el expediente por su identidad completa,
la resolución y el acta con su texto original, la multa con procedencia
«importada» y sin persona, su constitución por el importe y un pago anterior
**provisional** por mes con pago, con fecha el primero del mes y la evidencia
«Planilla 2018, fila 123, columna H (01/2019)». **No hay ingresos, asientos ni
recibos.** Una fila que falla queda con su error y no frena las demás.

**Otro lote después.** Una fila que ya se importó y no cambió se saltea. Si cambió,
va a Revisión y no se aplica sola. Una multa ya consolidada no se consolida dos
veces, ni desde dos lotes a la vez.

**No relevado con el área**, para confirmar: qué son las actas «RUBRICA» y «OBST»;
el importe que incluye intereses (2023, fila 550) y los pagos mayores que la multa;
los sobrepagos; si el corte vale también para los pagos anteriores cargados a mano.

---

<a id="caja"></a>

## Caja y cuenta bancaria

La cuenta bancaria de Multas es **compartida con Aranceles**, y su saldo no se
puede repartir entre las dos (M08, M12): cada caja reconoce solo lo que
identifica. Por eso la caja no declara un saldo bancario:

- **La apertura de la caja** (`/multas/caja/apertura`, permiso `multas.caja.abrir`): como la
  de Haberes —fecha, efectivo contado por billete, cheques detallados, total
  calculado—, pero **sin saldo bancario** y diciendo con qué cuenta opera. Su
  fecha es la frontera: lo que el extracto trae antes ya estaba en el saldo que el
  área traía y **no entra al libro como cobro**; solo puede respaldar un pago
  anterior de una multa. La cuenta de la apertura es la primera
  [cuenta de la caja](#cuentas-de-la-caja).

No hay un corte declarado para la cuenta: el saldo del banco lo trae el extracto
(ver [corte de la cuenta compartida](#sin-corte-de-cuenta)).

<a id="cuentas-de-la-caja"></a>

### Cuentas de la caja · `/multas/caja/cuentas`

La caja puede operar con **más de una cuenta**: sumar una segunda, compartir una con
otra caja o reemplazar una por otra (decisión del usuario, 29/10/2026). La cuenta de
la apertura es la primera, predeterminada y **sin fecha de inicio** —«inicio
histórico desconocido»—; `cash_book_openings.bank_account_id` queda como dato de la
apertura, y el vínculo lo crea Banking al escuchar `CashBookOpened`, igual que en
Haberes. Se administran en una pantalla propia, enlazada desde la apertura
(`multas.caja.cuentas`, administrador); mirarlas es de quien ve la caja.

- **Vigencia efectiva**: desde (vacío = inicio histórico desconocido) hasta la baja,
  **que incluye ese día**. Separada de quién la cargó y cuándo, que queda en el
  vínculo y en la auditoría. **Sin fechas futuras**: un cambio de cuenta se carga el
  día en que empieza.
- **Una predeterminada por moneda** mientras haya alguna vigente: la que se propone
  al depositar. Dar de baja la predeterminada pide elegir la nueva, salvo que sea la
  última: entonces la caja queda sin cuenta para operaciones nuevas.
- **Depositar** va a una cuenta vigente el día del depósito: la elegida o la
  predeterminada. El formulario ofrece las vigentes **en la fecha que se carga**, no
  las de hoy: un depósito hecho antes de una baja se registra después contra la
  cuenta dada de baja. Con una sola, no se elige.
- **Identificar**: se ofrecen los créditos de cada cuenta desde la más tardía entre la
  apertura y su inicio, hasta su baja. **Que se ofrezcan no los hace de Multas**: lo
  decide quien identifica.
- **Pago anterior**: lo respalda un crédito anterior a la apertura dentro de la
  vigencia de una cuenta. Una cuenta que empieza después de la apertura no habilita
  nada viejo; la de la apertura, sin inicio, sí.
- **Dar de baja** no borra nada: un depósito en tránsito se acredita, se revierte o se
  rechaza contra su cuenta, y un crédito llegado mientras valía se identifica después.
- **Volver a habilitar** una cuenta dada de baja abre **un período nuevo**, que empieza
  después de la baja (decisión del usuario, 30/10/2026): el tiempo en que no
  correspondía queda afuera. Los períodos de una cuenta en la caja no se superponen.
- **Cambiar el inicio** pide motivo y queda auditado: adelantarlo ofrece todos los
  créditos del período que se suma. Ni el inicio ni la baja pueden dejar afuera lo ya
  registrado.

La base impone cada regla (`cash_box_bank_accounts` y sus guardas,
`cash_transfer_follows_box_accounts`, `bank_allocation_follows_box_accounts`,
`fine_historical_bank_credit`); ver [reglas](multas-reglas.md#cuentas-de-la-caja).

La cuenta se mira en **dos números que no se suman nunca**, cada uno donde se usa:

- **El saldo según el banco**, de la cuenta entera y compartida: el último que
  informó un extracto, arriba de **Banco › Movimientos**, por cuenta.
- **Lo reconocido en el libro de Multas**, en el **calendario** de la caja: por mes y
  en el acumulado del año hasta hoy. Es lo que el libro tiene asentado en la cuenta
  —depósitos de la caja acreditados y pagos por banco verificados, menos los
  débitos de cheques rechazados—, no la parte de Multas en el saldo del banco: de
  las salidas de la cuenta solo se conocen las que el libro registró.

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

Están en **Banco › Movimientos**, con el filtro de la cuenta de Multas y «Sin
identificar» —Inicio los cuenta y lleva ahí—. Son **de la cuenta compartida, no una
deuda de Multas** (M08): lo que no sea de Multas queda pendiente, sin asignarle una
caja por descarte. Banking no sabe qué es una multa: cada crédito pendiente le
pregunta al módulo de la caja de su cuenta qué ofrecer (`BankCreditHandler`).

- **Desde la apertura de la caja, libre** y con `multas.verificar-pagos`:
  «Identificar como multa». Si la cuenta también es de Aranceles, la fila ofrece además
  «Identificar como arancel», y quien identifica decide. Un crédito que Aranceles ya usó
  en parte no se ofrece acá: un pago de multa toma el crédito entero.
- **Anterior a la apertura**: la fila lo dice y no ofrece identificar. Ya estaba en el
  saldo que el área traía: se usa como pago anterior desde la ficha de la multa.

**Identificar** abre `/multas/creditos/{movimiento}/identificar`, con dos pestañas:

- **Pago de una multa**, por uno de dos caminos:
  - **Alguien lo informó**: se ofrecen primero los pagos informados del mismo
    importe. Se verifica ese pago, que conserva su presentación —la que decide el
    término— y su respaldo. Un pago de una multa de septiembre presentado el 29/09 e
    identificado el 02/10 queda en término: cuenta la presentación, no la carga.
  - **Nadie lo informó**: se busca la multa por expediente, resolución, acta o
    carátula y se registra el pago verificado, presentado ese día.
- **Depósito de la caja**: los depósitos del mismo importe que esperan al extracto.
  Confirmarlo acredita ese depósito, como desde los pendientes.

Ya identificado, la pantalla vuelve sola a la lista del banco.

Identificar no crea otro movimiento bancario ni duplica nada: registra por primera
vez en el libro de Multas el crédito que ya estaba en el extracto, con su
aplicación y su recibo. Siguen el crédito entero, una sola multa, la clave contra
el doble clic y la base frente a dos operadores con el mismo crédito; si el recibo
falla, no queda nada a medias.

<a id="rendicion"></a>

## Rendición mensual · `/multas/rendiciones`

La rendición oficial a la SAF (P02), con el modelo del área. **Vive en el mes del
calendario de la caja**, al lado del cierre: ahí se genera, se ven sus dos notas y
sus versiones anteriores. La lista de todos los meses sigue en esta dirección. Por
cada mes cerrado,
**dos notas en PDF** —«ingresos de Multas correspondientes al MES DE …» y «depósitos
FUERA DE TÉRMINO MES DE …»—, con fecha, caja (CC, Trans., Cheque), comprobante,
carátula, expediente, importe y recibo, el total y el importe en letras. Si la
tabla no entra en una hoja, sigue en las siguientes con el encabezado repetido;
el total es la última fila y nunca queda solo en una hoja, y cada hoja lleva
«Hoja N de M» al pie.

- **Qué entra:** lo **acreditado en la cuenta**. Un pago por banco, por la fecha de su
  movimiento; un cobro de la caja, por la acreditación de su depósito (cobrado el
  04/09 y acreditado el 11/09 figura el 11/09). Lo que no se acreditó todavía no
  entra en ninguna.
- **Reconocido** es el día en que el sistema da por cobrado el pago contra la
  cuenta: la verificación de un pago por banco, o el más tardío entre el cobro en
  caja y la confirmación de la acreditación de su depósito. Es la fecha del asiento,
  que siempre es la del día en que se carga.
- **En término (mes M):** acreditado y reconocido en M.
- **Fuera de término (mes M):** reconocido en M, con una acreditación de un mes
  anterior.
- **El corte es el fin del mes calendario, no su cierre manual** (decisión del
  usuario, 29/10/2026). Un crédito del 29/10 reconocido el 29/10 va en término en
  octubre; reconocido el 02/11, fuera de término en noviembre, aunque octubre
  siguiera sin cerrar. **Cada pago va a un solo informe**, y como un mes cerrado no
  admite asientos con su fecha, lo emitido no cambia.
- **Un pago por el banco corregido** (regla provisoria, no relevada, hasta definir
  cómo se informa a la SAF): lo emitido no cambia. Anulado dentro del mes en que se
  reconoció, no figura. Anulado después, sigue en su mes, también si ese mes se
  reabre y se vuelve a emitir, y en el mes de la anulación va una fila en negativo,
  fuera de término. El pago nuevo va en el mes de su reconocimiento, el día de la
  corrección, con la fecha de su crédito: en término solo si el crédito es de ese
  mes. Las filas concilian las correcciones con lo reconocido en el libro de Multas;
  no se comparan con el saldo de la cuenta, que tiene otros movimientos.
- Se emite con el mes cerrado (`multas.rendicion.emitir`) y queda **congelado**:
  pedirla de nuevo devuelve la misma. Reabrir y volver a cerrar el mes produce otra
  versión; la anterior se conserva. La base exige el cierre mensual vigente y
  cerrado, y no deja cambiar ni borrar una rendición.
- **La cuenta** de cada fila es la del crédito, citada como dice su cuenta bancaria
  («Cómo se cita en los documentos», en Banco › Cuentas). Al emitir se guardan las
  cuentas del informe: con una, la nota la nombra en el texto; con varias, dice «las
  cuentas de esta Secretaría» y agrega la columna «Cuenta». Reimprimir no consulta
  la configuración ni la predeterminada de hoy. Las emitidas antes guardan la
  citación que se usaba («Nº 452680-1 del Bco. Macro»).
- **Sin relevar:** el formato con varias cuentas; la citación de la cuenta, distinta
  del número del recibo; el destinatario y el lugar están en `config/multas.php`; la caja
  de un cheque acreditado por remesa (P15); un cheque acreditado y después rechazado
  se lista con la marca «cheque rechazado», sin descontarlo. El membrete queda
  reservado hasta que el área entregue el logotipo.

<a id="movimientos"></a>

## Movimientos del mes · `/multas/movimientos`

Una **consulta operativa, no la rendición oficial** (P02): lo dicen la pantalla y el
Excel. Se llega desde el mes del calendario de la caja. Va por secciones, cada una con la fecha que filtra y su propio total, **sin
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
traslado y el reemplazo no es recaudación. Un depósito **cancelado** sigue en su mes,
marcado, y la cancelación es una fila negativa en la fecha de su asiento inverso: un
mes pasado no cambia, cancelado en el mismo mes da cero, y volver a depositarlo no lo
cuenta dos veces. Uno devuelto por un rechazo sí ocurrió y suma. La pantalla muestra
las primeras cien filas de cada sección; el Excel, que se arma por cursor, las trae
todas. Las dos leen todas las secciones de una misma foto de la base: si alguien cobra
mientras tanto, ese cobro no aparece en unas secciones y en otras no.

---

<a id="caja-del-dia"></a>

## Caja del día, arqueo y cierre

Son **las pantallas de Caja de Haberes**, con la caja de Multas y sus permisos
propios (`multas.caja.*`): cerrar una caja no habilita a cerrar la otra, y los
botones de cada pantalla se encienden con el permiso de la caja que se mira. Se
entra desde la barra lateral: la Caja del día y el Calendario, que lleva a los
cierres, a la rendición y a los movimientos del mes.

Lo que cambia respecto de Haberes:

- **Solo pesos**: Multas no cobra en dólares. No hay selector de moneda, el
  servidor muestra pesos aunque la dirección pida dólares, y rechaza un arqueo o
  un cierre en dólares.
- **Sin saldo del sistema anterior**: la apertura de Multas no reserva por cuota.
  Abrir los libros lleva a `/multas/caja/apertura`.
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

El cierre mensual es de la caja —arqueos, días cerrados, totales congelados— y no
decide el término de un pago (M08) ni la rendición: los dos se cortan en el último día
del mes.
Inicio lista los días y los meses de Multas sin cerrar a quien puede cerrarlos.

---

<a id="cobro"></a>

## Cobro en caja

«Cobrar en caja», en la ficha (permiso `multas.cobrar`, con la caja abierta en la
moneda de la multa y saldo pendiente), registra un pago en **efectivo o con
cheque** y en el mismo acto lo aplica a la multa y emite el recibo (M02).
Sin la apertura de la caja el botón no aparece; en su lugar, la sección Pagos
avisa que la caja todavía no se abrió. A quien puede abrirla (`multas.caja.abrir`)
lo lleva a la apertura, y a los demás les dice que se lo pidan a un
administrador. «Depositar», en Pendientes, avisa lo mismo.

El cobro:

- **Importe**: arranca vacío, para que se cargue lo que se recibe; el saldo se
  muestra debajo del campo y un clic lo copia. Un pago
  parcial deja saldo; uno que lo supera cancela la multa y lo que sobra queda como
  **excedente pendiente**, que no se devuelve ni se acredita solo (M05). El cheque
  se recibe por su importe entero y queda en custodia (M06); si es una **orden de
  pago judicial**, se marca y se anota el expediente judicial.
- **Fecha**: hoy. Un cobro de un día anterior se carga con el número del **recibo
  de talonario** que se entregó ese día: ese papel respalda la fecha de
  presentación. Con cualquier número de talonario se puede adjuntar, de manera
  opcional, una foto o PDF de la hoja; queda archivado en privado con el recibo.
- **Recibo**: de la serie de Multas (`0210/…`, configurable en Numeración),
  «recibí de» la carátula de la multa, con su concepto —pago parcial o
  cancelatorio de la multa por su resolución—, el expediente administrativo y el
  total de la multa. Las letras y el recuadro dicen siempre lo recibido. Con
  excedente, en papel en blanco, el «SON:» pasa a **Importe a abonar** —el saldo
  antes de este pago, sin recuadro— y debajo va **Importe recibido** con su
  recuadro. Sobre la hoja del talonario, que trae el «SON:» impreso, sigue la
  disposición de siempre (decisión del usuario, **sin relevar con el área**). Se ve e imprime desde la fila del pago, sobre papel en blanco
  o sobre la hoja del talonario. El del pago que deja la deuda en cero es el
  **cancelatorio**: lista los pagos anteriores, con la fecha que se conoce de cada
  uno, sin repetir el saldo en cero. El excedente se nombra aparte —«Excedente
  pendiente»— solo sobre el talonario: en papel en blanco ya lo muestran los dos
  importes. La casilla «Depósito Cta.» lleva el número de la
  cuenta del crédito en un pago por banco y, en sus tres casillas, el día de ese
  crédito en el extracto —la fecha del movimiento, como en el papel—. En una
  transferencia el rótulo dice «Transf. Cta.» en papel en blanco; la hoja del
  talonario lo trae impreso como «Depósito Cta.», y ahí se anota «Transf.» encima,
  como lo escribe el área a mano (decisión del usuario, **sin relevar**); en un cobro en caja, la cuenta solo si la
  caja tiene una única vigente, y vacía si tiene varias —el depósito guarda la
  suya—. Lo que dice queda congelado: un ajuste posterior de la multa, un cambio en
  la cuenta o en la predeterminada no lo cambian (M09). Una hoja del talonario ya
  usada se rechaza en el campo.
- **Revisar el recibo**: el cobro va en dos pasos. «Revisar recibo» comprueba los
  datos con las mismas reglas que el cobro —un error se marca en su campo— y
  muestra el recibo tal como va a salir, sobre papel en blanco o sobre la hoja del
  talonario, sin escribir nada. Recién «Cobrar y emitir recibo» cobra; «Volver»
  conserva lo cargado. La vista previa y la emisión arman el papel con el mismo
  código, así que lo que se mira es lo que se imprime. El número que muestra es un
  anticipo: el definitivo se toma al emitir, y si entretanto se emite otro recibo
  de Multas, le toca el siguiente.
- **Término** (M08): en término si el comprobante se **presentó** hasta el
  último día del mes en que se registró la multa; después, fuera de término, se
  haya cerrado la caja o no (decisión del usuario, 29/10/2026). Sin fecha de
  registro o sin fecha de presentación queda «a revisar». Se calcula una vez, al
  aplicar el pago, y se guarda con el día contra el que se juzgó.

Un ajuste de la obligación, su reversión o validar un pago anterior pueden
cambiar cuánto de lo ya cobrado es excedente: el sistema lo reclasifica en el mismo
acto, sin mover dinero.

<a id="anular-cobro"></a>

**Anular un cobro** (`multas.anular-cobros`): para un cobro de la caja cuyo dinero
nunca entró, por ejemplo un importe mal tipeado. Solo mientras siga en la caja: sin
depósito vigente. Un cheque rechazado se anula de otra forma: ver
[Cheque rechazado y reemplazo](#rechazo). Pide motivo. Revierte la aplicación y la
recepción con fecha de hoy, la deuda vuelve, y el recibo queda anulado **con su
número**, que no se reutiliza; la hoja del talonario sigue usada. El cobro queda a la
vista como «Anulado», con el motivo. Si el día de hoy está cerrado, no se puede.

En **Movimientos**, el cobro sigue en su mes, marcado «anulado el …» y sumando: ese
día el recibo existió y el libro lo registró, y los cierres anteriores no cambian. La
anulación es una fila propia, en negativo y con su fecha.

Lo depositado no se anula así, y sigue sin corrección (P13). Un pago bancario
verificado se corrige aparte: ver [Pagos por el banco](#pago-verificado-corregir).

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
  cuenta es una de las [cuentas de la caja](#cuentas-de-la-caja) vigentes en la
  fecha del depósito: con una sola no se elige; con varias, viene la
  predeterminada y se puede cambiar. La fecha no puede ser futura ni anterior al
  cobro. El cheque sale de custodia; el efectivo, de la caja; los dos
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
  importe**. Vuelve a custodia y se deposita como cualquier cobro, sin recibo nuevo
  ni nueva recaudación. Si también se rechaza, se repite el circuito y la ficha
  conserva toda la cadena.
- **Anular el cobro** (`multas.anular-cobros`): para cobrar por otro medio, o con un
  cheque de otro importe. Se puede si el último cheque de la cadena está rechazado y
  sin reemplazo, y ningún rechazo de la cadena figura acreditado. La deuda vuelve, el
  recibo queda anulado con su número y el pago nuevo lleva su propio recibo. La
  recepción **no se revierte**: el cheque entró y salió con el rechazo, y en la
  planilla sigue ese renglón. Sale lo que había que regularizar, con el asiento
  `rejected_cheque_voided` (D `UNASSIGNED_FUNDS` / C `REJECTED_CHEQUES`).
  El rechazo queda a la vista, con el aviso y el papel por completar. **Anular no
  afirma que el banco nunca lo acreditó:** si el extracto trae después el crédito o
  el débito, se registra sobre el rechazo sin reactivar el pago ni pedir reemplazo.
  Esa vía sigue apagada hasta habilitar la revisión bancaria, así que mientras tanto
  ese crédito queda sin identificar. Regla no relevada con el área.
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
  —hoy, o un día anterior con su respaldo: el sello de Mesa de Entradas, una nota—
  y, si es una transferencia judicial, el expediente judicial.
- **Comprobante** (quien informa): cuando aparece el papel, se carga la fecha de pago
  que dice y su archivo, juntos o por separado; los dos son opcionales. La fecha se
  completa o se corrige mientras el pago está informado, y cada cambio queda en la
  auditoría; verificado, queda fija y solo se suma otro archivo. Es informativa: no
  mueve el saldo ni decide el término.
- **Verificar** (`multas.verificar-pagos`): se elige el crédito del extracto. Tiene
  que ser de la cuenta de Multas, desde la apertura de la caja, libre y **por
  el importe entero del pago**: un pago, un crédito, hasta que el área responda P14.
  Al abrir, el diálogo propone los créditos libres de ese importe, con sus señales en
  palabras: primero el del CUIT de la persona de la multa, después los más cercanos a
  la fecha del comprobante (o a la presentación, si no se cargó), como hace Haberes con
  el ticket. Proponer no identifica: elige quien verifica, y «Verificar y emitir recibo»
  no se habilita hasta elegir uno. Buscar a mano en todo el extracto es solo de
  super-admin (`dev.buscar-creditos-multas`, decisión del usuario, 12/11/2026): si el
  crédito no aparece, lo habitual es que el importe informado esté mal y se corrija el
  pago. Al verificar, el pago se aplica, sale el recibo (casilla «Depósito Cta.») y el
  crédito deja de figurar como sin identificar en Banco › Movimientos.
- **Reconocerlo del extracto**: un crédito que nadie informó se identifica desde
  Banco › Movimientos («Identificar crédito»): quien concilia elige la multa y hace
  las dos cosas en un acto, por el importe del crédito. La presentación es ese
  momento: identificar después del último día del mes de la multa es fuera de
  término (M08). «Pago por banco», en la ficha, ya no lo ofrece: ahí solo se
  informa, con la fecha real de presentación, y se verifica después, igual que en
  Haberes se carga el comprobante y luego se cruza con el extracto.
- **Corregir** (quien informa): un informado mal cargado —el importe, el medio— se
  reemplaza por otro. El anterior queda descartado con el motivo, y el corregido
  conserva la misma presentación y la fecha de pago del comprobante: la fecha de
  presentación no se corrige. Se puede corregir aunque otro pago ya haya cancelado la multa; lo que
  sobre queda como excedente al verificarlo. Un depósito de recurso se corrige
  como depósito: si se pagó en efectivo, se descarta y se cobra en la caja. Lo ya
  aplicado tiene recibo y no se corrige así: un cobro de la caja [se anula](#anular-cobro)
  y uno verificado se corrige como sigue.
- **Descartar** (`multas.verificar-pagos`): un informado que no corresponde, con su
  motivo. Nunca tocó el saldo.

<a id="pago-verificado-corregir"></a>

**Corregir un pago verificado** (`multas.corregir-pagos`, solo el administrador, no
se delega): «Corregir pago» pasa el pago a otra multa, a otro crédito o a los dos.
Nada se edita: el pago y su recibo quedan anulados, a la vista y con su número, y
nace otro pago con recibo nuevo, en una sola transacción. Si algo falla, también al
emitir el recibo, el pago original sigue vigente. **El importe no se escribe:** es el
del crédito entero. Si lo mal cargado es lo que debe la multa, se usa «Ajustar».

- **La presentación se conserva.** En la misma multa se conservan también el término
  y su fecha de corte, aunque la fecha de registro haya cambiado después; en otra
  multa, el término se juzga contra esa.
- **La multa de destino** tiene que tener deuda y no estar congelada.
- **Con recurso en trámite** se corrige dentro de su multa, con otro crédito: es la
  única excepción al congelamiento. Con veredicto, primero se revierte.
- **«Solo anularlo»**, si el crédito no era de Multas: la deuda vuelve, el crédito
  queda sin identificar y no nace otro pago. Si era el pago del recurso en trámite, el
  recurso cae. También sirve para destrabar la reversión de un veredicto.
- **El mes ya rendido no cambia:** ver la regla provisoria en la
  [rendición](#rendicion). Un recibo cancelatorio ya emitido tampoco se regenera.

Nada de esto está relevado con el área, salvo que lo haga solo el administrador.

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
- **El recurso es el primer pago de la multa** (decisión del área, 03/11/2026): se
  ofrece solo si la multa tiene deuda y ningún pago ni antecedente, y no tiene otro
  recurso. Un intento descartado no cuenta.
- Un depósito de recurso mal cargado se **corrige** como cualquier informado.

**El trámite.** Registrar el recurso deja la multa **«En recurso»** hasta el
veredicto, aunque el depósito todavía no esté verificado:

- **Se congela lo económico:** no admite otro cobro, pago por banco ni pago
  anterior, ni ajustes, correcciones o reversiones del importe.
- **Sigue lo demás:** el depósito del recurso se verifica, se corrige o se
  descarta; el efectivo se deposita y se acredita; se vinculan documentos —también
  una resolución que llegue— y se adjuntan archivos.
- **Cae** si se descarta su depósito informado mientras está en trámite: la multa se
  descongela y admite otro recurso.

**Resolver recurso** (`multas.cobrar`, sin resolución obligatoria) aparece recién con
el 20 % **cobrado o verificado y su recibo emitido**: en efectivo, al cobrar; por
depósito, al verificar. No trae ningún veredicto marcado.

| Veredicto | Qué pasa con la multa |
|---|---|
| **Prosperó** | Queda **Cancelada** con lo cobrado según el recibo externo, no con el 20 % calculado. Si eso es menos que el importe, una reducción citando el veredicto lo lleva a lo cobrado; si alcanza o sobra, no hay reducción y el excedente queda a la vista. Nada se devuelve y el importe ya no se modifica. |
| **No prosperó** | Se descongela: lo pagado queda a cuenta y el resto se cobra por cualquier medio. No admite otro recurso. |

El veredicto se da sobre el recurso y el pago que muestra la pantalla; si cambiaron
mientras estaba abierto el diálogo, se rechaza y hay que revisar la multa. El depósito
de un recurso ya resuelto no se corrige. Un importe distinto se fija después con
«Ajustar por resolución».

<a id="revertir-veredicto"></a>

**Revertir veredicto** (`multas.cobrar`), para uno cargado por error. Pide motivo. El
recurso vuelve a estar en trámite y la multa, congelada; si prosperó con reducción, se
revierte esa reducción. El veredicto queda en la tabla, con quién lo dio y quién lo
revirtió, y el recurso se puede resolver de nuevo. Como vuelve a congelar la multa, no
se puede si desde el veredicto hubo otros pagos, antecedentes o movimientos de importe:
el mensaje dice cuál y qué hacer (anular el cobro, descartar el informado, rechazar el
antecedente, revertir el ajuste). Un pago ya depositado o verificado, o un antecedente
validado, todavía no tienen corrección: en esos casos el veredicto no se revierte por
ahora. El cobro de un recurso con veredicto no se anula: primero se revierte.

Sigue pendiente, sin relevar, vincular el recurso con la resolución que lo resuelve. El
listado de multas filtra «En recurso».

En la ficha, «Comprobante» se ofrece solo para pagos bancarios. Los cobros
ordinarios en efectivo o cheque ya se respaldan con el recibo del sistema, y el
ticket que se obtiene al llevar esa recaudación al banco pertenece a «Depositar».
Los recursos conservan su carga separada como «Respaldo del recurso», incluso si
se cobraron en efectivo, porque documenta el recibo externo del trámite.

---

<a id="correcciones"></a>

## Completar y corregir datos

- **Completar** es llenar un dato que faltaba o precisar una fecha sin moverla de
  mes. Lo hace quien carga y no pide motivo.
- **Corregir** es cambiar un dato que ya tenía valor. Pide el motivo y el permiso
  de ajustar.

En los dos casos el cambio queda como una revisión con el valor anterior y el
nuevo. Dejar menos precisa una fecha —de un día a «solo el mes»— es corregir.

Todo motivo se elige de una lista que depende del acto —«Error de carga», «Se
leyó mal el papel», «Era de otra multa»…— y lleva un detalle opcional, que es
obligatorio con «Otro». Se guarda el código, así se puede contar cuántas veces
pasó cada cosa ([reglas](multas-reglas.md#motivos)).

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
| `multas.ajustar` | Ajustar y revertir; desvincular documentos; corregir datos ya cargados; validar o descartar pagos anteriores | administrador, contador |
| `multas.reemplazar-origen` | Reemplazar la resolución de origen cargada por error | administrador |
| `multas.corregir-importe` | Corregir un importe mal cargado sin resolución, y revertir esa corrección | administrador |
| `multas.cobrar` | Cobrar en la caja en efectivo o con cheque y emitir el recibo; informar un pago por banco; registrar un recurso y resolverlo | administrador, administrativo |
| `multas.anular-cobros` | Anular un cobro de la caja que sigue sin depositar, o un cheque rechazado sin acreditación registrada. Sensible: administrador y contador |
| `multas.corregir-pagos` | Corregir o anular un pago bancario verificado. Sensible y no delegable: administrador |
| `multas.depositar` | Depositar en el banco un cobro de la caja, con el ticket del cajero | administrador, administrativo |
| `multas.gestionar-rechazos` | Registrar el rechazo de un cheque y completar el aviso y la disposición | administrador, contador |
| `multas.verificar-pagos` | Informar, verificar contra el extracto y descartar pagos por banco; ver los pagos informados en los pendientes; identificar un crédito desde Banco › Movimientos; acreditar o cancelar un depósito de la caja | administrador, contador |
| `multas.caja.abrir` | Abrir la caja de Multas, una sola vez | administrador |
| `multas.caja.ver` | Ver la caja del día, los arqueos, los cierres y el calendario de Multas | administrador, contador, administrativo, consulta |
| `multas.caja.arquear` | Registrar arqueos de Multas | administrador, contador, administrativo |
| `multas.caja.revisar-arqueo` | Revisar arqueos de Multas | administrador, contador |
| `multas.caja.ajustar-diferencia` | Imputar la diferencia de un arqueo de Multas | administrador, contador |
| `multas.caja.cerrar` | Cerrar un día o un mes de Multas | administrador, contador |
| `multas.caja.reabrir` | Reabrir un período de Multas, con motivo | administrador, contador |
| `multas.caja.exportar` | Descargar la planilla de un cierre de Multas | administrador, contador, administrativo, consulta |
| `multas.caja.regenerar-planilla` | Rehacer la planilla de un cierre de Multas | administrador |
| `multas.rendicion.emitir` | Emitir la rendición mensual a la SAF, con el mes cerrado | administrador, contador |
| `multas.importar` | Importar la planilla histórica, decidir su revisión y consolidarla. Sensible | administrador |

Un permiso que se crea por primera vez llega con su asignación de fábrica también a
los roles ya configurados (`RolesAndPermissionsSeeder`); uno que ya existía no se
toca.
