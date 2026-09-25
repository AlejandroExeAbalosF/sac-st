# Auditoría de acceso HTTP

Cada solicitud que llega a Laravel deja una línea JSON en
`storage/logs/audit/audit-AAAA-MM-DD.log` y, por defecto, la misma línea en
la consola de `composer dev` en el entorno local. Se escribe al finalizar la
respuesta.
El canal `audit` es independiente de `laravel.log` y de `security.log`.

Esta capa complementa las fuentes de auditoría existentes:

| Fuente              | Qué responde                                                                                                                                                                       |
| ------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `audit_events`      | Qué operación de negocio ocurrió, sobre qué entidad y con qué cambios. Se mira en **Configuración › Auditoría** y en el historial de cada expediente, haber y cuota |
| `user_login_events` | Qué pasó con las credenciales, el segundo factor y las sesiones. Se mira en **Configuración › Accesos y sesiones**                                                                 |
| Archivo `audit`     | Qué solicitud HTTP llegó, quién la hizo, resultado y duración                                                                                                                      |

## Auditoría de operaciones

`/configuracion/auditoria` lee `audit_events` de punta a punta: qué se hizo,
sobre qué, quién y cuándo, sin tener que saber de antemano en qué expediente
mirar. Es de solo lectura; la tabla es append-only y la base lo impone.

**Catálogo.** Qué significa cada código lo dice `AuditCatalog`
(`app/Modules/Shared/Audit`), el mismo para esta pantalla y para los
historiales. Cada módulo registra el suyo en su `*AuditCatalog`:

- el rótulo, la **categoría** (un concepto del área) y la **severidad**:
  `critical` para lo que deshace, fuerza o reabre algo;
- qué claves de `metadata` se muestran. El motivo sale siempre; el resto de
  la metadata queda en la tabla y no se vuelca;
- quién describe y enlaza cada tipo de sujeto, y quién traduce cada id que
  apunta a otra fila.

Un código nuevo **se registra en el catálogo en el mismo cambio** que lo
emite. Fuera de producción `RecordAuditEvent` rechaza un código sin
registrar, y `tests/Unit/Shared/AuditCatalogTest.php` recorre el código
fuente en las dos direcciones. En producción el evento se graba igual y el
error se reporta; la pantalla lo muestra con su código.

**Enlaces.** El sujeto se enlaza solo si el registro todavía existe y quien
mira puede abrir su pantalla. Si no, se describe como «Cuota #123».

**Filtros.** Fechas como días de Salta (rango semiabierto sobre el instante),
usuario o «el sistema» (`user_id` nulo), acción, entidad y «solo críticas».
Un filtro inválido vuelve a la pantalla sin filtros y con el error.

**Excel.** `auditoria.operaciones.exportar`, un permiso aparte de
`auditoria.operaciones.ver` porque saca del sistema datos personales. Baja
lo que se está mirando, una fila por cambio, en el mismo orden que la
pantalla. Se recorre por cursor `(occurred_at, id)` sobre una foto del id
más alto al empezar: lo que se registre durante la descarga no entra.

**Habilitación en una instalación existente.** El seeder crea
`auditoria.operaciones.ver` y `auditoria.operaciones.exportar`, pero no
modifica un rol que ya tenga permisos: esa matriz pudo haberse ajustado
desde Configuración y no se pisa al desplegar. Después de sembrar los
permisos, hay que otorgar ambos al contador desde **Configuración › Roles**.
El administrador ya puede entrar por su acceso global.

**Cruce con este archivo.** El **n.º de evento** que muestra la pantalla es
el `audit_id` que aparece en `context.changes` de la línea HTTP que lo
provocó. El cruce se hace por ese número, no por `X-Request-Id`:

```bash
jq 'select(.context.changes[]?.audit_id == 1234)' storage/logs/audit/audit-*.log
```

## Sesiones y credenciales

Lo que decide quién puede estar adentro, y lo que deja en
`user_login_events`:

- **No hay «recordarme».** La sesión dura lo que la actividad. Un ingreso
  por cookie de recordatorio —una que alguien haya conservado de antes— se
  rechaza y queda como `session_revoked`.
- **Cerrar el navegador cierra la sesión** (`expire_on_close`, por defecto en
  `true`). No es una garantía: Chrome y Edge con «continuar donde lo dejaste»
  restauran las cookies de sesión. Lo que no depende del navegador es la
  inactividad, `SESSION_LIFETIME`, que vence en el servidor.
- **Usuario activo en cada pedido.** `EnsureAccountIsUsable` saca en el
  pedido siguiente a quien se dio de baja, entre por donde haya entrado
  (contraseña, passkey o una sesión que ya estaba abierta). La passkey de un
  inactivo, además, ni siquiera entra: queda como `login_failed` con
  `account_disabled`.
- **Cerrar sesiones rota el `remember_token`.** Lo hacen la baja, el
  restablecimiento desde Usuarios, el cambio de clave propio (conserva la
  sesión actual) y la recuperación por correo.
