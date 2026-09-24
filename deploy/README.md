# Deploy de SAC-ST

Cómo se instala y se opera SAC-ST en un servidor del data center con Docker.

> **El repo es público.** Esta guía no nombra IPs, dominios ni rutas reales del
> servidor: donde hace falta uno aparece un marcador como `<IP_DEL_SERVIDOR>`.
> Los valores reales viven solo en el servidor, en `/etc/sacst/`.

Fase inicial: **HTTP por VPN**. El data center corre su análisis de
vulnerabilidades y, con el visto bueno, habilita el certificado en su proxy. El
pasaje a HTTPS es un cambio de configuración (sección 8).

---

## 1. Cómo está armado

```
  Usuario (VPN)
      │
      ▼
  proxy del DATA CENTER          ← otra máquina; no la administramos
      │ http://<IP_DEL_SERVIDOR>:8080
  ════╪══════════════════════════════════════════ servidor
      ▼
  web (nginx) ──▶ app (php-fpm) ──▶ PostgreSQL 18 del HOST (10.200.1.1)
                  queue (worker)          │
                  migrate (una vez)       └─ roles sacst_owner / sacst_app
                       │
                       └─ storage/ = SACST_STORAGE_PATH del servidor
```

| Pregunta | Decisión | Por qué |
|---|---|---|
| ¿De dónde salen las imágenes? | De **GHCR**. Las compila GitHub Actions al empujar un tag, después de que pasan los tests. | El servidor no compila nada: corre exactamente la imagen que pasó CI, y volver atrás es cambiar la versión. |
| ¿PostgreSQL en el host o en un contenedor? | **Host.** | Parches por `apt`, backups con `pg_dump` y un dato que no depende de un volumen de Docker: un `down -v` no lo borra. |
| ¿Con qué usuario se conecta la app? | **`sacst_app`**, que no es dueño de nada. Las migraciones usan **`sacst_owner`**. | El dueño de una tabla puede apagarle los triggers o vaciarla con `TRUNCATE`. Los invariantes append-only solo protegen si la app no puede hacer eso. Ver `postgres/setup.sql`. |
| ¿Dónde quedan los archivos del sistema? | En **`SACST_STORAGE_PATH`**, una carpeta del servidor fuera del repo: adjuntos, logs de auditoría y de seguridad, caché. | Sobreviven a cada versión nueva y pueden ir en un disco de datos aparte. |
| ¿Dónde quedan los secretos? | En **`/etc/sacst/`**, de root y con permisos 600. Nunca en el repo ni en la imagen. | La imagen es pública, como el código. |
| ¿Quién pone las cabeceras de seguridad? | **La app** (`config/security.php`). Ni nginx ni el proxy del data center las repiten. | Duplicadas, ZAP las marca; con un `X-Frame-Options: DENY` de más, los visores de comprobantes quedan en blanco. |

### Archivos

| En el repo (`deploy/`) | Para qué |
|---|---|
| `compose.yml` | El stack. Se opera con `bin/sacst`. |
| `bin/sacst` | `docker compose` con el archivo de variables del servidor ya puesto. |
| `Dockerfile` | Las dos imágenes: `app` (php-fpm) y `web` (nginx). |
| `docker/` | `php.ini`, nginx y el entrypoint que van adentro de la imagen. |
| `env/*.example` | Plantillas de los tres archivos de `/etc/sacst/`. |
| `postgres/setup.sql` | Base, roles y privilegios. Se corre una sola vez. |
| `proxy/sacst.conf.example` | La configuración que se le entrega al data center para su proxy, con marcadores. |
| `compose.build.yml` | Compilar en la máquina en vez de bajar de GHCR. Solo para probar o como salida de emergencia. |

