<div align="center">

<img src="docs/logo.svg" alt="SAC-ST" width="132">

# SAC-ST

**Sistema Administrativo Contable**

Secretaría de Trabajo · Provincia de Salta

[![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white)](https://www.php.net)
[![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)](https://laravel.com)
[![React](https://img.shields.io/badge/React-19-61DAFB?logo=react&logoColor=black)](https://react.dev)
[![TypeScript](https://img.shields.io/badge/TypeScript-strict-3178C6?logo=typescript&logoColor=white)](https://www.typescriptlang.org)
[![PostgreSQL](https://img.shields.io/badge/PostgreSQL-18-4169E1?logo=postgresql&logoColor=white)](https://www.postgresql.org)
[![Tests](https://img.shields.io/badge/tests-1003%20passing-3fb950)](#verificación)

</div>

---

Administra **dinero de terceros**: haberes que un empleador deposita para un
trabajador y que el organismo custodia hasta entregarlos. Cada decisión de diseño
se toma del lado de la trazabilidad antes que de la comodidad.

Reemplaza un circuito que hoy vive en planillas de cálculo y formularios en papel.

## Estado

El área maneja **tres cajas**. La primera está implementada; las otras dos ya
existen en el modelo de datos, pero todavía no tienen circuito.

| Caja                        | Estado          | Movimientos      |
| --------------------------- | --------------- | ---------------- |
| **Haberes en consignación** | ✅ Implementada | ingreso y egreso |
| **Aranceles**               | ⏳ Pendiente    | -                |
| **Multas**                  | ⏳ Pendiente    | -                |

La caja es una clasificación
contable, no una cuenta física. Por eso el motor contable se construyó sin saber
qué es un expediente — cuando entren Aranceles y Multas, reutilizan el mismo libro.

Las tres están sembradas desde ahora, aunque solo Haberes tenga circuito: la caja
a la que van a pertenecer sus eventos ya existe, así que construirlas no va a
exigir tocar el motor.

De Aranceles y Multas **no hay nada operativo todavía, tampoco el ingreso**. Lo
único definido es la regla que las gobierna: no admitirán egresos, porque recaudan
para el Estado y lo que entra no vuelve a salir por esa caja, se rinde. El circuito
entero está por hacerse y su relevamiento, pendiente.

## Arquitectura

**Monolito modular organizado por dominio de negocio**, no por capa técnica. No
hay una carpeta `Controllers/` con todos los controladores del sistema: hay un
módulo por área —`Shared`, `Ledger`, `Banking`, `Haberes`— y adentro de cada uno
viven sus modelos, sus Actions y sus controladores.

Lo que sostiene esa división es una regla: **la dependencia entre módulos es
dirigida**, nunca lateral ni ascendente. Cada módulo puede apoyarse en el
siguiente y en ninguno más.

```
Haberes  ──→  Banking  ──→  Ledger  ──→  Shared
```

`Ledger` **no puede saber que existen expedientes ni cuotas**. Esa frontera es lo
que permitirá que Aranceles y Multas lo reutilicen, y no depende de la disciplina
de nadie: se verifica en `tests/Arch/ModuleBoundariesTest.php` y, si alguien la
cruza, falla CI.

<table>
<tr><th align="left">Módulo</th><th align="left">Qué contiene</th><th align="left">Tablas</th></tr>
<tr><td><b>Haberes</b></td><td>el dominio</td><td><code>expedientes</code> · <code>haberes</code> · <code>beneficiary_installments</code> · <code>funding_allocations</code> · <code>payment_orders</code> · <code>payment_order_funding_sources</code> · <code>pases</code> · <code>disbursements</code> · <code>deposit_tickets</code> · <code>cash_to_bank_transfer_items</code> · <code>haber_management_labels</code></td></tr>
<tr><td><b>Banking</b></td><td>cuentas, extractos y conciliación</td><td><code>bank_accounts</code> · <code>bank_statement_imports</code> · <code>bank_statement_rows</code> · <code>bank_transactions</code> · <code>bank_transaction_allocations</code> · <code>cash_to_bank_transfers</code></td></tr>
<tr><td><b>Ledger</b></td><td>el motor contable</td><td><code>financial_events</code> · <code>journal_lines</code> · <code>fund_receipts</code> · <code>receipt_financial_events</code> · <code>cash_counts</code> · <code>cash_count_lines</code> · <code>period_closings</code></td></tr>
<tr><td><b>Shared</b></td><td>lo que todos usan</td><td><code>people</code> · <code>person_roles</code> · <code>person_bank_accounts</code> · <code>cash_boxes</code> · <code>document_series</code> · <code>receipts</code> · <code>attachments</code> · <code>audit_events</code> · <code>users</code> · <code>user_login_events</code></td></tr>
</table>

> [!IMPORTANT]
> **Los invariantes viven en la base de datos**, no solo en PHP: `CHECK`, `UNIQUE`
> parciales, claves foráneas compuestas y triggers. Un Action da el mensaje de
> error legible; la base impide el desastre.

## El circuito

Lo que se reconoce y lo que se mueve son cosas distintas, y el modelo las separa:

| | |
|---|---|
| **Expediente** | el derecho reconocido. No registra dinero |
| **Haber** | lo que le toca a un beneficiario dentro de ese expediente |
| **Cuota** | los importes, que vienen del expediente: el sistema no reparte el total en partes iguales |

El expediente llega con su haber, así que el beneficiario y el medio de pago se
conocen desde el principio. De ahí salen dos vías:

| Vía | Cómo entra el dinero |
|---|---|
| **Por mostrador** | el efectivo llega junto con el expediente. Cobrar y entregar el recibo son **un solo acto**: el recibo es la constancia de que ingresó |
| **Por depósito** | el empleador deposita y trae el comprobante. El sistema lo cruza contra el crédito del extracto —cruzar es una lectura, no mueve plata— y con eso asienta la recepción y financia la cuota |

Con la cuota financiada siguen los mismos pasos en las dos vías:

| Paso | Qué ocurre |
|---|---|
| **Orden de Pago + Pase** | se remiten al organismo superior. El expediente no viaja |
| **Egreso** | el dinero sale hacia su dueño |
| **Recibo** | el comprobante que se entrega |

Un crédito que aparece en el extracto sin comprobante que lo explique queda como
**fondo sin identificar** hasta que se sepa a quién corresponde. Es la excepción
que el sistema contempla, no el camino habitual.

El día de caja tiene su propio ciclo — apertura, movimientos, arqueo, revisión de
otra persona, cierre —, documentado en **[docs/caja-de-haberes.md](docs/caja-de-haberes.md)**.

Los comprobantes reproducen formularios preimpresos, así que se maquetan con
tablas y `float`: las plantillas de `resources/views/pdf/` las renderiza DomPDF,
no un navegador.

## La idea contable

Todo movimiento se asienta por **partida doble** en `journal_lines`. Los saldos no
se guardan en ninguna columna: se suman.

| Cuenta               | Qué dice                                          |
| -------------------- | ------------------------------------------------- |
| `CASH_ON_HAND`       | efectivo en el cajón                              |
| `CHEQUES_IN_CUSTODY` | cheques todavía sin depositar                     |
| `CASH_IN_TRANSIT`    | efectivo depositado que el banco aún no acreditó  |
| `BANK_ACCOUNT`       | saldo en la cuenta bancaria                       |
| `UNASSIGNED_FUNDS`   | plata recibida que todavía no se sabe de quién es |
| `BENEFICIARY_FUNDS`  | plata ya atribuida a un beneficiario              |
| `LEGACY_FUNDS`       | saldo histórico anterior al sistema               |
| `CASH_DIFFERENCE`    | diferencias de arqueo imputadas                   |

Se opera en **pesos y dólares**, llevados separados en todo: arqueos, cierres y
saldos son por moneda. Sumar las dos no significaría nada.

`journal_lines` y `financial_events` son **append-only**: un asiento no se edita,
se revierte con su contrapartida. Lo impone un trigger de PostgreSQL.

## Acceso

Cuatro roles y 38 permisos. No hay registro público: las altas las hace un
administrador.

|                           | consulta | administrativo | contador | administrador |
| ------------------------- | :------: | :------------: | :------: | :-----------: |
| Ver la caja               |    ✓     |       ✓        |    ✓     |       ✓       |
| Contar el cajón           |          |       ✓        |    ✓     |       ✓       |
| Revisar el arqueo         |          |                |    ✓     |       ✓       |
| Cerrar y reabrir períodos |          |                |    ✓     |       ✓       |
| Abrir los libros          |          |                |          |       ✓       |

Contar es trabajo de mostrador; revisar y mover plata contra una cuenta de
diferencias, no.

## Stack

PHP 8.5 · Laravel 13 · Inertia 3 · React 19 · TypeScript estricto · Tailwind 4 ·
shadcn/ui sobre Radix UI · PostgreSQL 18 · pnpm

> [!NOTE]
> Los importes **nunca son `float`** en ningún punto de la pila: `numeric(19,2)`
> en la base, `brick/money` en PHP, y al front llegan como string.

## Levantarlo

Con **solo Docker** instalado — sin PHP ni PostgreSQL en la máquina:

```bash
docker compose up
```

Queda en `localhost:8000` con la base creada, migrada y sembrada. El detalle está
en **[docker/README.md](docker/README.md)**.

Con el entorno nativo ya instalado:

```bash
composer setup && composer dev
```

## Verificación

```bash
composer ci:check
```

Es lo mismo que corre CI: Pint, PHPStan sin baseline, Pest **contra PostgreSQL
real** — nunca SQLite: lo que se prueba son constraints, triggers y tipos que
SQLite no tiene —, ESLint, Prettier, `tsc` y Vitest.

Hoy son **1003 tests** con 4898 aserciones.

> [!WARNING]
> Una sola corrida de Pest a la vez: todas comparten la base `sacst_test` y dos
> corridas simultáneas se traban entre sí.

## Documentación

El relevamiento funcional y el modelo de datos viven **fuera de este repositorio**,
en poder del área. Acá adentro:

| Documento                                          | Qué cubre                                        |
| -------------------------------------------------- | ------------------------------------------------ |
| [docs/caja-de-haberes.md](docs/caja-de-haberes.md) | Cómo funciona hoy la Caja, pantalla por pantalla |
| [docker/README.md](docker/README.md)               | El entorno de desarrollo en contenedores         |

El código explica en sus comentarios **por qué** cada decisión es así; los
documentos son el mapa.
