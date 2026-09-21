<?php

declare(strict_types=1);

namespace App\Modules\Shared\Actions;

use App\Modules\Shared\Models\AuditEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Deja registrado un cambio del dominio.
 *
 * Se llama desde los Actions y no desde un observador de Eloquent a
 * propósito: el observador sabe qué columnas cambiaron pero no por qué, y
 * «quién validó este egreso» o «quién reabrió el período» no se deducen de
 * un diff. El nombre de la acción lo pone quien la ejecuta.
 *
 * Para maestros mutables sin significado propio —una razón social
 * corregida— alcanza `owen-it/laravel-auditing`, que va por observador.
 * Esta tabla es para los hechos del circuito.
 */
final class RecordAuditEvent
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>  $metadata
     */
    public function handle(
        string $action,
        Model $subject,
        ?array $before = null,
        ?array $after = null,
        array $metadata = [],
        ?int $actorId = null,
    ): AuditEvent {
        return AuditEvent::query()->create([
            // Los Actions reciben al actor explícitamente porque también
            // pueden ejecutarse fuera de una petición HTTP. La sesión es
            // solo el respaldo para los casos interactivos más simples.
            'user_id' => $actorId ?? Auth::id(),
            'action' => $action,
            // El nombre corto de la clase, no el espacio de nombres
            // completo: mover una clase de módulo no tiene por qué
            // invalidar el historial.
            'subject_type' => class_basename($subject),
            'subject_id' => $subject->getKey(),
            'old_values' => $before === null ? null : ($before ?: null),
            'new_values' => $after === null ? null : ($after ?: null),
            'metadata' => $metadata ?: null,
            'ip_address' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 500) ?: null,
            'occurred_at' => now(),
        ]);
    }

    /**
     * Lo que cambió de verdad, no la fila entera.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function diff(array $before, array $after): array
    {
        $cambiados = array_keys(array_filter(
            $after,
            fn (mixed $valor, string $campo): bool => ($before[$campo] ?? null) !== $valor,
            ARRAY_FILTER_USE_BOTH,
        ));

        return [
            array_intersect_key($before, array_flip($cambiados)),
            array_intersect_key($after, array_flip($cambiados)),
        ];
    }
}