| En el servidor, fuera del repo | Qué tiene |
|---|---|
| `/etc/sacst/compose.env` | Versión, carpeta de datos, IP y puerto. Lo lee compose. |
| `/etc/sacst/app.env` | El entorno de Laravel, con `sacst_app`. |
| `/etc/sacst/migrate.env` | Las credenciales de `sacst_owner`. Solo las ve `migrate`. |
| `SACST_STORAGE_PATH` | Lo que el sistema recibe o genera. |

---

## 2. Preparación del servidor (una sola vez)

La misma que en los otros sistemas del data center: Ubuntu Server 24.04 LTS,
en la LAN del organismo y detrás de un proxy del data center, en otra máquina.

### 2.1 Sistema, reloj y salida a internet

```bash
cat /etc/os-release        # Ubuntu 24.04
df -h /                    # imágenes + capas: dejar ~6 GB libres
ip -4 addr show            # anotar la IP de la LAN: es <IP_DEL_SERVIDOR>
timedatectl                # UTC, NTP activo y sincronizado
```

**El host queda en UTC.** Los contenedores también, y la app convierte a la
hora de Salta al mostrar. Si el host estuviera en `-03`, `journalctl` y los
logs de Laravel mostrarían dos relojes distintos en la misma línea. El reloj
tiene que estar sincronizado: el doble factor falla con más de ~30 segundos de
desfase.

El servidor necesita **salida** hacia estos destinos. Probarlo antes de seguir:

```bash
curl -sI https://download.docker.com/linux/ubuntu/gpg | head -1   # Docker
curl -sI https://apt.postgresql.org/pub/repos/apt/ | head -1       # PostgreSQL
curl -sI https://ghcr.io/v2/ | head -1                             # imágenes (401 es la respuesta correcta)
curl -sI https://api.pwnedpasswords.com/range/00000 | head -1       # ver nota
nc -zv smtp.gmail.com 587                                          # correo
```

> **`api.pwnedpasswords.com`**: en producción, la política de contraseñas
> compara cada contraseña nueva contra la base de filtraciones conocidas
> (`uncompromised()` en `AppServiceProvider`). Si el data center bloquea esa
> salida **descartando** paquetes en vez de rechazarlos, cada cambio de
> contraseña espera el timeout de 30 segundos antes de seguir. Si no hay salida,
> pedirla o avisar para desactivar esa verificación.

### 2.2 Docker Engine

Desde el repositorio oficial, para que las actualizaciones entren por
`apt upgrade`:

```bash
for p in docker.io docker-doc docker-compose podman-docker containerd runc; do
    sudo apt-get remove -y "$p" 2>/dev/null || true
done

sudo apt-get update
sudo apt-get install -y ca-certificates curl
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] \
https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
    | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
```

**Las redes de Docker se sacan de su rango por defecto (`172.17.0.0/16`)
antes de levantar nada.** Si la LAN del servidor usa ese mismo rango —y en
redes corporativas es habitual—, el resto de la LAN queda inalcanzable desde
este servidor, y el `pg_hba.conf` tendría que abrirse a toda la red. Moverlas
no cuesta nada y evita el problema aunque hoy no haya choque.

```bash
sudo tee /etc/docker/daemon.json > /dev/null <<'EOF'
{
  "bip": "10.200.0.1/24",
  "default-address-pools": [
    { "base": "10.201.0.0/16", "size": 24 }
  ],
  "log-driver": "journald",
  "live-restore": true
}
EOF
sudo systemctl enable docker
sudo systemctl restart docker      # restart y no start: el paquete ya lo dejó corriendo
ip -4 addr show docker0            # DEBE decir 10.200.0.1/24
```

**Docker se opera con `sudo`, no con el grupo `docker`.** Estar en ese grupo es
ser root sin contraseña y sin rastro en el log de auditoría. Para leer logs sin
`sudo` alcanza con el grupo `adm`, que es de solo lectura:

```bash
sudo usermod -aG adm "$USER"       # cerrar la sesión SSH y volver a entrar
```

### 2.3 Red del stack

