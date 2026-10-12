<?php

declare(strict_types=1);

namespace App\Modules\Shared\Actions;

use App\Modules\Shared\Enums\DocumentType;
use App\Modules\Shared\Models\DocumentSeries;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ConfigureDocumentNumbering
{
    public function __construct(private readonly RecordAuditEvent $audit) {}

    /** @param array<string, mixed> $input */
    public function handle(DocumentType $type, array $input, int $actorId): DocumentSeries
    {
        $data = Validator::make($input, [
            'prefix' => ['required', 'string', 'regex:/^[0-9]{1,12}$/D'],
            'prefixDigits' => ['required', 'integer', 'between:1,12'],
            'numberDigits' => ['required', 'integer', 'between:1,12'],
            'separator' => ['required', Rule::in(['/', '-'])],
            'nextNumber' => ['required', 'integer', 'between:1,999999999999'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'token' => ['required', 'string', 'size:64'],
        ])->validate();

        return DB::transaction(function () use ($type, $data, $actorId): DocumentSeries {
            DB::select('SELECT pg_advisory_xact_lock(76104, 1)');
            $series = DocumentSeries::query()->where('code', $type->seriesCode())
                ->where('is_active', true)->lockForUpdate()->firstOrFail();
            if (! hash_equals($series->configurationToken(), $data['token'])) {
                throw ValidationException::withMessages(['token' => 'Se emitió un comprobante o cambió la configuración. Recargá la página antes de guardar.']);
            }
            if (strlen($data['prefix']) > (int) $data['prefixDigits']) {
                throw ValidationException::withMessages(['prefixDigits' => 'La cantidad de dígitos debe alcanzar para el prefijo.']);
            }
            $prefix = str_pad($data['prefix'], (int) $data['prefixDigits'], '0', STR_PAD_LEFT);
            // Se reserva el prefijo aun en versiones históricas: nunca identifica otro tipo.
            $samePrefix = DocumentSeries::query()->get()->filter(
                fn (DocumentSeries $other): bool => str_pad($other->display_prefix ?? $other->code, $other->code_padding, '0', STR_PAD_LEFT) === $prefix,
            );
            if ($samePrefix->contains(fn (DocumentSeries $other): bool => $other->document_type !== $type)) {
                throw ValidationException::withMessages(['prefix' => 'Ese prefijo pertenece a otro tipo de comprobante, incluso si su serie ya es histórica.']);
            }
            $ids = $samePrefix->pluck('id')->all();
            $last = max(
                (int) DB::table('receipts')->whereIn('document_series_id', $ids)->max('number'),
                (int) DB::table('payment_orders')->whereIn('document_series_id', $ids)->max('number'),
            );
            if ((int) $data['nextNumber'] <= $last) {
                throw ValidationException::withMessages(['nextNumber' => "Con ese prefijo ya se emitió el número {$last}. El próximo debe ser mayor."]);
            }
            $before = $series->only(['display_prefix', 'code_padding', 'number_padding', 'format_pattern', 'next_number']);
            $changes = [
                'display_prefix' => $data['prefix'], 'code_padding' => (int) $data['prefixDigits'],
                'number_padding' => (int) $data['numberDigits'], 'format_pattern' => '{code}'.$data['separator'].'{number}',
                'next_number' => (int) $data['nextNumber'],
            ];
            $used = DB::table('receipts')->where('document_series_id', $series->id)->exists()
                || DB::table('payment_orders')->where('document_series_id', $series->id)->exists();
            $formatChanged = ($series->display_prefix ?? $series->code) !== $changes['display_prefix']
                || $series->code_padding !== $changes['code_padding'] || $series->number_padding !== $changes['number_padding']
                || $series->format_pattern !== $changes['format_pattern'];
            $previousId = $series->id;
            if ($used && $formatChanged) {
                $series->forceFill(['is_active' => false])->save();
                $series = $series->replicate();
                $series->forceFill(['is_active' => true, 'created_by' => $actorId]);
            }
            $series->fill($changes)->save();
            $this->audit->handle('numeracion.configurada', $series, before: $before, after: $changes,
                metadata: ['motivo' => $data['reason'], 'serie_anterior' => $previousId], actorId: $actorId);

            return $series;
        });
    }
}
