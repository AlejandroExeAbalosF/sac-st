# Zonas horarias

Los **instantes** se generan en UTC en Laravel (`APP_TIMEZONE=UTC`), se
consultan desde conexiones PostgreSQL configuradas en UTC
(`DB_TIMEZONE=UTC`) y se guardan en columnas `timestamptz`. PostgreSQL
normaliza esos valores internamente a UTC. Las respuestas que representan
un instante deben enviar ISO 8601 con zona, preferentemente `Z`.

La **fecha operativa** de Caja, Haberes y los comprobantes es un día del
calendario de Salta. Se guarda en columnas `date` y no se convierte de zona.
`BusinessDate::today()` calcula ese día desde el instante actual; se usa
para límites de fecha, valores por defecto y reglas de cierre. Las horas
que constan en un ticket son evidencia del documento y se guardan como
`time`, sin reinterpretarlas como instantes.

La presentación usa `APP_DISPLAY_TIMEZONE=America/Argentina/Salta`. En el
frontend, `resources/js/lib/format.ts` convierte instantes en `dateTime()`
y deja las fechas `YYYY-MM-DD` como días de calendario en `date()`. Los PDF
y mensajes generados en PHP convierten a esa zona en el punto de salida.
El log de auditoría HTTP también muestra esa zona con offset explícito.

## Datos anteriores

La migración `2026_09_22_120000_convert_legacy_timestamps_to_timestamptz`
convierte los 17 campos técnicos que aún eran `timestamp without time zone`.
Sus valores anteriores se interpretan como hora civil de Salta con
`AT TIME ZONE 'America/Argentina/Salta'`; así `12:00` pasa a representar
`15:00Z` sin alterar el momento del hecho. La regla se verificó con un
traslado existente cuya hora antigua coincidía con el evento financiero
relacionado en hora de Salta.

La migración modifica tipos de columnas y toma bloqueos de tabla. En un
despliegue con usuarios, ejecutar con ventana de mantenimiento y respaldo
de la base. Reiniciar los procesos PHP después de cambiar `.env` o la
configuración cacheada para que todas las conexiones nuevas usen UTC.
