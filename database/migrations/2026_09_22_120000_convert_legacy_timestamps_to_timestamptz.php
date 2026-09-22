<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los timestamp heredados se escribieron como hora civil de Salta, sin zona.
 * Interpretarlos explícitamente en esa zona conserva el instante al pasar a
 * timestamptz, independientemente de la zona de la conexión que migra.
 */
return new class extends Migration
{
    private const LEGACY_COLUMNS = [
        'cash_to_bank_transfer_items' => ['created_at', 'updated_at'],
        'cash_to_bank_transfers' => ['created_at', 'updated_at'],
        'failed_jobs' => ['failed_at'],
        'passkeys' => ['created_at', 'last_used_at', 'updated_at'],
        'password_reset_tokens' => ['created_at'],
        'permissions' => ['created_at', 'updated_at'],
        'roles' => ['created_at', 'updated_at'],
        'users' => ['created_at', 'email_verified_at', 'two_factor_confirmed_at', 'updated_at'],
    ];

    public function up(): void
    {
        $this->convert('timestamp with time zone');
    }

    public function down(): void
    {
        $this->convert('timestamp without time zone');
    }

    private function convert(string $type): void
    {
        foreach (self::LEGACY_COLUMNS as $table => $columns) {
            $clauses = array_map(
                fn (string $column): string => sprintf(
                    'ALTER COLUMN "%1$s" TYPE %2$s USING "%1$s" AT TIME ZONE \'America/Argentina/Salta\'',
                    $column,
                    $type,
                ),
                $columns,
            );

            DB::statement(sprintf('ALTER TABLE "%s" %s', $table, implode(', ', $clauses)));
        }
    }
};