Tiene subred fija porque su gateway, `10.200.1.1`, es la dirección en la que
PostgreSQL escucha y la que autoriza el `pg_hba.conf`. Es externa para que
sobreviva a un `down`. Se crea **antes** de configurar PostgreSQL: si el
gateway no existe, PostgreSQL no puede escuchar ahí.

```bash
sudo docker network create --subnet 10.200.1.0/24 --gateway 10.200.1.1 sacst_net
ip -4 addr show | grep 10.200.1.1
```

> Si el servidor ya aloja otro sistema con esta subred, usar otra (por ejemplo
> `10.200.2.0/24`) y cambiar **en el mismo movimiento** los cuatro lugares que
> dependen de ella: esta red, `listen_addresses`, `pg_hba.conf` y
> `DB_HOST`/`TRUSTED_PROXIES` en `app.env`.

### 2.4 PostgreSQL 18

```bash
sudo apt-get install -y postgresql-common
sudo /usr/share/postgresql-common/pgdg/apt.postgresql.org.sh -y
sudo apt-get update
sudo apt-get install -y postgresql-18 postgresql-client-18
sudo systemctl enable postgresql
```

Que escuche en el gateway de la red, y que solo esa red llegue a esta base con
estos dos roles:

```bash
sudo tee /etc/postgresql/18/main/conf.d/10-sacst.conf > /dev/null <<'EOF'
# Docker: gateway de la red sacst_net (deploy/compose.yml).
listen_addresses = 'localhost,10.200.1.1'
EOF

echo "host    sacst    sacst_app,sacst_owner    10.200.1.0/24    scram-sha-256" \
    | sudo tee -a /etc/postgresql/18/main/pg_hba.conf
```

**Arrancar después de Docker.** La interfaz `10.200.1.1` la crea Docker al
arrancar. Si después de un reinicio PostgreSQL arranca primero, no puede
escuchar en ella: lo registra como advertencia, sigue escuchando solo en
localhost, y la app queda sin base hasta que alguien lo reinicie a mano. Esta
línea fija el orden:

```bash
sudo mkdir -p /etc/systemd/system/postgresql@18-main.service.d
sudo tee /etc/systemd/system/postgresql@18-main.service.d/10-sacst.conf > /dev/null <<'EOF'
[Unit]
# 10.200.1.1 es el gateway de sacst_net y existe recién cuando Docker arrancó.
After=docker.service
Wants=docker.service
EOF
sudo systemctl daemon-reload
sudo systemctl restart postgresql@18-main
ss -ltnp | grep 5432     # 127.0.0.1 y 10.200.1.1. Si aparece 0.0.0.0, parar y revisar.
```

**Base y roles.** Dos contraseñas de 40 caracteres, alfanuméricas a propósito:
atraviesan un literal SQL y un `env_file`, y un `'`, `$` o `#` rompe alguno de
los dos de una forma difícil de diagnosticar. Guardarlas en el gestor de
contraseñas antes de seguir.

```bash
LC_ALL=C tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 40; echo     # una para cada rol
```

Van por stdin: `read -s` no las muestra ni las deja en el historial, y
`printf` es un builtin del shell, así que no aparecen en `ps`.

```bash
cd /opt/sacst/sac-st-web          # el repo del paso 3.1
read -rsp 'Contraseña de sacst_owner: ' OWNER_PASS; echo
read -rsp 'Contraseña de sacst_app: ' APP_PASS; echo
{ printf "\\set owner_password '%s'\n\\set app_password '%s'\n" "$OWNER_PASS" "$APP_PASS"
  cat deploy/postgres/setup.sql; } | sudo -u postgres psql -v ON_ERROR_STOP=1
unset OWNER_PASS APP_PASS
```

Probar desde un contenedor, no desde el host. Desde el host la conexión sale
con la IP de la LAN y el rechazo de `pg_hba` es el correcto:

```bash
sudo docker run --rm -it --network sacst_net postgres:18-alpine \
    psql -h 10.200.1.1 -U sacst_app -d sacst -W -c '\conninfo'
```

