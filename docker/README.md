# Levantar SAC-ST con Docker

Lo único que hace falta en la máquina es **Docker**. Ni PHP, ni Node, ni
PostgreSQL: todo eso vive adentro de los contenedores, y la base de datos es
propia del entorno —un volumen de Docker— así que no toca ninguna instancia
de PostgreSQL que ya esté instalada.

```bash
docker compose up
```

La primera vez tarda unos minutos: construye la imagen, instala las
dependencias de PHP y de JavaScript, crea las dos bases, corre las 73
migraciones y siembra el catálogo. Las veces siguientes arranca en segundos.

Cuando termina:

| | |
|---|---|
| Sistema | <http://localhost:8000> |
| Usuario | `admin` |
| Contraseña | `Contrasena.Segura.2026` |
| PostgreSQL | `localhost:5434`, usuario `sacst`, contraseña `sacst` |

Para frenar todo, `Ctrl+C` o `docker compose down`. Los datos sobreviven:
viven en un volumen, no en los contenedores.

## Qué levanta

| Servicio | Qué hace |
|---|---|
| `db` | PostgreSQL 18 con las bases `sacst_dev` y `sacst_test` |
| `init` | Instala, migra y siembra. Corre una vez y termina; los demás lo esperan |
| `app` | El servidor de Laravel en el puerto 8000 |
| `queue` | El worker de la cola |
| `vite` | El servidor de desarrollo del front, con recambio en caliente |

## El día a día

Los comandos van adentro del contenedor `app`:

```bash
docker compose exec app php artisan migrate
```

```bash
docker compose exec app php artisan tinker
```

```bash
docker compose exec app composer types:ts
```

Y si hace falta una terminal completa adentro:

```bash
docker compose exec app bash
```

## Tests

Corren contra `sacst_test`, que es una base aparte de la de desarrollo. El
nombre está forzado en `phpunit.xml`, así que no hay forma de que una corrida
de Pest se lleve puestos los datos con los que estás probando el circuito.

```bash
docker compose exec app php artisan test
```

Lo mismo que corre CI —pint, phpstan, pest, eslint, prettier, tsc y vitest—:

```bash
docker compose exec app composer ci:check
```

**Una sola corrida de Pest a la vez.** Dos en paralelo se traban entre sí
sobre `sacst_test` y el resultado que devuelven no sirve para nada.

## Datos de prueba

La base arranca con lo mínimo: permisos, series, cajas, etiquetas y el
usuario administrador. **Ningún dato de demo se carga solo.** Los escenarios
son alternativos entre sí y se piden por nombre:

```bash
docker compose exec app php artisan db:seed --class=HaberesDemoSeeder
```

| Seeder | Qué carga | |
|---|---|---|
| `PersonaDemoSeeder` | Las dieciséis contrapartes de muestra | Aditivo |
| `HaberesDemoSeeder` | Seis expedientes del relevamiento | Aditivo |
| `EscenariosDemoSeeder` | Tres expedientes con sus bordes | **Reemplaza** |
| `CajaDemoSeeder` | La planilla de caja de junio | **Reemplaza** |
| `OperacionDemoSeeder` | Dos meses de operación día por día | **Reemplaza** |

Los que dicen «reemplaza» vacían el circuito antes de construir: correr dos
seguidos deja solo el último.

## Empezar de nuevo

Acá sí se puede, y es la diferencia con el entorno nativo: esta base no tiene
datos cargados a mano que no estén en ningún otro lado.

```bash
docker compose down -v && docker compose up
```

`-v` borra los volúmenes: base, `vendor` y `node_modules`. Vuelve a armar
todo desde cero.

## Rendimiento: dónde tiene que vivir el código

Esto importa y está medido, no supuesto.

El proyecto entra al contenedor por un bind mount. Cuando ese bind mount apunta
a un disco de Windows (`C:`, `E:`, cualquiera), **cada lectura de archivo cuesta
unas ochenta veces más** que dentro del propio Docker: recorrer los 364 archivos
de `resources` tardó 0,44 s contra 0,015 s de un volumen nombrado que tiene casi
el triple de archivos.

