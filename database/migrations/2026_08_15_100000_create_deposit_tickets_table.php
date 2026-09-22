<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El ticket del depósito que llega con el expediente.
 *
 * **Entidad que el DER no tiene**, y hace falta. El área describió el
 * flujo: llega el expediente con el comprobante, el empleado carga sus
 * datos, y cada tanto revisa el extracto buscando ese movimiento. Ese
 * estado intermedio —«cargado y todavía no encontrado en el banco»— hoy
 * vive en la cabeza de la contadora.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Las claves que hacen posible la FK compuesta de más abajo.
         *
         * Son redundantes con la clave primaria —`id` ya es único— y aun
         * así hacen falta: PostgreSQL exige que el destino de una foránea
         * compuesta tenga su propio índice único. Es el mismo recurso que
         * ya usan `people (id, type)` y `person_bank_accounts (id,
         * person_id)` para garantizar que un haber no se asigne a quien no
         * es beneficiario.
         */
        DB::statement('ALTER TABLE haberes ADD CONSTRAINT haberes_id_expediente_unique
            UNIQUE (id, expediente_id)');
        DB::statement('ALTER TABLE beneficiary_installments ADD CONSTRAINT installments_id_haber_unique
            UNIQUE (id, haber_id)');

        Schema::create('deposit_tickets', function (Blueprint $table): void {
            $table->id();

            /*
             * El ticket llega dentro de un expediente: ese vínculo no es
             * opcional y es lo que después evita tener que adivinar de
             * quién es el dinero. El haber y la cuota se completan cuando
             * se sepan —a veces el ticket dice «Cuota 1 de 3» y a veces no.
             */
            $table->foreignId('expediente_id')->constrained('expedientes')->cascadeOnDelete();
            $table->foreignId('haber_id')->nullable()->constrained('haberes')->nullOnDelete();
            $table->foreignId('beneficiary_installment_id')->nullable()
                ->constrained('beneficiary_installments')->nullOnDelete();

            /** La cuenta del organismo que el ticket declara como destino. */
            $table->foreignId('bank_account_id')->constrained('bank_accounts')->restrictOnDelete();

            $table->date('deposited_at');
            /** La hora que imprime el ticket. Ayuda a desempatar dos depósitos del mismo día. */
            $table->time('deposited_time')->nullable();

            $table->decimal('amount', 19, 2);

            /*
             * El «OPERACIÓN: 1053565801» del ticket.
             *
             * No se presume que sirva para el cruce: el extracto trae su
             * propia `Referencia` y a veces otro número dentro del
             * concepto, y no hay evidencia de que pertenezcan al mismo
             * espacio de numeración. Se guarda, se compara, y las
             * coincidencias quedan registradas en `match_signals` para
             * poder responder con datos —dentro de unos meses— si este
             * campo sirvió alguna vez. Mismo criterio que §4.3.
             */
            $table->string('operation_number', 40)->nullable();
            $table->string('terminal', 40)->nullable();

            $table->string('deposit_kind', 20);

            $table->text('notes')->nullable();

            $table->string('status', 20)->default('waiting');

            /*
             * Un depósito es un movimiento y un ticket es un depósito: la
             * relación es 1:1. El índice único parcial lo impone en vez de
             * confiar en que nadie lo intente dos veces.
             */
            $table->foreignId('bank_transaction_id')->nullable()
                ->constrained('bank_transactions')->nullOnDelete();

            /** Qué señales coincidieron al vincular. Es la prueba del punto anterior. */
            $table->jsonb('match_signals')->nullable();
            $table->foreignId('matched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('matched_at')->nullable();

            $table->string('discarded_reason', 300)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            // La cola de trabajo: los que esperan, del más viejo al más nuevo.
            $table->index(['status', 'deposited_at']);
            $table->index(['bank_account_id', 'deposited_at', 'amount']);
            $table->index('operation_number');
        });

        DB::statement('ALTER TABLE deposit_tickets ADD CONSTRAINT deposit_tickets_amount_check
            CHECK (amount > 0)');
        DB::statement("ALTER TABLE deposit_tickets ADD CONSTRAINT deposit_tickets_kind_check
            CHECK (deposit_kind IN ('cash_deposit', 'transfer', 'cheque_deposit'))");
        DB::statement("ALTER TABLE deposit_tickets ADD CONSTRAINT deposit_tickets_status_check
            CHECK (status IN ('waiting', 'matched', 'discarded'))");
        // Un ticket vinculado tiene movimiento, quién lo vinculó y cuándo;
        // uno que espera no tiene ninguna de las tres cosas.
        DB::statement("ALTER TABLE deposit_tickets ADD CONSTRAINT deposit_tickets_matched_check
            CHECK ((status = 'matched') = (bank_transaction_id IS NOT NULL))");
        DB::statement('ALTER TABLE deposit_tickets ADD CONSTRAINT deposit_tickets_matched_author_check
            CHECK ((matched_by IS NULL) = (matched_at IS NULL))');
        DB::statement('ALTER TABLE deposit_tickets ADD CONSTRAINT deposit_tickets_matched_pair_check
            CHECK (bank_transaction_id IS NULL OR matched_at IS NOT NULL)');
        // Descartar un ticket exige decir por qué: es afirmar que ese
        // depósito no va a aparecer nunca.
        DB::statement("ALTER TABLE deposit_tickets ADD CONSTRAINT deposit_tickets_discarded_check
            CHECK ((status = 'discarded') = (discarded_reason IS NOT NULL))");

        DB::statement('CREATE UNIQUE INDEX deposit_tickets_transaction_unique
            ON deposit_tickets (bank_transaction_id) WHERE bank_transaction_id IS NOT NULL');

        /*
         * La cuota tiene que pertenecer al haber, y el haber al expediente.
         * Sin esto nada impediría que un ticket del expediente A apunte a
         * una cuota del expediente B, y esa plata terminaría financiando a
         * quien no corresponde.
         */
        DB::statement('ALTER TABLE deposit_tickets ADD CONSTRAINT deposit_tickets_haber_fk
            FOREIGN KEY (haber_id, expediente_id) REFERENCES haberes (id, expediente_id)');
        DB::statement('ALTER TABLE deposit_tickets ADD CONSTRAINT deposit_tickets_installment_fk
            FOREIGN KEY (beneficiary_installment_id, haber_id)
            REFERENCES beneficiary_installments (id, haber_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_tickets');

        DB::statement('ALTER TABLE beneficiary_installments DROP CONSTRAINT IF EXISTS installments_id_haber_unique');
        DB::statement('ALTER TABLE haberes DROP CONSTRAINT IF EXISTS haberes_id_expediente_unique');
    }
};
