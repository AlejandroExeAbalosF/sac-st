<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El archivo del extracto pasa a ser un adjunto como cualquier otro.
 *
 * `bank_statement_imports.stored_path` guardaba la ruta directamente, con
 * esta nota en su migración: *«attachments es de Shared y todavía no
 * existe. Mover la ruta a attachments más adelante es un UPDATE; frenar
 * la ingesta hasta tenerla, no»*.
 *
 * Ya existe, así que se mueve. No es prolijidad: dos formas de guardar un
 * archivo son dos lugares donde buscarlo, dos maneras de borrarlo mal y
 * dos permisos que mantener sincronizados.
 *
 * El `mime_type` se deduce de la extensión porque no se guardaba. Es la
 * única pérdida de la migración y es menor: los formatos posibles son dos
 * y están declarados en `source_format`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $imports = DB::table('bank_statement_imports')
            ->select('id', 'original_filename', 'stored_path', 'file_sha256', 'file_size', 'imported_by', 'created_at')
            ->orderBy('id')
            ->get();

        foreach ($imports as $import) {
            DB::table('attachments')->insert([
                'subject_type' => 'import',
                'subject_id' => $import->id,
                'document_type' => 'bank_statement',
                'title' => mb_substr((string) $import->original_filename, 0, 200),
                'storage_disk' => 'local',
                'object_key' => $import->stored_path,
                'original_filename' => $import->original_filename,
                'mime_type' => self::mimeFor((string) $import->original_filename),
                // El CHECK exige un tamaño positivo y un extracto vacío no
                // existe; si alguno quedó en cero es un dato roto de
                // desarrollo, no un archivo real.
                'size_bytes' => max(1, (int) $import->file_size),
                'sha256' => $import->file_sha256,
                'source' => 'uploaded',
                'confidentiality' => 'internal',
                'created_by' => $import->imported_by,
                'created_at' => $import->created_at,
            ]);
        }

        Schema::table('bank_statement_imports', function (Blueprint $table): void {
            $table->dropColumn('stored_path');
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_imports', function (Blueprint $table): void {
            $table->string('stored_path', 255)->default('')->after('original_filename');
        });

        $adjuntos = DB::table('attachments')
            ->where('subject_type', 'import')
            ->select('subject_id', 'object_key')
            ->get();

        foreach ($adjuntos as $adjunto) {
            DB::table('bank_statement_imports')
                ->where('id', $adjunto->subject_id)
                ->update(['stored_path' => $adjunto->object_key]);
        }

        // El trigger de inmutabilidad no aplica a `DELETE` masivo sin la
        // variable de sesión: se declara para poder revertir la migración.
        DB::statement("SET LOCAL sacst.allow_attachment_delete = 'on'");
        DB::table('attachments')->where('subject_type', 'import')->delete();

        Schema::table('bank_statement_imports', function (Blueprint $table): void {
            $table->string('stored_path', 255)->default(null)->change();
        });
    }

    private static function mimeFor(string $filename): string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'csv', 'txt' => 'text/csv',
            'xls' => 'application/vnd.ms-excel',
            'xlsx', 'xlsm' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            default => 'application/octet-stream',
        };
    }
};
