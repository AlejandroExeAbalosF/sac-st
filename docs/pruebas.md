# Verificación por etapas y módulos

`composer ci:check` conserva todos los controles y corta ante el primer error:

1. `composer ci:frontend`: ESLint, Prettier, TypeScript y Vitest.
2. `composer ci:php`: limpieza de configuración, Pint y PHPStan.
3. `composer test:parallel`: toda la suite PHP, dividida como se describe abajo.

Los comandos por módulo sirven para iterar mientras se desarrolla.
Al modificar infraestructura compartida también hay que probar sus consumidores:
por ejemplo, cambiar Banking puede afectar Haberes, Multas y Aranceles. Antes de
integrar o publicar se ejecuta `composer ci:check` completo.

## Comandos por área

Para trabajar en un área sin repetir todos los controles:

| Comando | Alcance |
| --- | --- |
| `composer ci:frontend` | ESLint, Prettier, TypeScript y Vitest |
| `composer ci:php` | Limpieza de configuración, Pint y PHPStan |
| `composer test:haberes` | Pruebas de Haberes, incluidas las unitarias |
| `composer test:multas` | Pruebas de Multas, incluidas las de concurrencia |
| `composer test:aranceles` | Pruebas de Aranceles, incluidas las de concurrencia |
| `composer test:banking` | Pruebas de Banking, incluidas las unitarias |
| `composer test:ledger` | Pruebas del motor contable y cajas |
| `composer test:shared` | Pruebas compartidas, incluidas las unitarias |
| `composer test:configuracion` | Pruebas de configuración |
| `composer test:security` | Autenticación, seguridad, cuenta y navegación |
| `composer test:unit` / `composer test:arch` | Unitarias / arquitectura |
| `composer test:parallel` | Todas las pruebas PHP, en tres etapas |
| `composer test:serial` | Todas las pruebas PHP, en un proceso |

`composer test` conserva el comportamiento anterior: Pint, PHPStan y toda la
suite PHP en serie. Los comandos por módulo solo ejecutan pruebas; no reemplazan
el control completo antes de integrar o publicar. Los cambios en Shared, Ledger,
Banking, permisos o migraciones también pueden afectar a los módulos consumidores.

Se puede filtrar un caso con `composer test:multas -- --filter=NombreDelCaso`.
Para revisar tiempos, usar `composer --profile -v ci:check`; para encontrar los
casos más lentos, `composer test:serial -- --profile`.

## Cómo se divide la suite PHP

| Etapa | Selección | Ejecución |
| --- | --- | --- |
| `test:parallel:main` | Unit y Feature, salvo el grupo `serial` | Cuatro procesos |
| `test:exclusive` | Unit y Feature del grupo `serial` | Un proceso, después de los anteriores |
| `test:arch` | Arch | Un proceso al final |

Laravel crea una base PostgreSQL por proceso: `sacst_test_test_1` hasta
`sacst_test_test_4`. El usuario de pruebas necesita permiso `CREATEDB`; no necesita
ser superusuario. `RefreshDatabase` prepara el esquema y el catálogo una vez por
proceso, y revierte los datos de cada caso mediante transacciones. `Storage::fake`
también separa los directorios por proceso. Las bases se conservan entre corridas;
la primera prueba con `RefreshDatabase` vuelve a preparar el esquema.

Las pruebas de concurrencia real usan conexiones o procesos auxiliares que
necesitan ver datos confirmados. Recrean `sacst_test` antes de cada caso y sus
guardas siguen exigiendo ese nombre exacto. Se marcan con el atributo PHPUnit
`#[Group('serial')]` y se ejecutan en la etapa exclusiva. También pertenece a ese
grupo la prueba del seeder que compara el inventario global de archivos
temporales: otro proceso podría cambiarlo durante su ejecución.

Las pruebas de arquitectura corren aparte para no cargar su análisis de clases
en los procesos que prueban los módulos.

## Agregar pruebas

