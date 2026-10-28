<?php

declare(strict_types=1);

use App\Support\Text\PersonName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lleva el nombre y el apellido ya cargados a la forma en que se muestran:
 * «Pérez, Juan». Desde ahora los modelos lo hacen al guardar; esto alcanza a
 * lo anterior.
 *
 * Usa la misma regla de la app para que no haya dos versiones de ella: lo
 * que ya mezcla mayúsculas y minúsculas no se toca. No tiene vuelta atrás,
 * porque cómo estaba escrito antes no se conserva.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'people'] as $table) {
            DB::table($table)
                ->whereNotNull('first_name')
                ->orderBy('id')
                ->select(['id', 'first_name', 'last_name'])
                ->chunkById(500, function ($rows) use ($table): void {
                    foreach ($rows as $row) {
                        $firstName = PersonName::format($row->first_name);
                        $lastName = PersonName::format($row->last_name);

                        if ($firstName !== $row->first_name || $lastName !== $row->last_name) {
                            DB::table($table)->where('id', $row->id)->update([
                                'first_name' => $firstName,
                                'last_name' => $lastName,
                            ]);
                        }
                    }
                });
        }
    }

    public function down(): void {}
};
