<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El alta de un usuario exige email y DNI.
 *
 * **Divergencia deliberada respecto del DER §9.1**, que define
 * `email UNIQUE NULL` porque suponía que no todo el personal tiene casilla
 * institucional. El área confirmó que sí la tiene, así que el email pasa a
 * ser obligatorio. Beneficio lateral: con email para todos, cualquiera
 * puede autogestionar el restablecimiento de contraseña en vez de
 * depender de un administrador.
 *
 * El DNI no figura en `users` en el DER —los documentos viven en `people`,
 * que modela beneficiarios, empleadores y depositantes—. Se agrega acá
 * como columna propia porque el operador del sistema no es un beneficiario
 * y no justifica acoplarlo al maestro de personas, que además todavía no
 * existe. Si en algún momento un mismo humano tiene que ser operador y
 * persona a la vez, se migra a la relación.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Se agrega con default para no fallar si ya hay filas; el default
        // se retira enseguida, porque un DNI vacío no es válido para nadie.
        Schema::table('users', function (Blueprint $table): void {
            $table->string('document_number', 20)->default('')->after('username');
        });

        DB::statement('ALTER TABLE users ALTER COLUMN document_number DROP DEFAULT');

        Schema::table('users', function (Blueprint $table): void {
            $table->string('email')->nullable(false)->change();
            $table->unique('document_number');
        });

        // Solo dígitos, entre 7 y 9: cubre el DNI argentino actual y los
        // documentos viejos de siete cifras que todavía circulan.
        DB::statement(
            "ALTER TABLE users ADD CONSTRAINT users_document_number_format_check
             CHECK (document_number ~ '^[0-9]{7,9}$')"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_document_number_format_check');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['document_number']);
            $table->dropColumn('document_number');
            $table->string('email')->nullable()->change();
        });
    }
};