- Las pruebas nuevas de Unit, Feature y Arch entran automáticamente en sus suites;
  no hay que mantener una lista de archivos en Composer.
- Una prueba Unit o Feature que recree la base fuera de `RefreshDatabase`, exija
  `sacst_test` o inspeccione recursos globales compartidos debe llevar
  `#[Group('serial')]`, importando `PHPUnit\Framework\Attributes\Group`.
- Mantener las guardas que impiden ejecutar las pruebas destructivas sobre bases
  operativas. No cambiar la base de desarrollo ni pasar `DB_DATABASE`/`DB_URL` del
  entorno operativo a una corrida de pruebas.
- No iniciar dos comandos de pruebas independientes al mismo tiempo, aunque sean
  de módulos distintos: reutilizan los mismos nombres de bases de prueba.

## Menos procesos o diagnóstico en serie

En una máquina con menos memoria o CPU, ejecutar estas etapas consecutivamente:

```bash
php artisan test --parallel --processes=2 --testsuite=Unit,Feature --exclude-group=serial
composer test:exclusive
composer test:arch
```

Para ejecutar todas las pruebas en un proceso, usar `composer test:serial`.
`composer test` además ejecuta Pint y PHPStan, como antes de esta división.

## Medir sin confundir tiempos

```bash
# Tiempos acumulados y memoria de Composer; permiten ubicar cada etapa.
composer --profile -v ci:check

# Casos PHP más lentos, sin interferencia de otros procesos de prueba.
composer test:serial -- --profile

# Informe JUnit con los tiempos por caso para comparar corridas.
composer test:serial -- --log-junit=output/pruebas.xml
```

Crear `output/` antes de escribir el informe; está ignorado por Git. Comparar en la
misma máquina, con la misma base PostgreSQL y sin otras corridas simultáneas. Una
ejecución con cachés calientes no representa necesariamente el tiempo de un CI
recién instalado. La suma de tiempos por caso en paralelo tampoco equivale al
tiempo de espera total: varios casos se ejecutan al mismo tiempo.

## Medición del 6 de noviembre de 2026

Medido en el entorno nativo Windows con PHP 8.5.12 y PostgreSQL, sin otra
corrida de pruebas simultánea. Los tiempos incluyen el arranque de cada etapa.

| Etapa | Tiempo |
| --- | --- |
| Suite PHP original en serie (2.128 casos) | 14 min 52 s |
| Unit/Feature en cuatro procesos (2.051 casos) | 3 min 42 s |
| Pruebas exclusivas (55 casos) | 3 min 20 s |
| Arquitectura (24 casos) | 19 s |
| Suite PHP nueva completa (2.130 casos) | 7 min 21 s |
| Frontend completo | 2 min 57 s |
| Preparación, Pint y PHPStan | 58 s |
| `composer ci:check` completo nuevo | 11 min 17 s |

La reducción medida de la suite PHP es aproximadamente del 51 %. El tiempo
original corresponde solo a PHP; no se midió un `ci:check` completo anterior.
Las dos pruebas nuevas verifican la base real y el directorio de adjuntos del
proceso. La unión de las tres etapas coincide con el inventario completo, sin
omisiones ni duplicados. El resultado final fue 2.129 pruebas PHP aprobadas,
una omisión preexistente y 185 pruebas frontend aprobadas; todos los controles
de formato y análisis estático pasaron.

La corrida original registró un fallo al esperar un bloqueo PostgreSQL en
`CorreccionConcurrenteDeMultasTest::test_a_late_credit_while_voiding_is_recorded_without_reviving_the_payment`.
El caso pasó al repetirlo aislado y también dentro de la etapa exclusiva final.
Una comprobación posterior ejecutó diez veces el caso aislado y tres veces su
clase completa: las trece corridas pasaron (31 ejecuciones de casos, sin fallos).
No se cambiaron sus condiciones ni sus plazos de espera; este resultado no
demuestra que el fallo intermitente esté resuelto.
