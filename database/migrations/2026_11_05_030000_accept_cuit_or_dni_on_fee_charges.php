<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Quien paga un arancel se identifica con CUIT o con DNI (regla A01): puede ser una
 * persona o una empresa, y no está relevado cuál. El DNI va de 6 a 8 dígitos, como en
 * el resto del sistema, y el CUIT tiene 11.
 *
 * La columna deja de llamarse `payer_cuit`, porque ya no guarda solo eso, y deja de ser
 * `char(11)`, que rellenaría un DNI con espacios. La guarda de `fee_charges` nombra la
 * columna, así que se vuelve a escribir.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE fee_charges DROP CONSTRAINT fee_charges_payer_cuit_check;
            ALTER TABLE fee_charges RENAME COLUMN payer_cuit TO payer_document;
            ALTER TABLE fee_charges ALTER COLUMN payer_document TYPE varchar(11);
            ALTER TABLE fee_charges ADD CONSTRAINT fee_charges_payer_document_format
                CHECK (payer_document ~ '^([0-9]{6,8}|[0-9]{11})$');
        SQL);

        DB::unprepared($this->guarda('payer_document'));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE fee_charges DROP CONSTRAINT fee_charges_payer_document_format;
            ALTER TABLE fee_charges ALTER COLUMN payer_document TYPE char(11);
            ALTER TABLE fee_charges RENAME COLUMN payer_document TO payer_cuit;
            ALTER TABLE fee_charges ADD CONSTRAINT fee_charges_payer_cuit_check
                CHECK (payer_cuit ~ '^[0-9]{11}$');
        SQL);

        DB::unprepared($this->guarda('payer_cuit'));
    }

    /**
     * @param  literal-string  $columna
     * @return literal-string
     */
    private function guarda(string $columna): string
    {
        return <<<SQL
            CREATE OR REPLACE FUNCTION fee_charges_guard() RETURNS trigger AS \$\$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un cobro de arancel no se borra: se anula.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.payer_name IS DISTINCT FROM OLD.payer_name
                    OR NEW.{$columna} IS DISTINCT FROM OLD.{$columna}
                    OR NEW.medium IS DISTINCT FROM OLD.medium
                    OR NEW.requested_on IS DISTINCT FROM OLD.requested_on
                    OR NEW.pricing_date IS DISTINCT FROM OLD.pricing_date
                    OR NEW.idempotency_key IS DISTINCT FROM OLD.idempotency_key
                    OR NEW.created_by IS DISTINCT FROM OLD.created_by
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at
                    OR (OLD.paid_on IS NOT NULL AND NEW.paid_on IS DISTINCT FROM OLD.paid_on)
                THEN
                    RAISE EXCEPTION 'Los datos de un cobro de arancel no se editan: se corrige con otro cobro.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.status IS DISTINCT FROM OLD.status
                    AND NOT (OLD.status = 'pending' AND NEW.status IN ('paid', 'voided'))
                    AND NOT (OLD.status = 'paid' AND NEW.status = 'voided')
                THEN
                    RAISE EXCEPTION 'Un cobro de arancel % no pasa a %.', OLD.status, NEW.status
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
        SQL;
    }
};
