# Auditoría de acceso HTTP

Cada solicitud que llega a Laravel deja una línea JSON en
`storage/logs/audit/audit-AAAA-MM-DD.log` y, por defecto, la misma línea en
la consola de `composer dev` en el entorno local. Se escribe al finalizar la
respuesta.
El canal `audit` es independiente de `laravel.log` y de `security.log`.

Esta capa complementa las fuentes de auditoría existentes:

| Fuente | Qué responde |
| --- | --- |
| `audit_events` | Qué operación de negocio ocurrió, sobre qué entidad y con qué cambios |
| `user_login_events` | Qué pasó con las credenciales, el segundo factor y las sesiones |
| Archivo `audit` | Qué solicitud HTTP llegó, quién la hizo, resultado y duración |

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

| Variable | Valor inicial | Uso |
| --- | --- | --- |
| `AUDIT_LOG_ENABLED` | `true` | Activar el registro de acceso |
| `AUDIT_LOG_CONSOLE` | `true` en local, `false` en otros entornos | Reflejar cada línea en `stderr` (`composer dev` o logs del contenedor) |
| `AUDIT_LOG_RETENTION_DAYS` | `365` | Días de archivos diarios en disco |
| `AUDIT_LOG_SLOW_MS` | `1000` | Umbral de lentitud; `0` lo desactiva |

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