### 2.5 Logs: journald con retención

```bash
sudo mkdir -p /etc/systemd/journald.conf.d /var/log/journal
sudo tee /etc/systemd/journald.conf.d/sacst.conf > /dev/null <<'EOF'
[Journal]
Storage=persistent
Compress=yes
MaxRetentionSec=6month
SystemMaxUse=4G
SystemMaxFileSize=256M
SystemKeepFree=2G
EOF
sudo systemctl restart systemd-journald
```

Los logs de **auditoría** y de **seguridad** no van a journald: son archivos en
`SACST_STORAGE_PATH/logs`, con un año de retención propia, y entran en el
backup de esa carpeta.

---

## 3. Instalación

### 3.1 El repo

En el servidor el repo sirve para una sola cosa: tener `deploy/` en la misma
versión que la imagen que corre. No se compila nada. Como es público, se clona
sin credenciales:

```bash
sudo mkdir -p /opt/sacst && sudo chown "$USER":"$USER" /opt/sacst
git clone https://github.com/AlejandroExeAbalosF/sac-st.git /opt/sacst/sac-st-web
cd /opt/sacst/sac-st-web
git checkout v0.1.0               # la misma versión que SACST_VERSION
```

### 3.2 Carpeta de datos

```bash
sudo mkdir -p /srv/sacst/storage
sudo chown 33:33 /srv/sacst/storage          # 33 = www-data en la imagen
sudo chmod 750 /srv/sacst/storage
```

Si hay un disco de datos aparte, usar una carpeta de ese disco y declararla en
`compose.env`. Si la carpeta no es de `33:33`, los contenedores no arrancan y
lo dicen en el log.

### 3.3 Configuración

```bash
sudo install -d -m 700 /etc/sacst
sudo install -m 600 deploy/env/compose.env.example /etc/sacst/compose.env
sudo install -m 600 deploy/env/app.env.example     /etc/sacst/app.env
sudo install -m 600 deploy/env/migrate.env.example /etc/sacst/migrate.env
```

- `compose.env`: `SACST_VERSION`, `SACST_STORAGE_PATH`, `SACST_WEB_BIND` (`<IP_DEL_SERVIDOR>:8080`)
  y `SACST_DC_PROXY_IP`, la IP del proxy del data center: es el único origen al
  que nginx le cree `X-Forwarded-Proto`.
- `app.env`: `APP_URL`, la contraseña de `sacst_app`, `TRUSTED_PROXIES` con la IP del proxy del data center y el correo.
  **`APP_URL` tiene que ser el dominio** (`http://<DOMINIO>`), no la IP: la app
  atiende solo pedidos dirigidos a ese host y responde 400 a cualquier otro,
  para que nadie pueda desviar el enlace de recuperación de contraseña.
- `migrate.env`: la contraseña de `sacst_owner`.

Falta la `APP_KEY`. Se genera con la imagen, sin levantar nada:

```bash
sudo deploy/bin/sacst pull
sudo deploy/bin/sacst run --rm --no-deps --entrypoint php app artisan key:generate --show
sudo nano /etc/sacst/app.env      # pegarla en APP_KEY=
```

> `--no-deps` evita que `run` levante `migrate` antes, que sin `APP_KEY` no
> puede correr. `--entrypoint php` evita el control de permisos de `storage/`,
> que para esto no hace falta.
>
> **La `APP_KEY` no se cambia nunca.** Cifra los secretos del doble factor: con
> otra clave, nadie que lo tenga configurado puede volver a ingresar.

### 3.4 Arranque

```bash
sudo deploy/bin/sacst up -d
sudo deploy/bin/sacst ps          # app, web y queue arriba; migrate en «exited (0)»
```

El orden es automático: `migrate` corre las migraciones y siembra el catálogo
(permisos, series, cajas y etiquetas), termina, y recién entonces arrancan
`app` y `queue`. `web` espera a que `app` esté sano.