Eso no se nota en PHP, que lee unos pocos archivos por pedido. Se nota en Vite,
que lee cientos: Wayfinder genera 133 archivos de rutas y acciones, y el layout
los importa todos. Medido sobre un disco de Windows, con el entorno recién
levantado:

| | |
|---|---|
| Vite queda listo | ~40 s |
| Primera pantalla que se abre | ~90 s |
| Las siguientes | ~11 s |
| Un módulo ya transformado | 4 ms |

**Esos 90 segundos son normales y no significan que se colgó.** Para que el
costo se lo coma el arranque en lugar de quien está mirando la pantalla, el
entorno precalienta los archivos generados al levantar. Aun así:

**En Windows conviene que el proyecto viva adentro de WSL2**, en el sistema de
archivos de Linux y no en un disco de Windows: desde una terminal de WSL,
`~/proyectos/...`. Ahí no hay traducción de por medio, el problema desaparece
entero y el recambio en caliente puede dejar de sondear. Es la recomendación de
Docker Desktop, no una manía de este proyecto.

En Linux no hay nada que hacer: el bind mount es nativo. En macOS el costo
existe pero es bastante menor.

Si el proyecto igual queda en un disco de Windows, funciona —todo lo que dice
este documento se probó así—, solo que con esa primera carga lenta y con Vite
sondeando archivos, que consume CPU de forma constante.

## Si algo no anda

**La pantalla queda en blanco.** Tres causas posibles, y la consola del
navegador las distingue en un vistazo:

- *Todavía está cargando.* La primera vez que se abre una pantalla, Vite
  transforma cientos de módulos desde el bind mount y eso tarda minutos. No hay
  error en la consola, solo pedidos pendientes. Ver la sección anterior.
- *CSP.* Dice `invalid source` o menciona `0.0.0.0:5173`: el servicio `vite`
  anunció mal su dirección y la política bloqueó sus módulos.
- *CORS.* Dice `blocked by CORS policy`: Vite está devolviendo un
  `Access-Control-Allow-Origin` que no es el de la aplicación. Ese valor sale
  de `APP_URL`, así que si lo cambiaste, cambialo en `docker-compose.yml` y no
  solo en el `.env`, o los dos dejan de coincidir.

```bash
docker compose logs vite
```

**Un cambio en el front no se ve.** Confirmá que el servicio `vite` está
arriba (`docker compose ps`). Si no lo está, el sistema sirve el bundle viejo
de `public/build`, que puede tener meses.

Si `vite` está arriba y aun así no reacciona, es el sondeo de archivos: los
eventos de cambio del anfitrión no cruzan un bind mount, por eso el entorno
sondea. Viene activado y se apaga con `SACST_VITE_POLLING=0`, que tiene sentido
únicamente si el proyecto vive dentro de WSL2 o en Linux, donde los eventos sí
llegan y el sondeo solo gasta CPU.

**`init` falla y los demás no arrancan.** Es a propósito: sin dependencias
instaladas ni migraciones aplicadas, lo que levantaría después daría errores
que no se parecen a la causa. El log dice qué paso falló:

```bash
docker compose logs init
```

**Cambió el `composer.json` o el `package.json`.** Basta con volver a
disparar el init:

```bash
docker compose up init
```

## Sobre el entorno nativo

Este entorno no reemplaza al de la máquina: convive con él.

- Tu `.env` **no se toca**. Si ya existe, se respeta tal cual está; los datos
  de conexión se inyectan como variables de entorno desde
  `docker-compose.yml`, que en Laravel le ganan al archivo.
- La base de Docker está en el puerto **5434** y es un volumen aparte.
  `sacst_dev` de tu PostgreSQL local, en el 5433, sigue intacta.
- `vendor/` y `node_modules/` de los contenedores viven en volúmenes propios:
  los binarios de Linux no pisan los que tengas compilados para Windows.

Lo único que sí es compartido es `public/hot`, el archivo donde Vite anota
dónde está escuchando. **No corras el Vite de Docker y el nativo a la vez**:
el segundo pisa al primero y la CSP termina habilitando el origen equivocado.
