# La Caja de Haberes

Qué hace hoy la Caja, en una página. Para el detalle de por qué cada decisión es
así, el código lo explica en sus comentarios; esto es el mapa.

---

## La idea

La Caja responde **cuánto hay, dónde está y si el papel coincide con el libro**.

Todo movimiento de dinero se asienta por partida doble en `journal_lines`, y los
saldos no se guardan: se suman. Las ocho cuentas son:

| Cuenta | Qué dice |
|---|---|
| `CASH_ON_HAND` | efectivo en el cajón |
| `CHEQUES_IN_CUSTODY` | cheques todavía sin depositar |
| `CASH_IN_TRANSIT` | efectivo depositado que el banco aún no acreditó |
| `BANK_ACCOUNT` | saldo en la cuenta bancaria |
| `UNASSIGNED_FUNDS` | plata recibida que todavía no se sabe de quién es |
| `BENEFICIARY_FUNDS` | plata ya atribuida a un beneficiario |
| `LEGACY_FUNDS` | saldo histórico anterior al sistema |
| `CASH_DIFFERENCE` | diferencias de arqueo imputadas |

Las cuatro primeras dicen **dónde está** la plata; las demás, **de quién es**.

---

## El circuito del día

```
Apertura (una sola vez)
   ↓
Movimientos del día  →  recibos de ingreso y egreso, traslados al banco
   ↓
Arqueo               →  contar el cajón
   ↓
Revisión             →  la firma de otra persona
   ↓
Cierre del día       →  congela los totales y emite la planilla
   ↓
Cierre del mes       →  exige que todos los días con movimiento estén cerrados
```

### Apertura · `/caja/apertura`

Carga el saldo que ya estaba en el cajón el día que el sistema arranca. **Una vez
por caja y moneda**, y solo el administrador (`caja.abrir-saldo-inicial`). Sin
esto todos los saldos son cero y el primer arqueo daría una diferencia igual a
todo el saldo histórico.

**Los cheques se cargan uno por uno**: número, banco, fecha e importe, más
expediente, empresa y beneficiario tal como los dice el papel. Cada uno queda
como una recepción en custodia con su propio asiento —una recepción por hecho,
que es lo que la base exige— y aparece desde el primer día en el inventario del
reverso. Los tres últimos datos son **lo que declaró quien abrió los libros**, no
un dato verificado: si ese cheque se imputa después a una cuota real, manda el
snapshot del recibo.

Detallarlos es opcional, pero la suma tiene que coincidir con el saldo declarado
de cheques —mismo control cruzado que la base impone al arqueo—. Sin detalle el
inventario arranca vacío.

### Caja del día · `/caja/dia`

La pantalla principal. Arriba, los saldos; abajo, los movimientos de la jornada y
el flujo del día: contar, revisar, cerrar, bajar la planilla, reabrir.

Lista también **los asientos que movieron el día**, con su rótulo, su importe y el
día en que se revirtió si se revirtió. Es lo único que explica una jornada donde
el libro cambió y no hay comprobante que lo respalde —una reversión, por ejemplo—.

`/caja` redirige acá conservando los filtros.

### Arqueo · `/caja/arqueos`

Contar el cajón por denominación. Dos números:

- **Recaudación del día** — lo contado billete por billete.
- **Saldo del día anterior (no recontado)** — la plata vieja que queda en el
  cajón y no se recuenta. Exige decir por qué.

**Los dos suman: `contado + arrastre = total en el cajón`.** El arrastre no es
una nota al margen ni un dato de referencia — es parte del contenido declarado, y
la planilla del área lo suma igual en su fila `SALDO DIA ANTERIOR`.

```
diferencia = (contado + arrastre) − saldo del libro
```

La pantalla muestra las tres líneas y la diferencia que va a quedar **antes** de
registrar. El total no se puede tipear: sale de las denominaciones.

> El arrastre es la parte que el arqueo **no verifica**. Si de ese fajo faltara
> plata, el total daría igual y nadie lo vería. Por eso el motivo es obligatorio
> y por eso revisa otra persona.

**El arqueo cuenta solo efectivo.** Los cheques en cartera no se cuentan por
cantidad: cada uno es único —número, banco, fecha, beneficiario— y va listado
uno por uno en el inventario del reverso, que sale de `fund_receipts` con
`cheque_status = 'in_custody'`. No hay tabla de arqueo de cheques.

### Revisión

`draft → reviewed`. **La hace alguien distinto de quien contó**, impuesto por el
Action (`caja.revisar-arqueo`: contador o administrador). Un arqueo que se aprueba
solo no controla nada.

Un borrador se reemplaza contando de nuevo; uno revisado abre el **turno
siguiente** del mismo día.

### Imputar la diferencia (opcional)

`reviewed → adjusted`. Asienta la diferencia contra `CASH_DIFFERENCE` con fecha
del arqueo, para que el libro describa el cajón real. Exige el respaldo de la
autorización —una nota, un acta— y el permiso `caja.ajustar-diferencia`.

**Es opcional a propósito.** Documentar la diferencia sin imputarla es un estado
final válido; lo único que no se puede es cerrar el día con una diferencia
colgando. El `difference_amount` del arqueo **no se borra**: queda como registro
de lo que se encontró, y lo que cambia es el libro.

Antes de imputar conviene descartar la causa: un sobrante casi siempre es un
recibo sin cargar, y esa plata es de alguien.

### Cierre · `/caja/cierres`