- **Segundo factor.** Un código rechazado queda como `two_factor_failed`.
- **Recuperación por correo.** Responde lo mismo exista o no la casilla, y
  tiene tope: 5 pedidos por minuto por IP y 3 cada 15 minutos por correo.
- **Cambiar el propio correo** pide la contraseña actual: es a donde llega
  la recuperación.

**Entre administradores** no se restablecen claves ni se cambian correos o
roles: con la temporal en la mano, uno entraría como el otro y sus actos
quedarían a nombre ajeno. Sí se corrige la ficha y se desactiva. Nadie cambia
su propio rol, al último administrador activo no se le quita, y a un
`super-admin` solo lo gestiona otro super-admin —también para cerrarle una
sesión desde esta pantalla—, que es también el único que asigna ese rol.

**Siempre queda un administrador activo**, y lo impone la base: un trigger
diferido (`ensure_an_active_administrator`) sobre `users.is_active` y
`model_has_roles`, que cuenta con un bloqueo tomado. Sin el bloqueo, dos
administradores que se desactivaban uno al otro a la vez pasaban los dos; se
verificó con dos conexiones reales, con y sin él. Las reglas viven en `UserManagementGuard`; la pantalla
deshabilita con el mismo motivo que el servidor devolvería. Las capacidades
`dev.*` no se otorgan a ningún rol: `UpdateRolePermissions` lo rechaza y un
trigger sobre `role_has_permissions` lo impide en la base.

El administrador que pierde su clave la recupera por correo. Si el correo no
funciona, desde el servidor:

```bash
php artisan usuarios:restablecer-clave <usuario>
```

Muestra la temporal una sola vez, obliga a cambiarla al entrar y deja el
restablecimiento firmado por «sistema».

## Formato

Campos principales: `channel`, `ts` (zona horaria de visualización), `event`,
`id` (UUID), `method`, `uri`, `route`, `status`, `duration_ms`, `ip`, `user`,
`user_agent` y `outcome`. `event` es `http_request`, `auth` o `error`.
`outcome` distingue `ok`, `auth_failed`, `unauthenticated`, `denied`,
`not_found`, `csrf`, `validation_error`, `rate_limited`, `error` y `other`.
Las solicitudes que superan `AUDIT_LOG_SLOW_MS` incluyen `slow: true` en la
misma línea.

El ID vuelve en `X-Request-Id`. Si durante la solicitud se crea un
`audit_event`, aparece en `context.changes` su ID, acción, entidad y nombres
de campos. Los valores anteriores y nuevos siguen exclusivamente en la tabla.
Los intentos de ingreso fallidos usan el evento de credenciales para marcar
`auth_failed`, incluso cuando el formulario responde con una redirección.
`context.login_event_id` enlaza la línea con `user_login_events`.

## Privacidad y alcance

No se registran cuerpos de solicitud o respuesta, cookies, contraseñas,
parámetros de consulta ni valores de los cambios. `uri` usa la plantilla de
la ruta, por ejemplo `/reset-password/{token}`. Sólo se omite `/up`; los
archivos estáticos servidos directamente por el servidor web no llegan a PHP.
Los datos de usuario, IP y agente son personales: restringir el acceso al
directorio de logs y respaldarlo según la política del organismo.

La rotación diaria conserva 365 archivos por defecto. Se configura con:

| Variable                   | Valor inicial                              | Uso                                                                    |
| -------------------------- | ------------------------------------------ | ---------------------------------------------------------------------- |
| `AUDIT_LOG_ENABLED`        | `true`                                     | Activar el registro de acceso                                          |
| `AUDIT_LOG_CONSOLE`        | `true` en local, `false` en otros entornos | Reflejar cada línea en `stderr` (`composer dev` o logs del contenedor) |
| `AUDIT_LOG_RETENTION_DAYS` | `365`                                      | Días de archivos diarios en disco                                      |
| `AUDIT_LOG_SLOW_MS`        | `1000`                                     | Umbral de lentitud; `0` lo desactiva                                   |

La retención local no sustituye los respaldos. Si hay varios servidores,
hay que recopilar los archivos de cada instancia para tener el historial
completo. Las solicitudes que no alcanzan PHP tampoco aparecen aquí.
No hace falta declarar `AUDIT_LOG_*` en `.env` para usar estos valores; en
producción se pueden definir explícitamente para ajustar la retención o el
destino de los logs.

Para seguir sólo el archivo desde PowerShell:

```powershell
Get-Content storage/logs/audit/audit-$(Get-Date -Format yyyy-MM-dd).log -Wait
```

Si se cambia una variable de logging en `.env`, reiniciar `composer dev`;
si se usa configuración cacheada, regenerar la caché de configuración.

## Consultas

```bash
jq 'select(.user.username == "operador")' storage/logs/audit/audit-*.log
jq 'select(.outcome == "denied" or .outcome == "auth_failed")' storage/logs/audit/audit-*.log
jq 'select(.slow == true)' storage/logs/audit/audit-*.log
jq 'select(.id == "UUID_DE_LA_RESPUESTA")' storage/logs/audit/audit-*.log
```
