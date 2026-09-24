-- Base y roles de SAC-ST en el PostgreSQL del host. Se corre una sola vez,
-- como postgres. Las contraseñas entran por stdin y no están en este archivo,
-- que es público (deploy/README.md, paso de la base):
--
--   { printf "\\set owner_password '%s'\n\\set app_password '%s'\n" "$OWNER_PASS" "$APP_PASS"
--     cat deploy/postgres/setup.sql; } | sudo -u postgres psql -v ON_ERROR_STOP=1
--
-- ── Por qué dos roles ─────────────────────────────────────────────────────
--
-- Los invariantes del sistema viven en la base: triggers append-only sobre
-- journal_lines, financial_events, receipts y funding_allocations, entre
-- otros. Pero el dueño de una tabla puede apagarle los triggers
-- (ALTER TABLE ... DISABLE TRIGGER) o vaciarla con TRUNCATE, que no dispara
-- los triggers de fila. Los seeders de demo lo hacen, a propósito.
--
-- Si la aplicación entrara con el rol dueño, un seeder corrido por error, un
-- bug o un `tinker` podrían hacer lo mismo en producción. Por eso:
--
--   sacst_owner  dueño del esquema. Solo lo usa el servicio `migrate`.
--   sacst_app    la aplicación. Lee y escribe filas; no altera tablas, no
--                apaga triggers, no hace TRUNCATE.
--
-- No tiene relación con los roles del sistema (administrador, contador...):
-- esos son de la aplicación y viven en sus tablas. Estos son los usuarios con
-- los que Laravel se conecta a PostgreSQL.

CREATE ROLE sacst_owner LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD :'owner_password';
CREATE ROLE sacst_app LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD :'app_password';

CREATE DATABASE sacst OWNER sacst_owner ENCODING 'UTF8';

-- Nadie más se conecta.
REVOKE ALL ON DATABASE sacst FROM PUBLIC;
GRANT CONNECT ON DATABASE sacst TO sacst_app;

\connect sacst

-- Desde PostgreSQL 15 el esquema public es del dueño de la base y PUBLIC ya
-- no puede crear objetos en él. Se deja explícito igual.
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO sacst_app;

-- Las extensiones que piden las migraciones. Se crean acá, como
-- superusuario, y el CREATE EXTENSION IF NOT EXISTS de la migración pasa de
-- largo: el dueño del esquema no necesita ningún privilegio extra.
CREATE EXTENSION IF NOT EXISTS unaccent;
CREATE EXTENSION IF NOT EXISTS pg_trgm;

-- Todo lo que cree sacst_owner de acá en adelante —es decir, cada tabla de
-- cada migración, las de hoy y las de las cajas que faltan— nace con los
-- permisos de la aplicación. Una migración nueva no tiene que acordarse de
-- nada.
ALTER DEFAULT PRIVILEGES FOR ROLE sacst_owner IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO sacst_app;
ALTER DEFAULT PRIVILEGES FOR ROLE sacst_owner IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO sacst_app;

-- Verificación: dos roles sin superusuario y los privilegios por defecto.
SELECT rolname, rolsuper, rolcreatedb, rolcreaterole
FROM pg_roles
WHERE rolname IN ('sacst_owner', 'sacst_app');

\ddp