`ClosePeriod` congela un snapshot del período —apertura, recibido, pagado,
depositado, saldo final— y emite la planilla. Diario o mensual
(`cierres.cerrar`).

Para cerrar **un día** hacen falta: ningún asiento en borrador, ningún arqueo sin
resolver, un arqueo del día revisado o imputado, sin diferencia sin imputar, y que
el libro no se haya movido desde ese arqueo.

Para cerrar **un mes**: todos sus días con movimiento cerrados.

**Reabrir** (`cierres.reabrir`) exige motivo y deja rastro. Cada cierre estrena
planilla: la anterior queda archivada porque pudo haberse firmado.

### Planilla

Un `.xlsx` que reproduce el formulario del área —anverso `CAJA ddmmyy`, reverso
`REVERSO ddmmyy`—. El reverso lleva **dos tablas**: la rendición del efectivo
—denominaciones, recaudación, arrastre, total— y el **inventario de cheques**,
con recibo, expediente, empresa, beneficiario, número, banco, fecha e importe.
En el cierre mensual la rendición no se repite —el arqueo es un acto del día—
pero el inventario sí: es una posición de cierre.

**Es un adjunto, no una descarga**: se guarda con su sha256, y si el cierre ya
tiene la suya se devuelve esa, para que el papel firmado y el archivo del sistema
sean el mismo objeto. Rehacerla es de administrador
(`cierres.regenerar-planilla`), exige motivo y crea una versión nueva.

### Calendario · `/caja/calendario`

El mes de un vistazo, para encontrar **lo que falta**. Cada día se pinta según su
estado:

| Estado | Qué dice |
|---|---|
| **Cerrado** | tiene cierre diario en firme |
| **Reabierto** | lo tuvo y alguien lo deshizo, con motivo |
| **Con movimientos, sin cerrar** | hubo asientos y nadie cerró |
| **Solo reversiones, sin cerrar** | lo único que hubo fueron reversiones |
| **Sin movimientos** | día tranquilo: no hacía falta cerrarlo |

Los cuatro primeros salen del libro; el estado no se guarda en ninguna tabla,
porque la de cierres **no sabe nada de los días que no tienen uno**.

**«Solo reversiones» no es un estado aparte, es un matiz de «sin cerrar».** Ese día
sigue contando como movimiento y sigue bloqueando el cierre del mes. Se distingue
porque una reversión mueve el libro sin dejar un comprobante que la explique: sin
la marca, quien abría el día buscando el movimiento no encontraba nada.

Eligiendo un día, el panel de la derecha ofrece **el mismo flujo que la Caja del
día** —no una copia de solo lectura— y lista los asientos que lo movieron, con su
rótulo, su importe y, si fue revertido, el día en que se revirtió. También desde
acá se cierra el mes, que es donde se lo está mirando.

### Pagos anteriores · `/caja/pagos-anteriores`

Egresos contra `LEGACY_FUNDS`, el saldo previo al sistema. Exige referencia al
registro manual, porque la única evidencia es la planilla en papel.

---

## Monedas

El mismo cajón guarda pesos y dólares, y la base los lleva **separados en todo**:
arqueos, cierres y saldos son por moneda. Son dos libros paralelos sobre una misma
caja.

Cuál se está mirando viaja en la URL: `/caja/dia?moneda=usd`. Los pesos no se
anotan. No hay vista con las dos juntas, y no debería haberla: sumar pesos con
dólares no significa nada.

Las denominaciones sugeridas dependen de la moneda.

---

## Lo que la base impide

Los invariantes viven en PostgreSQL, no solo en PHP. El Action da el mensaje
legible; el trigger impide el desastre.

- `journal_lines` y `financial_events` son **append-only**; un asiento se revierte,
  no se edita.
- Cada asiento **balancea por moneda**.
- Un período cerrado **no admite movimientos** con fecha adentro…
- …ni un movimiento anterior que **desactualice un cierre posterior**: la apertura
  de cada cierre es el saldo a su víspera.
- No se arquea un día que cae dentro de un período cerrado.
- No se cierra un día sin arqueo resuelto, ni un mes con días sin cerrar.
- El total del arqueo sale de sus denominaciones: un total tipeado no se audita.

---

## Quién puede qué

| | consulta | administrativo | contador | administrador |
|---|:-:|:-:|:-:|:-:|
| Ver la caja | ✓ | ✓ | ✓ | ✓ |
| Contar el cajón | | ✓ | ✓ | ✓ |
| Revisar el arqueo | | | ✓ | ✓ |
| Imputar la diferencia | | | ✓ | ✓ |
| Cerrar y reabrir | | | ✓ | ✓ |
| Pagar saldo anterior | | | ✓ | ✓ |
| Abrir los libros | | | | ✓ |
| Rehacer una planilla | | | | ✓ |

Contar es trabajo de mostrador; revisar y mover plata contra una cuenta de
diferencias, no.

---

## Pendiente con el área

Anotado en `../../Relevamiento/Correcciones-al-DER-pendientes.md` §23:

- **La planilla en dólares.** La que genera el sistema es la de pesos.
- **El recibo preimpreso dice «Son Pesos:»**, así que un ingreso en dólares no
  tiene comprobante válido que entregar.
- **El panel de caja del tablero** muestra un saldo y son dos.
- **Nadie coteja los cheques físicos.** En el papel, escribir esa lista *es* la
  verificación: alguien los tuvo en la mano. Acá la lista se genera de la base,
  así que no dice nada del cajón. Si el área quiere ese control, es una tilde
  por cheque en el arqueo —y eso sí necesita relevamiento—.
