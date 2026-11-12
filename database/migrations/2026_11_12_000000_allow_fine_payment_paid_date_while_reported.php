<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La fecha de pago del comprobante se completa o se corrige mientras el pago
 * está informado.
 *
 * «Pagado el (según el comprobante)» pasó del alta del pago al diálogo del
 * comprobante: se carga cuando llega el papel, junto con su foto. Es un dato
 * informativo —no mueve el saldo, ni decide el término, ni sale en el
 * recibo—, así que no necesita el trámite de «Corregir», que descarta el pago
 * y crea otro. El resto del pago sigue sin editarse, y al verificarlo la fecha
 * queda fija con él. Cada cambio queda en `audit_events`.
 *
 * Reescribe `fine_payments_guard` de `2026_11_04_010000` con esa única salida.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->guardaDePagos(fechaEditable: true);
    }

    public function down(): void
    {
        $this->guardaDePagos(fechaEditable: false);
    }

    private function guardaDePagos(bool $fechaEditable): void
    {
        $libres = "'status', 'discarded_by', 'discarded_at', 'discard_reason', 'discard_reason_code', 'updated_at'"
            .($fechaEditable ? ", 'declared_paid_on'" : '');
        // La fecha del comprobante se completa o se corrige solo en un informado.
        $fecha = $fechaEditable
            ? "IF NEW.declared_paid_on IS DISTINCT FROM OLD.declared_paid_on
                    AND NOT (OLD.status = 'reported' AND NEW.status = 'reported') THEN
                    RAISE EXCEPTION 'La fecha de pago se cambia solo mientras el pago está informado.'
                        USING ERRCODE = 'restrict_violation';
                END IF;"
            : '';

        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION fine_payments_guard() RETURNS trigger AS \$\$
            DECLARE
                v_anterior RECORD;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Un pago no se borra.' USING ERRCODE = 'restrict_violation';
                END IF;

                IF TG_OP = 'INSERT' THEN
                    IF NEW.status IN ('discarded', 'voided') THEN
                        RAISE EXCEPTION 'Un pago no nace descartado ni anulado.' USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.status = 'applied' AND NEW.medium NOT IN ('cash', 'cheque') THEN
                        RAISE EXCEPTION 'Un pago por el banco se aplica al verificar el crédito: nace informado.'
                            USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.status = 'reported' AND NEW.medium IN ('cash', 'cheque') THEN
                        RAISE EXCEPTION 'Lo que se cobra en la caja se aplica en el acto.'
                            USING ERRCODE = 'check_violation';
                    END IF;
                    IF NEW.appeal_kind IS NOT NULL THEN
                        PERFORM 1 FROM fines WHERE id = NEW.fine_id FOR UPDATE;
                        IF NEW.appeal_base_amount <> fine_obligation(NEW.fine_id) THEN
                            RAISE EXCEPTION 'La base del 20 %% es el importe vigente de la multa al registrar el recurso: % y no %.',
                                fine_obligation(NEW.fine_id), NEW.appeal_base_amount
                                USING ERRCODE = 'check_violation';
                        END IF;
                    END IF;
                    -- Una corrección reemplaza a un pago de la misma multa y del mismo tipo, ya descartado.
                    IF NEW.replaces_fine_payment_id IS NOT NULL THEN
                        SELECT fine_id, status, appeal_kind IS NOT NULL AS es_recurso, presented_at, presented_precision
                          INTO v_anterior
                          FROM fine_payments WHERE id = NEW.replaces_fine_payment_id;
                        IF v_anterior.fine_id <> NEW.fine_id OR v_anterior.status <> 'discarded'
                            OR v_anterior.es_recurso <> (NEW.appeal_kind IS NOT NULL) THEN
                            RAISE EXCEPTION 'Una corrección reemplaza a un pago descartado de la misma multa y del mismo tipo.'
                                USING ERRCODE = 'check_violation';
                        END IF;
                        -- Corrige los datos de un informado: nace informada y conserva su
                        -- presentación, que es lo que decide el término.
                        IF NEW.status <> 'reported'
                            OR NEW.presented_at IS DISTINCT FROM v_anterior.presented_at
                            OR NEW.presented_precision IS DISTINCT FROM v_anterior.presented_precision THEN
                            RAISE EXCEPTION 'Una corrección nace informada y conserva la presentación del pago que reemplaza.'
                                USING ERRCODE = 'check_violation';
                        END IF;
                    END IF;
                    RETURN NEW;
                END IF;

                IF (to_jsonb(NEW) - ARRAY[{$libres}])
                   IS DISTINCT FROM
                   (to_jsonb(OLD) - ARRAY[{$libres}]) THEN
                    RAISE EXCEPTION 'Los datos de un pago no se editan.' USING ERRCODE = 'restrict_violation';
                END IF;
                {$fecha}
                IF NEW.status IS DISTINCT FROM OLD.status
                    AND NOT ((OLD.status = 'reported' AND NEW.status IN ('applied', 'discarded'))
                        OR (OLD.status = 'applied' AND NEW.status = 'voided')) THEN
                    RAISE EXCEPTION 'Un pago % no pasa a %.', OLD.status, NEW.status USING ERRCODE = 'check_violation';
                END IF;

                IF NEW.status = OLD.status AND (NEW.discarded_by, NEW.discarded_at, NEW.discard_reason, NEW.discard_reason_code)
                        IS DISTINCT FROM (OLD.discarded_by, OLD.discarded_at, OLD.discard_reason, OLD.discard_reason_code) THEN
                    RAISE EXCEPTION 'El descarte se registra al descartar.' USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
        SQL);
    }
};