### 3.5 Primer administrador

En producción ningún seeder crea usuarios. El primero se da de alta desde la
consola, y los demás desde la pantalla de usuarios:

```bash
sudo deploy/bin/sacst exec app php artisan usuarios:crear-administrador
```

Pide nombre, apellido, usuario, DNI, correo y rol (`administrador` o
`super-admin`). La contraseña la genera el sistema, **se muestra una sola vez**
y el primer ingreso obliga a cambiarla. No entra por ningún archivo ni variable.
El mismo comando sirve de rescate si todos los administradores quedan bloqueados.

Un administrador que olvidó su clave la recupera por correo. Entre
administradores no se restablecen claves desde la pantalla, así que si el
correo no funciona:

```bash
sudo deploy/bin/sacst exec app php artisan usuarios:restablecer-clave <usuario>
```

> La base no deja que el sistema se quede sin un administrador activo: ni la
> pantalla ni un `UPDATE` a mano pueden desactivar o quitarle el rol al último.

### 3.6 Proxy del data center

La configuración para entregarle al equipo del data center está en
`deploy/proxy/sacst.conf.example`, con los marcadores a completar y la fase
HTTPS lista para descomentar. Lo esencial:

- Backend: `http://<IP_DEL_SERVIDOR>:8080`.
- Que reenvíe `X-Forwarded-For`, `X-Forwarded-Proto` y **`Host` con el dominio**
  (`proxy_set_header Host $host`). Sin eso, nginx le pasa a la app la IP del
  backend y la app responde 400.
- `client_max_body_size 33M`: los extractos bancarios llegan en planillas de
  hasta 32 MB.
- `proxy_buffer_size 32k`: la app manda cabeceras grandes (CSP, cookies).
- Que **no** agregue CSP, `X-Frame-Options`, `X-Content-Type-Options` ni HSTS.
  Los emite la app, y duplicados son un hallazgo de ZAP.
- La IP desde la que el proxy le habla a este servidor. Va en dos lugares:
  `TRUSTED_PROXIES` de `app.env` —sin ella, la auditoría registra la IP del
  proxy en lugar de la del usuario— y `SACST_DC_PROXY_IP` de `compose.env`
  —sin ella, nginx no arranca—.

---

## 4. Verificación

Los pedidos van con el `Host` del dominio, como los manda el proxy. Sin él, la
app responde 400: es el control que impide desviar el enlace de recuperación.

```bash
curl -sI -H 'Host: <DOMINIO>' http://<IP_DEL_SERVIDOR>:8080/up        # 200
curl -sI -H 'Host: <DOMINIO>' http://<IP_DEL_SERVIDOR>:8080/login | grep -iE \
    'x-frame-options|x-content-type|referrer-policy|permissions-policy|content-security-policy|server|x-powered-by'
curl -sI http://<IP_DEL_SERVIDOR>:8080/login | head -1                 # 400: Host ajeno
```

Cada cabecera tiene que aparecer **una sola vez**. `Server` sin versión, sin
`X-Powered-By` y, en HTTP, sin `Strict-Transport-Security`. La CSP no lleva
`'unsafe-inline'` en `style-src`: los estilos van por nonce.

**Un cliente que no es el proxy no puede hacerse pasar por HTTPS.** Desde esta
misma máquina —que no es el proxy del data center—:

```bash
curl -sI -H 'Host: <DOMINIO>' -H 'X-Forwarded-Proto: https' \
    http://<IP_DEL_SERVIDOR>:8080/login | grep -i strict-transport
# esperado: nada. Si aparece HSTS, SACST_DC_PROXY_IP está mal.
```

**Los roles de la base hacen lo que prometen.** Esta prueba es la que justifica
tenerlos separados. Conectado como la app, las dos cosas tienen que fallar:

