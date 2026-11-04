<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los fondos aplicados también pueden ser de un cobro de Aranceles.
 *
 * La guarda nació con Multas y exigía un pago de una multa en todo
 * `funds_applied`: la base rechazaba cualquier cobro de otra caja. Sigue sin
 * admitir un evento huérfano.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION financial_events_fine_events_linked() RETURNS trigger AS $$
            BEGIN
                IF NEW.event_type = 'funds_applied' AND NOT EXISTS (
                    SELECT 1 FROM fine_payment_applications WHERE application_event_id = NEW.id
                ) AND NOT EXISTS (
                    SELECT 1 FROM fee_charge_fundings WHERE application_event_id = NEW.id
                ) THEN
                    RAISE EXCEPTION 'Los fondos aplicados del evento % no corresponden a ningún pago.', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.event_type = 'excess_reclassified' AND NOT EXISTS (
                    SELECT 1 FROM fine_excess_reclassifications WHERE event_id = NEW.id
                ) THEN
                    RAISE EXCEPTION 'La reclasificación del evento % no corresponde a ninguna multa.', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION financial_events_fine_events_linked() RETURNS trigger AS $$
            BEGIN
                IF NEW.event_type = 'funds_applied' AND NOT EXISTS (
                    SELECT 1 FROM fine_payment_applications WHERE application_event_id = NEW.id
                ) THEN
                    RAISE EXCEPTION 'Los fondos aplicados del evento % no corresponden a ningún pago.', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.event_type = 'excess_reclassified' AND NOT EXISTS (
                    SELECT 1 FROM fine_excess_reclassifications WHERE event_id = NEW.id
                ) THEN
                    RAISE EXCEPTION 'La reclasificación del evento % no corresponde a ninguna multa.', NEW.id
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }
};
