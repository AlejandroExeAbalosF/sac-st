<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidación cronológica del proceso. Cada bloque conserva la migración
 * original para mantener visibles sus reglas y permitir el rollback inverso.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->migrations() as $migration) {
            $this->run($migration, 'up');
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->migrations()) as $migration) {
            $this->run($migration, 'down');
        }
    }

    private function run(Migration $migration, string $method): void
    {
        $callback = [$migration, $method];

        if (! is_callable($callback)) {
            throw new LogicException("La migración consolidada no tiene {$method}().");
        }

        $callback();
    }

    /** @return list<Migration> */
    private function migrations(): array
    {
        return [
            /**
             * Índices para mirar la auditoría de punta a punta.
             *
             * Los que había responden «qué le pasó a este expediente» —por sujeto— y
             * «qué hizo este usuario». La auditoría de operaciones pregunta además
             * «qué pasó en estas fechas», «quién anuló cobros» y «qué se hizo con las
             * cuotas», siempre del más reciente al más antiguo. El `id` al final de
             * cada uno es el desempate del orden, y es lo que deja recorrer el Excel
             * por cursor sin volver a ordenar.
             *
             * El índice por sujeto concreto se conserva: lo usan los historiales.
             */
            // Migración original: 2026_09_22_130000_add_timeline_indexes_to_audit_events_table.php
            new class extends Migration
            {
                public function up(): void
                {
                    Schema::table('audit_events', function (Blueprint $table): void {
                        $table->index(['occurred_at', 'id']);
                        $table->index(['action', 'occurred_at', 'id']);
                        $table->index(['subject_type', 'occurred_at', 'id']);
                    });
                }

                public function down(): void
                {
                    Schema::table('audit_events', function (Blueprint $table): void {
                        $table->dropIndex(['occurred_at', 'id']);
                        $table->dropIndex(['action', 'occurred_at', 'id']);
                        $table->dropIndex(['subject_type', 'occurred_at', 'id']);
                    });
                }
            },

            /**
             * Declarar un saldo sin recontar deja de exigir un motivo.
             *
             * El motivo se pidió suponiendo que no recontar era la excepción. La planilla
             * de junio de 2026 muestra lo contrario: **los veinte días** arrastran un
             * saldo sin recontar, y en cuatro de ellos es el cajón entero.
             *
             * Un campo obligatorio que se completa igual todas las tardes deja de aportar
             * y empieza a estorbar: se llena con cualquier cosa y nadie lo lee. El dato
             * que importa ya está en la fila —cuánto se declaró sin contar— y ese no se
             * va a ningún lado.
             */
            // Migración original: 2026_09_23_000000_drop_the_reason_for_not_recounting.php
            new class extends Migration
            {
                public function up(): void
                {
                    DB::statement('ALTER TABLE cash_counts DROP CONSTRAINT IF EXISTS cash_counts_uncounted_reason_check');

                    Schema::table('cash_counts', function (Blueprint $table): void {
                        $table->dropColumn('uncounted_reason');
                    });
                }

                public function down(): void
                {
                    Schema::table('cash_counts', function (Blueprint $table): void {
                        $table->string('uncounted_reason', 300)->nullable();
                    });

                    /*
                     * Los motivos de los arqueos que ya existían no vuelven: la columna
                     * se recrea vacía. Por eso el `CHECK` se restablece sin poder exigir
                     * lo que ya no está, y solo alcanza a las filas nuevas.
                     */
                    DB::statement(<<<'SQL'
            ALTER TABLE cash_counts ADD CONSTRAINT cash_counts_uncounted_reason_check
            CHECK (uncounted_amount = 0 OR uncounted_reason IS NOT NULL) NOT VALID
        SQL);
                }
            },

            /**
             * Abrir el fajo del día anterior pasa a ser un hecho registrado.
             *
             * Hasta acá el recuento vivía solo en el navegador: sumaba sus billetes a
             * los del día, ponía el saldo anterior en cero y desaparecía. El arqueo que
             * quedaba guardado era indistinguible de uno donde el cajero contó todo.
             *
             * Eso se lleva puestas tres cosas:
             *
             * - **Que el fajo se abrió.** Si está intacto no queda rastro de que
             *   alguien verificó dos millones de plata vieja, que es justamente el
             *   control que después hay que poder mostrar.
             * - **De dónde sale el faltante.** Si el fajo está corto, la diferencia cae
             *   en el arqueo del día mezclada con lo de hoy, y seis meses después nadie
             *   puede decir si faltó de la recaudación o del fondo histórico.
             * - **La composición del fajo.** Las líneas se fundían con las del día, así
             *   que se perdía con qué billetes estaba armado — que es lo que se guarda
             *   al abrir los libros para poder compararlo.
             *
             * ─── Qué **no** cambia ────────────────────────────────────────────────────
             *
             * La aritmética del arqueo. El efectivo del fajo está físicamente en el
             * cajón, así que sus billetes entran en `counted_amount` y el trigger que
             * exige que el total sea la suma de las líneas sigue valiendo sobre todas.
             * Las columnas nuevas son **atribución**, no un segundo cálculo: dicen qué
             * parte de lo contado salió del fajo y qué decía el libro que había ahí.
             */
            // Migración original: 2026_09_23_010000_record_when_the_carry_is_recounted.php
            new class extends Migration
            {
                public function up(): void
                {
                    Schema::table('cash_counts', function (Blueprint $table): void {
                        /** Por qué se abrió el fajo. Recontar es excepcional: el motivo informa. */
                        $table->string('carry_recount_reason', 300)->nullable();

                        /** Lo que el libro decía que había en el fajo al momento de abrirlo. */
                        $table->decimal('carry_expected_amount', 19, 2)->nullable();

                        /** Lo que se encontró. La resta contra el anterior es el faltante del fajo. */
                        $table->decimal('carry_counted_amount', 19, 2)->nullable();
                    });

                    /*
                     * Las tres van juntas o no va ninguna. Un motivo sin importes no
                     * dice nada, y un importe sin motivo es el campo que ya sacamos una
                     * vez por no aportar.
                     */
                    DB::statement('ALTER TABLE cash_counts ADD CONSTRAINT cash_counts_carry_recount_check
            CHECK (num_nonnulls(carry_recount_reason, carry_expected_amount, carry_counted_amount) IN (0, 3))');

                    DB::statement('ALTER TABLE cash_counts ADD CONSTRAINT cash_counts_carry_amounts_check
            CHECK (
                (carry_expected_amount IS NULL OR carry_expected_amount >= 0)
                AND (carry_counted_amount IS NULL OR carry_counted_amount >= 0)
            )');

                    /*
                     * Recontar el fajo y declararlo sin recontar al mismo tiempo es una
                     * contradicción: o se abrió o no se abrió.
                     */
                    DB::statement('ALTER TABLE cash_counts ADD CONSTRAINT cash_counts_carry_excludes_uncounted_check
            CHECK (carry_counted_amount IS NULL OR uncounted_amount = 0)');

                    Schema::table('cash_count_lines', function (Blueprint $table): void {
                        /*
                         * De qué conteo es la línea. `day` es el movimiento de la
                         * jornada; `carry`, el fajo que venía de días anteriores.
                         * Separadas, cada una conserva su composición.
                         */
                        $table->string('scope', 10)->default('day');

                        $table->dropUnique(['cash_count_id', 'denomination']);
                        $table->unique(['cash_count_id', 'scope', 'denomination']);
                    });

                    DB::statement("ALTER TABLE cash_count_lines ADD CONSTRAINT cash_count_lines_scope_check
            CHECK (scope IN ('day', 'carry'))");

                    $this->elFajoContadoEsLaSumaDeSusLineas();
                }

                public function down(): void
                {
                    DB::statement('DROP TRIGGER IF EXISTS cash_count_lines_carry_total ON cash_count_lines');
                    DB::statement('DROP TRIGGER IF EXISTS cash_counts_carry_total ON cash_counts');
                    DB::statement('DROP FUNCTION IF EXISTS cash_count_carry_matches_lines()');

                    DB::statement('ALTER TABLE cash_count_lines DROP CONSTRAINT IF EXISTS cash_count_lines_scope_check');

                    Schema::table('cash_count_lines', function (Blueprint $table): void {
                        $table->dropUnique(['cash_count_id', 'scope', 'denomination']);
                        $table->dropColumn('scope');
                        $table->unique(['cash_count_id', 'denomination']);
                    });

                    foreach ([
                        'cash_counts_carry_recount_check',
                        'cash_counts_carry_amounts_check',
                        'cash_counts_carry_excludes_uncounted_check',
                    ] as $restriccion) {
                        DB::statement("ALTER TABLE cash_counts DROP CONSTRAINT IF EXISTS {$restriccion}");
                    }

                    Schema::table('cash_counts', function (Blueprint $table): void {
                        $table->dropColumn([
                            'carry_recount_reason',
                            'carry_expected_amount',
                            'carry_counted_amount',
                        ]);
                    });
                }

                /**
                 * `carry_counted_amount` es la suma de las líneas del fajo, siempre.
                 *
                 * Misma regla que el total del arqueo y por el mismo motivo: un importe
                 * tipeado puede contradecir a sus propios sumandos. Va diferido porque
                 * la cabecera se escribe antes que las líneas y en el medio no cuadra.
                 *
                 * Un fajo vacío es un resultado legítimo —el día que no quede nada— y
                 * cero contra cero pasa. Lo que no pasa es tener líneas del fajo sin
                 * haber declarado el recuento, ni al revés.
                 */
                private function elFajoContadoEsLaSumaDeSusLineas(): void
                {
                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION cash_count_carry_matches_lines() RETURNS trigger AS $$
            DECLARE
                arqueo_id BIGINT;
                declarado NUMERIC(19,2);
                sumado NUMERIC(19,2);
            BEGIN
                IF TG_TABLE_NAME = 'cash_counts' THEN
                    arqueo_id := NEW.id;
                ELSE
                    arqueo_id := COALESCE(NEW.cash_count_id, OLD.cash_count_id);
                END IF;

                SELECT carry_counted_amount INTO declarado
                  FROM cash_counts WHERE id = arqueo_id;

                -- El arqueo pudo borrarse en esta misma transaccion.
                IF NOT FOUND THEN
                    RETURN NULL;
                END IF;

                SELECT COALESCE(SUM(subtotal), 0) INTO sumado
                  FROM cash_count_lines
                 WHERE cash_count_id = arqueo_id AND scope = 'carry';

                IF declarado IS NULL THEN
                    IF sumado <> 0 THEN
                        RAISE EXCEPTION
                            'El arqueo % tiene lineas del fajo por % sin declarar el recuento.',
                            arqueo_id, sumado
                            USING ERRCODE = 'check_violation';
                    END IF;

                    RETURN NULL;
                END IF;

                IF declarado <> sumado THEN
                    RAISE EXCEPTION
                        'El arqueo % declara % recontados del fajo y sus lineas suman %.',
                        arqueo_id, declarado, sumado
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

                    DB::statement('CREATE CONSTRAINT TRIGGER cash_count_lines_carry_total
            AFTER INSERT OR UPDATE OR DELETE ON cash_count_lines
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION cash_count_carry_matches_lines()');

                    DB::statement('CREATE CONSTRAINT TRIGGER cash_counts_carry_total
            AFTER INSERT OR UPDATE ON cash_counts
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION cash_count_carry_matches_lines()');
                }
            },

            /** La explicación corresponde a la recaudación del día, no al fajo recontado. */
            // Migración original: 2026_09_24_000000_require_explanation_for_day_difference.php
            new class extends Migration
            {
                public function up(): void
                {
                    DB::statement('ALTER TABLE cash_counts DROP CONSTRAINT cash_counts_explanation_check');

                    DB::statement('ALTER TABLE cash_counts ADD CONSTRAINT cash_counts_explanation_check
            CHECK (
                counted_amount - COALESCE(carry_counted_amount, 0)
                    = expected_amount - uncounted_amount - COALESCE(carry_expected_amount, 0)
                OR explanation IS NOT NULL
            )');
                }

                public function down(): void
                {
                    DB::statement('ALTER TABLE cash_counts DROP CONSTRAINT cash_counts_explanation_check');

                    DB::statement('ALTER TABLE cash_counts ADD CONSTRAINT cash_counts_explanation_check
            CHECK (
                counted_amount + uncounted_amount = expected_amount
                OR explanation IS NOT NULL
            )');
                }
            },

            /** Un recuento total que cuadra puede tener otro reparto interno de billetes. */
            // Migración original: 2026_09_24_010000_allow_compensated_recount_without_explanation.php
            new class extends Migration
            {
                public function up(): void
                {
                    DB::statement('ALTER TABLE cash_counts DROP CONSTRAINT cash_counts_explanation_check');

                    DB::statement('ALTER TABLE cash_counts ADD CONSTRAINT cash_counts_explanation_check
            CHECK (
                counted_amount - COALESCE(carry_counted_amount, 0)
                    = expected_amount - uncounted_amount - COALESCE(carry_expected_amount, 0)
                OR (carry_counted_amount IS NOT NULL AND counted_amount = expected_amount)
                OR explanation IS NOT NULL
            )');
                }

                public function down(): void
                {
                    DB::statement('ALTER TABLE cash_counts DROP CONSTRAINT cash_counts_explanation_check');

                    DB::statement('ALTER TABLE cash_counts ADD CONSTRAINT cash_counts_explanation_check
            CHECK (
                counted_amount - COALESCE(carry_counted_amount, 0)
                    = expected_amount - uncounted_amount - COALESCE(carry_expected_amount, 0)
                OR explanation IS NOT NULL
            )');
                }
            },

            /**
             * Las capacidades `dev.*` solo se vinculan al rol `super-admin`.
             *
             * El comodín del administrador las deja afuera a propósito, pero la
             * pantalla de roles dejaba otorgárselas a cualquier otro: un contador con
             * `dev.forzar-cbu` da por verificado un CBU que no pasa los controles, y la
             * Orden de Pago sale a esa cuenta. `UpdateRolePermissions` lo rechaza con
             * un mensaje; esto lo impide aunque alguien escriba en la tabla por otro
             * camino.
             */
            // Migración original: 2026_09_24_020000_guard_developer_permissions.php
            new class extends Migration
            {
                public function up(): void
                {
                    DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION role_has_permissions_guard_developer() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM permissions WHERE id = NEW.permission_id AND name LIKE 'dev.%')
                   AND NOT EXISTS (SELECT 1 FROM roles WHERE id = NEW.role_id AND name = 'super-admin') THEN
                    RAISE EXCEPTION 'Las capacidades de desarrollo (dev.*) solo las tiene el rol super-admin.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$
            SQL);

                    DB::statement(<<<'SQL'
            CREATE TRIGGER role_has_permissions_guard_developer
                BEFORE INSERT OR UPDATE ON role_has_permissions
                FOR EACH ROW EXECUTE FUNCTION role_has_permissions_guard_developer()
            SQL);
                }

                public function down(): void
                {
                    DB::statement('DROP TRIGGER IF EXISTS role_has_permissions_guard_developer ON role_has_permissions');
                    DB::statement('DROP FUNCTION IF EXISTS role_has_permissions_guard_developer()');
                }
            },

            /**
             * Las dos salidas de los triggers append-only ya no las abre cualquiera.
             *
             * `bank_transactions` y `attachments` dejaban borrar si la transacción
             * declaraba `SET LOCAL sacst.allow_*`. Pero un parámetro así lo puede fijar
             * cualquier rol, `sacst_app` incluido: la puerta la sostenía solo la
             * convención del código. Y la de `attachments` no preguntaba qué se
             * borraba, así que por ahí se iba el PDF de cualquier recibo u orden.
             *
             * Ahora el trigger exige además que quien borra sea el **dueño** de la
             * tabla. En producción la app entra con `sacst_app`, que no lo es; lo es
             * `sacst_owner`, el rol de las migraciones, y con él se crean las dos
             * únicas funciones que borran, `SECURITY DEFINER`:
             *
             * - `forget_statement_attachment(id)`: solo el archivo de un extracto
             *   importado, que es lo único que se borra al revertir una importación;
             * - `discard_statement_transaction(id)`: solo un movimiento todavía
             *   pendiente, sin conciliar, que es lo que la reversión elimina.
             *
             * La marca `sacst.allow_*` sigue: la fija la función, no la app. En
             * desarrollo todo corre con el dueño y el trigger se comporta igual que
             * antes; lo que cambia es producción, que es donde importa.
             */
            // Migración original: 2026_09_24_030000_close_append_only_escape_hatches.php
            new class extends Migration
            {
                public function up(): void
                {
                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sacst_runs_as_owner(p_table oid) RETURNS boolean
            LANGUAGE sql STABLE AS $$
                SELECT pg_has_role(current_user, relowner, 'USAGE') FROM pg_class WHERE oid = p_table
            $$;

            CREATE OR REPLACE FUNCTION bank_transactions_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF current_setting('sacst.allow_bank_transaction_delete', true) = 'on'
                       AND sacst_runs_as_owner(TG_RELID) THEN
                        RETURN OLD;
                    END IF;

                    RAISE EXCEPTION 'Un movimiento bancario no se borra: se revierte la importación que lo trajo.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.bank_account_id IS DISTINCT FROM OLD.bank_account_id
                    OR NEW.transaction_date IS DISTINCT FROM OLD.transaction_date
                    OR NEW.value_date IS DISTINCT FROM OLD.value_date
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.direction IS DISTINCT FROM OLD.direction
                    OR NEW.operation_id IS DISTINCT FROM OLD.operation_id
                    OR NEW.causal_code IS DISTINCT FROM OLD.causal_code
                    OR NEW.description IS DISTINCT FROM OLD.description
                    OR NEW.balance_after IS DISTINCT FROM OLD.balance_after
                    OR NEW.fingerprint IS DISTINCT FROM OLD.fingerprint
                THEN
                    RAISE EXCEPTION 'Los datos de un movimiento bancario no se editan: son lo que informó el banco.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION attachments_are_immutable() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF current_setting('sacst.allow_attachment_delete', true) = 'on'
                       AND sacst_runs_as_owner(TG_RELID) THEN
                        RETURN OLD;
                    END IF;

                    RAISE EXCEPTION 'Un adjunto no se borra: se sube el nuevo y el anterior queda.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RAISE EXCEPTION 'Un adjunto no se edita: cada version es un archivo nuevo.'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION forget_statement_attachment(p_attachment_id bigint) RETURNS void
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
            DECLARE
                v_subject text;
            BEGIN
                SELECT subject_type INTO v_subject FROM attachments WHERE id = p_attachment_id;

                IF v_subject IS NULL THEN
                    RAISE EXCEPTION 'El adjunto % no existe.', p_attachment_id
                        USING ERRCODE = 'no_data_found';
                END IF;

                IF v_subject <> 'import' THEN
                    RAISE EXCEPTION 'Solo se borra el archivo de un extracto importado, al revertir la importación.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                PERFORM set_config('sacst.allow_attachment_delete', 'on', true);
                DELETE FROM attachments WHERE id = p_attachment_id;
                PERFORM set_config('sacst.allow_attachment_delete', 'off', true);
            END;
            $$;

            CREATE OR REPLACE FUNCTION discard_statement_transaction(p_transaction_id bigint) RETURNS void
            LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp AS $$
            DECLARE
                v_status text;
            BEGIN
                SELECT reconciliation_status INTO v_status FROM bank_transactions WHERE id = p_transaction_id;

                IF v_status IS NULL THEN
                    RAISE EXCEPTION 'El movimiento % no existe.', p_transaction_id
                        USING ERRCODE = 'no_data_found';
                END IF;

                IF v_status <> 'pending' THEN
                    RAISE EXCEPTION 'Solo se descarta un movimiento sin conciliar, al revertir la importación que lo trajo.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                PERFORM set_config('sacst.allow_bank_transaction_delete', 'on', true);
                DELETE FROM bank_transactions WHERE id = p_transaction_id;
                PERFORM set_config('sacst.allow_bank_transaction_delete', 'off', true);
            END;
            $$;
        SQL);
                }

                public function down(): void
                {
                    DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS discard_statement_transaction(bigint);
            DROP FUNCTION IF EXISTS forget_statement_attachment(bigint);

            CREATE OR REPLACE FUNCTION bank_transactions_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF current_setting('sacst.allow_bank_transaction_delete', true) = 'on' THEN
                        RETURN OLD;
                    END IF;

                    RAISE EXCEPTION 'Un movimiento bancario no se borra: se revierte la importación que lo trajo.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.bank_account_id IS DISTINCT FROM OLD.bank_account_id
                    OR NEW.transaction_date IS DISTINCT FROM OLD.transaction_date
                    OR NEW.value_date IS DISTINCT FROM OLD.value_date
                    OR NEW.amount IS DISTINCT FROM OLD.amount
                    OR NEW.direction IS DISTINCT FROM OLD.direction
                    OR NEW.operation_id IS DISTINCT FROM OLD.operation_id
                    OR NEW.causal_code IS DISTINCT FROM OLD.causal_code
                    OR NEW.description IS DISTINCT FROM OLD.description
                    OR NEW.balance_after IS DISTINCT FROM OLD.balance_after
                    OR NEW.fingerprint IS DISTINCT FROM OLD.fingerprint
                THEN
                    RAISE EXCEPTION 'Los datos de un movimiento bancario no se editan: son lo que informó el banco.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION attachments_are_immutable() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF current_setting('sacst.allow_attachment_delete', true) = 'on' THEN
                        RETURN OLD;
                    END IF;

                    RAISE EXCEPTION 'Un adjunto no se borra: se sube el nuevo y el anterior queda.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RAISE EXCEPTION 'Un adjunto no se edita: cada version es un archivo nuevo.'
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;

            DROP FUNCTION IF EXISTS sacst_runs_as_owner(oid);
        SQL);
                }
            },

            /**
             * El sistema no se queda sin un administrador activo.
             *
             * `SetUserActive` y `UpdateUser` lo comprobaban antes de escribir, pero sin
             * bloquear nada: dos administradores que se desactivan uno al otro al mismo
             * tiempo veían cada uno al otro activo, y pasaban los dos. Sin
             * administrador nadie tiene `usuarios.*`, y reactivar una cuenta solo se
             * puede con SQL a mano.
             *
             * El trigger es **diferido** porque `syncRoles` quita el rol y lo vuelve a
             * poner dentro de la misma transacción: mirado en el medio, un
             * administrador que conserva su rol parecería haberlo perdido.
             *
             * Y toma un **bloqueo** antes de contar. Es lo que ordena las
             * transacciones simultáneas: la segunda espera a que la primera confirme, y
             * como en `READ COMMITTED` cada consulta ve lo ya confirmado, cuenta con el
             * cambio de la otra. Las Actions toman el mismo bloqueo
             * (`UserManagementGuard::lockAdministrators`) para que su mensaje legible
             * coincida con lo que la base va a decidir.
             */
            // Migración original: 2026_09_25_000000_require_an_active_administrator.php
            new class extends Migration
            {
                public function up(): void
                {
                    DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ensure_an_active_administrator() RETURNS trigger AS $$
            DECLARE
                v_admin bigint;
            BEGIN
                SELECT id INTO v_admin FROM roles WHERE name = 'administrador' AND guard_name = 'web';

                IF v_admin IS NULL THEN
                    RETURN NULL;
                END IF;

                -- Solo importa si lo que cambió le quita algo a un administrador.
                IF TG_TABLE_NAME = 'users' THEN
                    IF NOT EXISTS (
                        SELECT 1 FROM model_has_roles
                        WHERE role_id = v_admin AND model_type = 'App\Models\User' AND model_id = OLD.id
                    ) THEN
                        RETURN NULL;
                    END IF;
                ELSIF OLD.role_id <> v_admin THEN
                    RETURN NULL;
                END IF;

                PERFORM pg_advisory_xact_lock(hashtext('sacst.active_administrators'));

                IF NOT EXISTS (
                    SELECT 1
                    FROM users u
                    JOIN model_has_roles m ON m.model_id = u.id AND m.model_type = 'App\Models\User'
                    WHERE m.role_id = v_admin AND u.is_active
                ) THEN
                    RAISE EXCEPTION 'El sistema no puede quedarse sin un administrador activo.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE CONSTRAINT TRIGGER users_keep_an_active_administrator
                AFTER UPDATE OF is_active ON users
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW
                WHEN (OLD.is_active AND NOT NEW.is_active)
                EXECUTE FUNCTION ensure_an_active_administrator();

            CREATE CONSTRAINT TRIGGER model_has_roles_keep_an_active_administrator
                AFTER UPDATE OR DELETE ON model_has_roles
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW
                EXECUTE FUNCTION ensure_an_active_administrator();
        SQL);
                }

                public function down(): void
                {
                    DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS model_has_roles_keep_an_active_administrator ON model_has_roles;
            DROP TRIGGER IF EXISTS users_keep_an_active_administrator ON users;
            DROP FUNCTION IF EXISTS ensure_an_active_administrator();
        SQL);
                }
            },
        ];
    }
};
