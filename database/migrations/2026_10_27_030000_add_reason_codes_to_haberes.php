<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El motivo de Haberes se elige de una lista y se guarda como código, como
 * en Multas (`2026_10_27_020000_add_reason_codes_to_fines`).
 *
 * Cada columna de motivo de Haberes tiene al lado su código y el texto queda
 * como detalle:
 *
 * - el código es el de la lista de ese acto (`HaberesReasonAct`), y está
 *   siempre que el acto registró un motivo;
 * - el detalle es opcional, salvo con «Otro»;
 * - un detalle, si está, no es solo espacios.
 *
 * Un recibo de papel que cae porque se anuló el pago fuera del circuito al
 * que pertenece no repite el motivo del pago —su lista es otra—: lleva
 * «Se anuló el pago que respaldaba», que solo admite un papel de un pago. El
 * motivo de fondo queda en el pago.
 *
 * Lo ya cargado queda en «Otro», con su texto como detalle. La guarda de los
 * registros del sistema anterior suma la columna nueva entre las que anular
 * puede escribir.
 *
 * Anular y reactivar un expediente o un haber no tienen columna: el motivo
 * queda en la auditoría, con su código.
 */
return new class extends Migration
{
    private const ORDEN = "'wrong_beneficiary_data', 'wrong_amount', 'wrong_installment', 'saf_observation', 'other'";

    private const VENTANA = "'saf_observation', 'data_entry_error', 'resolution_changed', 'other'";

    private const PAGO_ANTERIOR = "'wrong_installment', 'wrong_amount', 'duplicate', 'data_entry_error', 'other'";

    private const PAPEL = "'mistyped_number', 'wrong_installment', 'duplicate', 'other'";

    private const COMPROBANTE = "'deposit_not_made', 'wrong_receipt', 'wrong_expediente', 'duplicate', 'other'";

    private const TABLAS = ['payment_orders', 'beneficiary_installments', 'legacy_settlements', 'legacy_documents', 'deposit_tickets'];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE payment_orders ADD COLUMN rejection_or_void_reason_code varchar(30);
            ALTER TABLE beneficiary_installments ADD COLUMN edit_unlock_reason_code varchar(30);
            ALTER TABLE legacy_settlements ADD COLUMN void_reason_code varchar(30);
            ALTER TABLE legacy_documents ADD COLUMN void_reason_code varchar(30);
            ALTER TABLE deposit_tickets ADD COLUMN discarded_reason_code varchar(30);
        SQL);

        $this->completarLoCargado();

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION legacy_records_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un registro del sistema anterior no se borra: se anula.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.voided_at IS NOT NULL THEN
                    RAISE EXCEPTION 'Un registro anulado del sistema anterior no se modifica.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF (to_jsonb(NEW) - ARRAY['voided_at', 'voided_by', 'void_reason', 'void_reason_code'])
                    IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['voided_at', 'voided_by', 'void_reason', 'void_reason_code']) THEN
                    RAISE EXCEPTION 'Un registro del sistema anterior no se edita: se anula y se carga de nuevo.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        $orden = self::ORDEN;
        $ventana = self::VENTANA;
        $pagoAnterior = self::PAGO_ANTERIOR;
        $papel = self::PAPEL;
        $comprobante = self::COMPROBANTE;

        DB::unprepared(<<<SQL
            ALTER TABLE payment_orders
                DROP CONSTRAINT payment_orders_void_check,
                ADD CONSTRAINT payment_orders_void_check CHECK (
                    (status IN ('voided', 'rejected')) = (rejection_or_void_reason_code IS NOT NULL)
                    AND (status NOT IN ('voided', 'rejected') OR (voided_by IS NOT NULL AND voided_at IS NOT NULL))
                    AND (rejection_or_void_reason_code IS NULL OR rejection_or_void_reason_code IN ({$orden}))
                    AND (rejection_or_void_reason IS NULL OR btrim(rejection_or_void_reason) <> '')
                    AND (rejection_or_void_reason IS NULL OR rejection_or_void_reason_code IS NOT NULL)
                    AND (rejection_or_void_reason_code IS DISTINCT FROM 'other' OR rejection_or_void_reason IS NOT NULL)
                );

            ALTER TABLE beneficiary_installments
                DROP CONSTRAINT beneficiary_installments_edit_unlock_check,
                ADD CONSTRAINT beneficiary_installments_edit_unlock_check CHECK (
                    (edit_unlocked_at IS NULL) = (edit_unlock_reason_code IS NULL)
                    AND (edit_unlocked_at IS NOT NULL OR (edit_unlock_reason IS NULL AND edit_unlocked_by IS NULL))
                    AND (edit_unlock_reason_code IS NULL OR edit_unlock_reason_code IN ({$ventana}))
                    AND (edit_unlock_reason IS NULL OR btrim(edit_unlock_reason) <> '')
                    AND (edit_unlock_reason_code IS DISTINCT FROM 'other' OR edit_unlock_reason IS NOT NULL)
                );

            ALTER TABLE legacy_settlements
                DROP CONSTRAINT legacy_settlements_void_check,
                ADD CONSTRAINT legacy_settlements_void_check CHECK (
                    (voided_at IS NULL) = (voided_by IS NULL)
                    AND (voided_at IS NULL) = (void_reason_code IS NULL)
                    AND (void_reason IS NULL OR voided_at IS NOT NULL)
                    AND (void_reason_code IS NULL OR void_reason_code IN ({$pagoAnterior}))
                    AND (void_reason IS NULL OR btrim(void_reason) <> '')
                    AND (void_reason_code IS DISTINCT FROM 'other' OR void_reason IS NOT NULL)
                );

            ALTER TABLE legacy_documents
                DROP CONSTRAINT legacy_documents_void_check,
                ADD CONSTRAINT legacy_documents_void_check CHECK (
                    (voided_at IS NULL) = (voided_by IS NULL)
                    AND (voided_at IS NULL) = (void_reason_code IS NULL)
                    AND (void_reason IS NULL OR voided_at IS NOT NULL)
                    AND (void_reason_code IS NULL OR void_reason_code IN ({$papel})
                         OR (void_reason_code = 'settlement_voided' AND legacy_settlement_id IS NOT NULL))
                    AND (void_reason IS NULL OR btrim(void_reason) <> '')
                    AND (void_reason_code IS DISTINCT FROM 'other' OR void_reason IS NOT NULL)
                );

            ALTER TABLE deposit_tickets
                DROP CONSTRAINT deposit_tickets_discarded_check,
                ADD CONSTRAINT deposit_tickets_discarded_check CHECK (
                    (status = 'discarded') = (discarded_reason_code IS NOT NULL)
                    AND (discarded_reason IS NULL OR status = 'discarded')
                    AND (discarded_reason_code IS NULL OR discarded_reason_code IN ({$comprobante}))
                    AND (discarded_reason IS NULL OR btrim(discarded_reason) <> '')
                    AND (discarded_reason_code IS DISTINCT FROM 'other' OR discarded_reason IS NOT NULL)
                );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE payment_orders
                DROP CONSTRAINT payment_orders_void_check,
                ADD CONSTRAINT payment_orders_void_check CHECK (
                    status NOT IN ('voided', 'rejected')
                    OR (voided_by IS NOT NULL AND voided_at IS NOT NULL
                        AND rejection_or_void_reason IS NOT NULL)
                ) NOT VALID;

            ALTER TABLE beneficiary_installments
                DROP CONSTRAINT beneficiary_installments_edit_unlock_check,
                ADD CONSTRAINT beneficiary_installments_edit_unlock_check CHECK (
                    (edit_unlocked_at IS NULL AND edit_unlock_reason IS NULL AND edit_unlocked_by IS NULL)
                    OR (edit_unlocked_at IS NOT NULL AND edit_unlock_reason IS NOT NULL)
                ) NOT VALID;

            ALTER TABLE legacy_settlements
                DROP CONSTRAINT legacy_settlements_void_check,
                ADD CONSTRAINT legacy_settlements_void_check
                    CHECK ((voided_at IS NULL) = (voided_by IS NULL) AND (voided_at IS NULL) = (void_reason IS NULL)) NOT VALID;

            ALTER TABLE legacy_documents
                DROP CONSTRAINT legacy_documents_void_check,
                ADD CONSTRAINT legacy_documents_void_check
                    CHECK ((voided_at IS NULL) = (voided_by IS NULL) AND (voided_at IS NULL) = (void_reason IS NULL)) NOT VALID;

            ALTER TABLE deposit_tickets
                DROP CONSTRAINT deposit_tickets_discarded_check,
                ADD CONSTRAINT deposit_tickets_discarded_check
                    CHECK ((status = 'discarded') = (discarded_reason IS NOT NULL)) NOT VALID;

            CREATE OR REPLACE FUNCTION legacy_records_append_only() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un registro del sistema anterior no se borra: se anula.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.voided_at IS NOT NULL THEN
                    RAISE EXCEPTION 'Un registro anulado del sistema anterior no se modifica.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF (to_jsonb(NEW) - ARRAY['voided_at', 'voided_by', 'void_reason'])
                    IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['voided_at', 'voided_by', 'void_reason']) THEN
                    RAISE EXCEPTION 'Un registro del sistema anterior no se edita: se anula y se carga de nuevo.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            ALTER TABLE payment_orders DROP COLUMN rejection_or_void_reason_code;
            ALTER TABLE beneficiary_installments DROP COLUMN edit_unlock_reason_code;
            ALTER TABLE legacy_settlements DROP COLUMN void_reason_code;
            ALTER TABLE legacy_documents DROP COLUMN void_reason_code;
            ALTER TABLE deposit_tickets DROP COLUMN discarded_reason_code;
        SQL);
    }

    /**
     * Lo que ya tenía motivo queda en «Otro», con su texto como detalle.
     *
     * Son tablas con guardas que no admiten editar esas filas —justamente
     * por eso—: el relleno corre con ellas apagadas, dentro de la migración y
     * como dueño de la tabla, y no toca ninguna otra columna.
     */
    private function completarLoCargado(): void
    {
        foreach (self::TABLAS as $tabla) {
            DB::statement("ALTER TABLE {$tabla} DISABLE TRIGGER USER");
        }

        DB::unprepared(<<<'SQL'
            UPDATE payment_orders SET rejection_or_void_reason_code = 'other'
             WHERE status IN ('voided', 'rejected');
            UPDATE payment_orders SET rejection_or_void_reason = 'Sin motivo registrado'
             WHERE status IN ('voided', 'rejected') AND (rejection_or_void_reason IS NULL OR btrim(rejection_or_void_reason) = '');
            UPDATE beneficiary_installments SET edit_unlock_reason_code = 'other' WHERE edit_unlocked_at IS NOT NULL;
            UPDATE legacy_settlements SET void_reason_code = 'other' WHERE voided_at IS NOT NULL;
            UPDATE legacy_documents SET void_reason_code = 'other' WHERE voided_at IS NOT NULL;
            UPDATE deposit_tickets SET discarded_reason_code = 'other' WHERE status = 'discarded';
        SQL);

        foreach (self::TABLAS as $tabla) {
            DB::statement("ALTER TABLE {$tabla} ENABLE TRIGGER USER");
        }
    }
};
