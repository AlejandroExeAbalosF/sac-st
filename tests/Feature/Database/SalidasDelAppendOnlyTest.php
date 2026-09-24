<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Modules\Banking\Enums\ReconciliationStatus;
use App\Modules\Banking\Enums\TransactionDirection;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\BankStatementImport;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Shared\Actions\StoreAttachment;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Models\Attachment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Las dos salidas de los triggers append-only, y quién puede abrirlas.
 *
 * `attachments` y `bank_transactions` dejaban borrar con un
 * `SET LOCAL sacst.allow_*`, que cualquier rol puede fijar: la puerta la
 * sostenía solo la convención del código, y la de los adjuntos no
 * preguntaba qué se borraba. Ahora el borrado lo hacen dos funciones que
 * corren como dueñas de la tabla y validan qué se va.
 */
class SalidasDelAppendOnlyTest extends TestCase
{
    use RefreshDatabase;

    /** Por la función solo se va el archivo de un extracto. */
    public function test_la_base_solo_borra_el_archivo_de_un_extracto(): void
    {
        $recibo = $this->adjunto(AttachmentSubject::Receipt);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Solo se borra el archivo de un extracto importado');

        DB::select('SELECT forget_statement_attachment(?)', [$recibo->id]);
    }

    /** Y la Action lo dice antes, con un mensaje legible. */
    public function test_la_accion_rechaza_olvidar_un_adjunto_que_no_es_de_un_extracto(): void
    {
        $recibo = $this->adjunto(AttachmentSubject::Receipt);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Solo se borra el archivo de un extracto importado');

        DB::transaction(fn () => app(StoreAttachment::class)->forget($recibo));
    }

    public function test_el_archivo_de_un_extracto_se_borra_por_la_funcion(): void
    {
        $extracto = $this->adjunto(AttachmentSubject::Import);

        DB::select('SELECT forget_statement_attachment(?)', [$extracto->id]);

        $this->assertNull(Attachment::query()->find($extracto->id));
    }

    /** Un movimiento que ya se trató no se descarta, ni por la función. */
    public function test_la_base_solo_descarta_un_movimiento_sin_conciliar(): void
    {
        $movimiento = $this->movimiento();
        $movimiento->forceFill([
            'reconciliation_status' => ReconciliationStatus::Ignored,
            'ignored_by' => $this->operador()->id,
            'ignored_at' => now(),
            'ignored_reason' => 'Comisión bancaria, no es un depósito',
        ])->save();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Solo se descarta un movimiento sin conciliar');

        DB::select('SELECT discard_statement_transaction(?)', [$movimiento->id]);
    }

    public function test_un_movimiento_pendiente_se_descarta_por_la_funcion(): void
    {
        $movimiento = $this->movimiento();

        DB::select('SELECT discard_statement_transaction(?)', [$movimiento->id]);

        $this->assertNull(BankTransaction::query()->find($movimiento->id));
    }

    /** Sin la función, la marca sola ya no abre nada para quien no es dueño. */
    public function test_quien_no_es_dueno_no_abre_la_puerta_fijando_la_marca(): void
    {
        $this->requiereCrearRoles();

        $recibo = $this->adjunto(AttachmentSubject::Receipt);
        $movimiento = $this->movimiento();

        // Como en producción: la app lee y escribe filas, pero no es dueña.
        DB::statement('CREATE ROLE sacst_app_de_prueba NOLOGIN');
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON attachments, bank_transactions TO sacst_app_de_prueba');
        DB::statement('SET LOCAL ROLE sacst_app_de_prueba');
        DB::statement("SET LOCAL sacst.allow_attachment_delete = 'on'");
        DB::statement("SET LOCAL sacst.allow_bank_transaction_delete = 'on'");

        $this->assertRechazado(fn () => DB::table('attachments')->where('id', $recibo->id)->delete());
        $this->assertRechazado(fn () => DB::table('bank_transactions')->where('id', $movimiento->id)->delete());

        // La función sí puede, porque corre como dueña, y solo lo que valida.
        DB::select('SELECT discard_statement_transaction(?)', [$movimiento->id]);
        $this->assertSame(0, DB::table('bank_transactions')->where('id', $movimiento->id)->count());
    }

    private function assertRechazado(callable $borrar): void
    {
        DB::statement('SAVEPOINT intento');

        try {
            $borrar();
            $this->fail('La base dejó borrar a un rol que no es dueño de la tabla.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('no se borra', $e->getMessage());
        } finally {
            DB::statement('ROLLBACK TO SAVEPOINT intento');
        }
    }

    /**
     * Crear un rol exige `CREATEROLE`, que el usuario local de desarrollo no
     * tiene. Donde se puede —el contenedor de CI, un Postgres de prueba—
     * el test corre; donde no, lo dice en vez de pasar en falso.
     */
    private function requiereCrearRoles(): void
    {
        $puede = DB::selectOne(
            'SELECT rolsuper OR rolcreaterole AS puede FROM pg_roles WHERE rolname = current_user',
        );

        if (! ($puede->puede ?? false)) {
            $this->markTestSkipped('El usuario de la base de tests no puede crear roles (falta CREATEROLE).');
        }
    }

    private function adjunto(AttachmentSubject $sujeto): Attachment
    {
        return Attachment::query()->create([
            'subject_type' => $sujeto,
            'subject_id' => 1,
            'document_type' => 'prueba',
            'title' => 'prueba.pdf',
            'storage_disk' => 'local',
            'object_key' => 'prueba/'.uniqid().'.pdf',
            'original_filename' => 'prueba.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
            'sha256' => hash('sha256', uniqid('', true)),
            'source' => 'uploaded',
            'confidentiality' => 'internal',
            'created_by' => null,
        ]);
    }

    private function movimiento(): BankTransaction
    {
        $cuenta = BankAccount::query()->create([
            'label' => 'Cta. Cte. 2693 — Haberes',
            'bank_name' => 'Banco Macro',
            'account_number' => '310000123456789',
            'currency' => 'ARS',
            'is_active' => true,
        ]);

        $importacion = BankStatementImport::query()->create([
            'bank_account_id' => $cuenta->id,
            'imported_by' => $this->operador()->id,
            'original_filename' => 'extracto.csv',
            'file_size' => 100,
            'file_sha256' => hash('sha256', uniqid('', true)),
            'source_format' => 'macro_online_csv',
            'parser_version' => 'macro-csv-1',
            'period_from' => '2026-04-01',
            'period_to' => '2026-04-30',
            'status' => 'completed',
            'rows_total' => 1,
            'rows_valid' => 1,
            'rows_rejected' => 0,
            'rows_new' => 1,
            'rows_duplicate' => 0,
            'imported_at' => now(),
        ]);

        return BankTransaction::query()->create([
            'bank_account_id' => $cuenta->id,
            'first_seen_import_id' => $importacion->id,
            'transaction_date' => '2026-04-24',
            'amount' => '1000.00',
            'direction' => TransactionDirection::Credit,
            'description' => 'Transferencia recibida',
            'fingerprint' => hash('sha256', uniqid('', true)),
        ]);
    }
}