```bash
sudo docker run --rm -it --network sacst_net postgres:18-alpine \
    psql -h 10.200.1.1 -U sacst_app -d sacst -W \
    -c 'ALTER TABLE journal_lines DISABLE TRIGGER USER' \
    -c 'TRUNCATE journal_lines'
# esperado, las dos veces: ERROR:  must be owner of table journal_lines
#                          ERROR:  permission denied for table journal_lines
```

Las dos únicas funciones que pueden borrar un adjunto o un movimiento bancario
corren como dueñas de la tabla. Tienen que ser de `sacst_owner`: si fueran de
otro rol, el borrado de la reversión de un extracto fallaría.

```bash
sudo docker run --rm -it --network sacst_net postgres:18-alpine \
    psql -h 10.200.1.1 -U sacst_app -d sacst -W -c \
    "SELECT proname, pg_get_userbyid(proowner) FROM pg_proc
     WHERE proname IN ('forget_statement_attachment', 'discard_statement_transaction')"
# esperado: las dos de sacst_owner
```

**Reinicio.** Hacerlo una vez antes de entregar: `sudo reboot` y, al volver,
sin tocar nada, `/up` tiene que responder 200 y `ss -ltnp | grep 5432` tiene
que mostrar `10.200.1.1`. Si algo queda abajo, se arregla ahora y no durante el
primer corte de luz.

---

## 5. Publicar una versión

Desde la máquina de desarrollo, con `main` en verde:

```bash
git tag v0.2.0
git push origin v0.2.0
```

`release.yml` corre los tests completos y, si pasan, publica
`ghcr.io/alejandroexeabalosf/sac-st:0.2.0` y `sac-st-web:0.2.0`. El avance se
ve en la pestaña **Actions** del repo.

> **La primera vez**, los dos paquetes nacen privados. En GitHub: perfil →
> *Packages* → `sac-st` → *Package settings* → *Change visibility* → *Public*.
> Lo mismo con `sac-st-web`. La imagen no tiene nada que el repo no muestre ya.
> Si se prefiriera mantenerlos privados, el servidor necesita un `docker login
> ghcr.io` con un token de solo lectura de paquetes.

## 6. Actualizar el servidor

```bash
cd /opt/sacst/sac-st-web
git fetch --tags && git checkout v0.2.0
sudo nano /etc/sacst/compose.env             # SACST_VERSION=0.2.0
sudo deploy/bin/sacst pull
sudo deploy/bin/sacst up -d                  # migrate corre solo antes que app
sudo deploy/bin/sacst ps
```

**Volver atrás** es lo mismo con la versión anterior, **salvo que la versión
nueva haya traído migraciones**. En ese caso el esquema ya avanzó, y el código
viejo puede no entenderlo. Antes de volver atrás, fijarse si hubo migraciones
(`git diff --stat v0.1.0 v0.2.0 -- database/migrations`). Si hubo, restaurar
el backup previo es el único camino limpio. Por eso conviene un `pg_dump` antes
de cada actualización con migraciones.

## 7. Operación

```bash
sudo deploy/bin/sacst ps
sudo deploy/bin/sacst exec app php artisan about

journalctl CONTAINER_NAME=sacst-app-1 -f                # app en vivo
journalctl CONTAINER_NAME=sacst-web-1 --since '1 hour ago'
journalctl CONTAINER_NAME=sacst-migrate-1 -n 100        # la última migración
TZ=America/Argentina/Salta journalctl CONTAINER_NAME=sacst-app-1 -n 50   # en hora local

sudo ls /srv/sacst/storage/logs/audit                   # auditoría de accesos

sudo deploy/bin/sacst exec app php artisan down         # modo mantenimiento
sudo deploy/bin/sacst exec app php artisan up
```

> Los comandos destructivos (`migrate:fresh`, `db:wipe`) están prohibidos en
> producción desde `AppServiceProvider`, y además `sacst_app` no tiene permisos
> para ejecutarlos.

### Correo

Con `MAIL_MAILER=failover`, el sistema elige solo:

