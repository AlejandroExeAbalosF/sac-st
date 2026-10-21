<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pagos de multas por el banco y recurso del 20 % — DER físico de Multas §6.2 y §8.
 *
 * - Un pago bancario se aplica con **un crédito** del extracto, por su
 *   importe entero, y ese crédito no respalda el pago de otra multa (M06,
 *   P14 provisorio).
 * - El comprobante de un pago se adjunta al pago (`fine_payment`).
 * - `multas.resolver-recurso` no se delega (M07): la guarda de la base suma
 *   el permiso a su lista.
 */
return new class extends Migration
{
    private const ADJUNTOS_ANTERIORES = "'expediente', 'import', 'receipt', 'payment_order',
                'pase', 'disbursement', 'cash_transfer', 'deposit_ticket',
                'period_closing', 'legacy_document', 'fine_case', 'fine',
                'fine_historical_movement', 'bank_account_opening'";

    private const NO_DELEGABLES_ANTERIORES = [
        'roles.gestionar',
        'usuarios.crear',
        'usuarios.editar',
        'usuarios.desactivar',
        'usuarios.restablecer-password',
    ];

    private const ROLES_PERMITIDOS = ['administrador', 'super-admin'];

    public function up(): void
    {
        DB::statement('ALTER TABLE attachments DROP CONSTRAINT attachments_subject_type_check');
        DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachments_subject_type_check
            CHECK (subject_type IN ('.self::ADJUNTOS_ANTERIORES.", 'fine_payment'))");

        DB::unprepared(<<<'SQL'
            /*
             * Una transferencia, una multa (M06), con el crédito entero (P14).
             *
             * La recepción del pago está respaldada por una sola imputación
             * vigente del extracto, por el importe completo del crédito, y ese
             * crédito no respalda la aplicación de otro pago. El crédito se
             * bloquea para que dos verificaciones no lo tomen a la vez.
             */
            CREATE OR REPLACE FUNCTION fine_payment_application_bank_credit() RETURNS trigger AS $$
            DECLARE
                v_medio text;
                v_evento bigint;
                v_imputaciones int;
                v_credito bigint;
                v_importe numeric(19,2);
            BEGIN
                SELECT medium INTO v_medio FROM fine_payments WHERE id = NEW.fine_payment_id;

                IF v_medio NOT IN ('bank_transfer', 'direct_deposit', 'echeq') THEN
                    RETURN NEW;
                END IF;

                SELECT financial_event_id INTO v_evento FROM fund_receipts WHERE id = NEW.fund_receipt_id;
                SELECT count(*), min(bank_transaction_id) INTO v_imputaciones, v_credito
                  FROM bank_transaction_allocations
                 WHERE financial_event_id = v_evento AND reversal_of_id IS NULL;

                IF v_imputaciones <> 1 THEN
                    RAISE EXCEPTION 'Un pago por el banco se aplica con un solo crédito del extracto.'
                        USING ERRCODE = 'check_violation';
                END IF;

                SELECT amount INTO v_importe FROM bank_transactions WHERE id = v_credito FOR UPDATE;

                IF v_importe <> NEW.amount THEN
                    RAISE EXCEPTION 'El pago es por el crédito entero: % y no %.', v_importe, NEW.amount
                        USING ERRCODE = 'check_violation';
                END IF;

                IF EXISTS (
                    SELECT 1
                      FROM fine_payment_applications a
                      JOIN fund_receipts r ON r.id = a.fund_receipt_id
                      JOIN bank_transaction_allocations b
                        ON b.financial_event_id = r.financial_event_id AND b.reversal_of_id IS NULL
                     WHERE b.bank_transaction_id = v_credito
                ) THEN
                    RAISE EXCEPTION 'Ese crédito ya respalda el pago de una multa: una transferencia es de una sola multa.'
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER fine_payment_applications_bank_credit BEFORE INSERT ON fine_payment_applications
                FOR EACH ROW EXECUTE FUNCTION fine_payment_application_bank_credit();
        SQL);

        $this->guardarNoDelegables([...self::NO_DELEGABLES_ANTERIORES, 'multas.resolver-recurso']);
    }

    public function down(): void
    {
        $this->guardarNoDelegables(self::NO_DELEGABLES_ANTERIORES);

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS fine_payment_applications_bank_credit ON fine_payment_applications;
            DROP FUNCTION IF EXISTS fine_payment_application_bank_credit();
        SQL);

        if (DB::table('attachments')->where('subject_type', 'fine_payment')->exists()) {
            throw new RuntimeException('Hay comprobantes adjuntos a pagos de multas. No se puede retirar esta migración sin perderlos.');
        }

        DB::statement('ALTER TABLE attachments DROP CONSTRAINT attachments_subject_type_check');
        DB::statement('ALTER TABLE attachments ADD CONSTRAINT attachments_subject_type_check
            CHECK (subject_type IN ('.self::ADJUNTOS_ANTERIORES.'))');
    }

    /**
     * Redefine la guarda de 2026_10_12_010000 con otra lista. Como allá, si un
     * rol de la matriz ya tiene alguno, frena en vez de quitárselo en silencio.
     *
     * @param  list<string>  $reservados
     */
    private function guardarNoDelegables(array $reservados): void
    {
        $infractores = DB::table('role_has_permissions as rp')
            ->join('roles as r', 'r.id', '=', 'rp.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->whereIn('p.name', $reservados)
            ->whereNotIn('r.name', self::ROLES_PERMITIDOS)
            ->orderBy('r.name')
            ->selectRaw("r.name || ' (' || p.name || ')' AS detalle")
            ->pluck('detalle')
            ->implode(', ');

        if ($infractores !== '') {
            throw new RuntimeException(
                "Estos roles tienen permisos que ya no se delegan: {$infractores}. "
                .'Quitáselos desde Configuración → Roles —queda en la auditoría— y volvé a migrar.'
            );
        }

        $lista = fn (array $valores): string => implode(', ', array_map(fn (string $v): string => "'{$v}'", $valores));

        DB::statement(
            'CREATE OR REPLACE FUNCTION role_has_permissions_guard_non_delegable() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM permissions WHERE id = NEW.permission_id AND name IN ('.$lista($reservados).'))
                   AND NOT EXISTS (SELECT 1 FROM roles WHERE id = NEW.role_id AND name IN ('.$lista(self::ROLES_PERMITIDOS).')) THEN
                    RAISE EXCEPTION \'Este permiso no se delega: lo tiene solo el administrador.\'
                        USING ERRCODE = \'check_violation\';
                END IF;

                RETURN NEW;
            END;
            $$'
        );
    }
};
