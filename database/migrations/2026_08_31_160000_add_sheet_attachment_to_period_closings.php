<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El cierre apunta a su planilla.
 *
 * **Por qué hace falta una columna y no alcanza con buscar el adjunto.**
 * Un período que se reabre y se vuelve a cerrar tiene otro snapshot, y su
 * planilla tiene que ser otra: devolver la del cierre anterior entregaría
 * números que ya no son los del libro.
 *
 * El primer intento fue comparar `attachments.created_at` contra
 * `period_closings.closed_at`. No sirve: **los timestamps del sistema
 * tienen precisión de segundos** —`timestampsTz()` genera `timestamp(0)`—
 * así que reabrir y volver a cerrar dentro del mismo segundo devolvía la
 * planilla vieja. Improbable con una persona delante, imposible de
 * descartar con un job en cola, y el tipo de error que aparece una vez cada
 * dos años sin dejar rastro.
 *
 * Con el puntero explícito no hay reloj de por medio: cada cierre lo pone
 * en nulo y la exportación lo completa.
 *
 * **La planilla anterior no se borra.** Pudo imprimirse y firmarse, y es la
 * evidencia de qué se cerró la primera vez. Queda en `attachments` con su
 * fecha; lo único que cambia es a cuál apunta el cierre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('period_closings', function (Blueprint $table): void {
            $table->foreignId('sheet_attachment_id')->nullable()
                ->constrained('attachments')->nullOnDelete();
        });

        $this->cierreCongeladoSalvoSuPlanilla();
    }

    public function down(): void
    {
        Schema::table('period_closings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sheet_attachment_id');
        });

        $this->cierreCongelado();
    }

    /**
     * El congelado del cierre, con una excepción declarada.
     *
     * Un período cerrado no admite cambios — es el punto entero del
     * cierre—, pero emitir su planilla ocurre **después** de cerrar y tiene
     * que poder anotar a cuál apunta. La excepción se declara acá, en el
     * único lugar donde no se puede saltear, y es de una sola columna: si
     * el cambio toca cualquier otra, se rechaza igual que antes.
     */
    private function cierreCongeladoSalvoSuPlanilla(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION period_closings_frozen() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status = 'draft' THEN
                        RETURN OLD;
                    END IF;
                    RAISE EXCEPTION 'Un periodo cerrado no se borra: se reabre.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.status <> 'closed' THEN
                    RETURN NEW;
                END IF;

                /*
                 * Anotar la planilla recien emitida es lo unico que un
                 * periodo cerrado admite sin reabrirse.
                 *
                 * Se compara la fila entera menos esa columna: si lo demas
                 * quedo igual, el cambio es solo el puntero al adjunto y
                 * pasa. Cualquier otra cosa que venga de contrabando en el
                 * mismo UPDATE se rechaza.
                 *
                 * Los tres saldos finales se excluyen porque **son columnas
                 * generadas**, y en un BEFORE UPDATE todavia no estan
                 * calculadas: PostgreSQL las resuelve despues de los
                 * triggers, asi que en NEW llegan nulas y toda comparacion
                 * daria distinto. No se pierde nada: dependen de las
                 * columnas base, que si se comparan.
                 */
                IF (to_jsonb(NEW) - 'sheet_attachment_id' - 'updated_at'
                        - 'closing_cash' - 'closing_cheques' - 'closing_bank_deposits')
                    = (to_jsonb(OLD) - 'sheet_attachment_id' - 'updated_at'
                        - 'closing_cash' - 'closing_cheques' - 'closing_bank_deposits')
                THEN
                    RETURN NEW;
                END IF;

                -- Del cierre solo se sale reabriendo.
                IF NEW.status <> 'reopened' THEN
                    RAISE EXCEPTION
                        'El periodo % — % ya esta cerrado. Reabrilo con motivo antes de tocarlo.',
                        OLD.period_from, OLD.period_to
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.opening_cash IS DISTINCT FROM OLD.opening_cash
                    OR NEW.opening_cheques IS DISTINCT FROM OLD.opening_cheques
                    OR NEW.opening_bank_deposits IS DISTINCT FROM OLD.opening_bank_deposits
                    OR NEW.received_cash IS DISTINCT FROM OLD.received_cash
                    OR NEW.received_cheques IS DISTINCT FROM OLD.received_cheques
                    OR NEW.received_bank_deposits IS DISTINCT FROM OLD.received_bank_deposits
                    OR NEW.disbursed_cash IS DISTINCT FROM OLD.disbursed_cash
                    OR NEW.disbursed_cheques IS DISTINCT FROM OLD.disbursed_cheques
                    OR NEW.disbursed_bank_deposits IS DISTINCT FROM OLD.disbursed_bank_deposits
                    OR NEW.deposited_to_bank_cash IS DISTINCT FROM OLD.deposited_to_bank_cash
                    OR NEW.deposited_to_bank_cheques IS DISTINCT FROM OLD.deposited_to_bank_cheques
                THEN
                    RAISE EXCEPTION 'Reabrir un periodo no reescribe su snapshot: lo habilita a recalcularse.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    /** La versión anterior, sin la excepción de la planilla. */
    private function cierreCongelado(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION period_closings_frozen() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status = 'draft' THEN
                        RETURN OLD;
                    END IF;
                    RAISE EXCEPTION 'Un periodo cerrado no se borra: se reabre.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF OLD.status <> 'closed' THEN
                    RETURN NEW;
                END IF;

                IF NEW.status <> 'reopened' THEN
                    RAISE EXCEPTION
                        'El periodo % — % ya esta cerrado. Reabrilo con motivo antes de tocarlo.',
                        OLD.period_from, OLD.period_to
                        USING ERRCODE = 'restrict_violation';
                END IF;

                IF NEW.opening_cash IS DISTINCT FROM OLD.opening_cash
                    OR NEW.opening_cheques IS DISTINCT FROM OLD.opening_cheques
                    OR NEW.opening_bank_deposits IS DISTINCT FROM OLD.opening_bank_deposits
                    OR NEW.received_cash IS DISTINCT FROM OLD.received_cash
                    OR NEW.received_cheques IS DISTINCT FROM OLD.received_cheques
                    OR NEW.received_bank_deposits IS DISTINCT FROM OLD.received_bank_deposits
                    OR NEW.disbursed_cash IS DISTINCT FROM OLD.disbursed_cash
                    OR NEW.disbursed_cheques IS DISTINCT FROM OLD.disbursed_cheques
                    OR NEW.disbursed_bank_deposits IS DISTINCT FROM OLD.disbursed_bank_deposits
                    OR NEW.deposited_to_bank_cash IS DISTINCT FROM OLD.deposited_to_bank_cash
                    OR NEW.deposited_to_bank_cheques IS DISTINCT FROM OLD.deposited_to_bank_cheques
                THEN
                    RAISE EXCEPTION 'Reabrir un periodo no reescribe su snapshot: lo habilita a recalcularse.'
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }
};
