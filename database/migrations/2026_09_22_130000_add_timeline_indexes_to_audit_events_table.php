<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices para mirar la auditoría de punta a punta.
 *
 * Los que había responden «qué le pasó a este expediente» —por sujeto— y
 * «qué hizo este usuario». La auditoría de operaciones pregunta además
 * «qué pasó en estas fechas», «quién anuló cobros» y «qué se hizo con las
 * cuotas», siempre del más reciente al más antiguo. El `id` al final de
 * cada uno es el desempate del orden, y es lo que deja recorrer el Excel
 * por cursor sin volver a ordenar.
 *
 * El índice por sujeto concreto se conserva: lo usan los historiales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->index(['occurred_at', 'id']);
            $table->index(['action', 'occurred_at', 'id']);
            $table->index(['subject_type', 'occurred_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropIndex(['occurred_at', 'id']);
            $table->dropIndex(['action', 'occurred_at', 'id']);
            $table->dropIndex(['subject_type', 'occurred_at', 'id']);
        });
    }
};
