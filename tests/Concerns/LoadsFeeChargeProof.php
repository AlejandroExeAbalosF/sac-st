<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Modules\Aranceles\Actions\RecordFeeChargeProof;
use App\Modules\Aranceles\Models\FeeCharge;
use App\Modules\Aranceles\Models\FeeChargeProof;
use App\Support\BusinessDate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/** El comprobante que un pendiente necesita para poder identificarse. */
trait LoadsFeeChargeProof
{
    protected function conComprobante(FeeCharge $cobro, ?int $actorId = null, string $importe = '120'): FeeCharge
    {
        $this->cargarComprobante($cobro, $actorId, $importe);

        return $cobro;
    }

    protected function cargarComprobante(FeeCharge $cobro, ?int $actorId = null, string $importe = '120'): FeeChargeProof
    {
        return app(RecordFeeChargeProof::class)->handle(
            $cobro,
            $importe,
            ['depositDate' => BusinessDate::today(), 'depositTime' => null, 'operationNumber' => null, 'terminal' => null, 'notes' => null],
            UploadedFile::fake()->create('comprobante.pdf', 50, 'application/pdf'),
            (string) Str::uuid(),
            $actorId,
        );
    }
}