- `MAIL_HOST` vacío: Gmail directo. Usa `GMAIL_USERNAME` y
  `GMAIL_APP_PASSWORD`, una contraseña de aplicación, que requiere la
  verificación en dos pasos de la cuenta.
- `MAIL_HOST` con valor: el SMTP del organismo, con Gmail de respaldo si falla.

Pasar al SMTP propio es completar `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME` y
`MAIL_PASSWORD` en `app.env` y hacer `sudo deploy/bin/sacst up -d`.

## 8. Pasaje a HTTPS

Cuando el data center habilita el certificado en su proxy:

1. En `app.env`: `APP_URL=https://...` y `SESSION_SECURE_COOKIE=true`.
2. Confirmar que el proxy manda `X-Forwarded-Proto: https`:
   `journalctl CONTAINER_NAME=sacst-web-1 -n 20 | grep xfp=` tiene que decir `xfp="https"`.
   nginx solo le cree ese dato a `SACST_DC_PROXY_IP`: la misma línea muestra
   `xfp_app`, lo que efectivamente le llega a la app. Si `xfp="https"` y
   `xfp_app="http"`, esa IP no es la del proxy.
3. `sudo deploy/bin/sacst up -d`.
4. `curl -sI https://<DOMINIO>/login`: ahora sí aparece `Strict-Transport-Security`,
   y la CSP incluye `upgrade-insecure-requests`. Los dos se activan solos cuando
   el pedido llega por HTTPS, y no hace falta tocar nada más.

Si después del cambio el sitio se ve sin estilos, casi seguro el proxy no está
mandando `X-Forwarded-Proto`, o su IP no está en `TRUSTED_PROXIES`: Laravel arma
URLs `http://` dentro de una página `https://` y el navegador las bloquea.

## 9. Problemas conocidos

| Síntoma | Causa | Qué hacer |
|---|---|---|
| `network sacst_net declared as external, but could not be found` | La red no existe. | Crearla (2.3) y reiniciar PostgreSQL, que perdió la interfaz. |
| `no pg_hba.conf entry for host "10.200.1.x"` | La subred de la red no coincide con la del `pg_hba.conf`. | `sudo docker network inspect sacst_net` y alinear los cuatro valores de 2.3. |
| `Connection refused` a `10.200.1.1` después de un reinicio | PostgreSQL arrancó antes que Docker. | Revisar el drop-in de 2.4. `sudo systemctl restart postgresql@18-main`. |
| Los contenedores se reinician con «storage/ no es escribible» | La carpeta de datos no es de `33:33`. | `sudo chown 33:33 <SACST_STORAGE_PATH>`. |
| `permission denied for table ...` en una migración | `migrate.env` no existe o no tiene las credenciales de `sacst_owner`. | Completarlo (3.3). |
| `must be owner of table ...` en la app | Algo de la app intenta alterar el esquema en runtime. | Es un bug, no un problema de permisos: eso solo puede pasar en una migración. |
| Visor de comprobantes en blanco | Una ruta de documento no está en `same_origin_frame_routes`, o alguien agregó `X-Frame-Options` en nginx o en el proxy. | Ver `same_origin_frame_routes` en `config/security.php` y el componente `DocumentFrame`, que avisa en desarrollo. |
| `400 Bad Request` en todas las pantallas | El `Host` que llega no es el de `APP_URL`: el proxy no reenvía `Host $host`, o `APP_URL` tiene la IP. | Corregir el proxy (3.6) o `APP_URL` (3.3). |
| nginx no arranca: `invalid network "${SACST_DC_PROXY_IP}"` | Falta `SACST_DC_PROXY_IP` en `compose.env`. | Completarla (3.3). |
| Pantallas sin estilos, con errores de CSP en la consola | Un `<style>` sin nonce o una librería que cambió su CSS inyectado. | `composer ci:check` lo detecta: el test de hashes compara con `node_modules`. |
