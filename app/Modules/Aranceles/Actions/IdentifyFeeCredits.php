<?php

declare(strict_types=1);

namespace App\Modules\Aranceles\Actions;

use App\Modules\Aranceles\Enums\FeeChargeMedium;
use App\Modules\Aranceles\Enums\FeeChargeStatus;
use App\Modules\Aranceles\Models\FeeCharge;
use App\Modules\Aranceles\Models\FeeChargeBalance;
use App\Modules\Aranceles\Support\FeeBankCredits;
use App\Modules\Aranceles\Support\FeeChargeFunds;
use App\Modules\Banking\Actions\RegisterBankFundReceipt;
use App\Modules\Banking\Models\BankTransaction;
use App\Modules\Banking\Support\AllocatableAmount;
use App\Modules\Ledger\Models\FinancialEvent;
use App\Modules\Shared\Actions\RecordAuditEvent;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Identifica uno o más créditos del extracto como el pago de un cobro pendiente.
 *
 * De cada crédito se toma solo lo necesario: lo que sobra queda libre y sin
 * identificar (regla A54). Lo que no alcanza queda vinculado y protegido, con lo
 * que falta a la vista (A55). Al completar el total, se emite un solo recibo.
 *
 * Bloquea en orden: el cobro, los créditos por `id`, y las recepciones que nacen.
 */
final class IdentifyFeeCredits
{
    public function __construct(
        private readonly FeeBankCredits $creditos,
        private readonly FeeChargeFunds $fondos,
        private readonly RegisterBankFundReceipt $registrar,
        private readonly AllocatableAmount $libre,
        private readonly CompleteFeeCharge $completar,
        private readonly RecordAuditEvent $auditar,
    ) {}

    /**
     * @param  list<int>  $creditos
     *
     * @throws ValidationException
     */
    public function handle(FeeCharge $cobro, array $creditos, string $idempotencyKey, ?int $signedById, ?int $actorId): FeeCharge
    {
        return DB::transaction(function () use ($cobro, $creditos, $idempotencyKey, $signedById, $actorId): FeeCharge {
            $cobro = FeeCharge::query()->whereKey($cobro->id)->lockForUpdate()->firstOrFail();

            // Un segundo envío del mismo formulario: ya se identificó.
            $claves = array_map(fn (int $id): string => self::clave($idempotencyKey, $id), $creditos);

            if (FinancialEvent::query()->whereIn('idempotency_key', $claves)->exists()) {
                return $cobro;
            }

            if ($cobro->status !== FeeChargeStatus::Pending || $cobro->medium !== FeeChargeMedium::Bank) {
                throw ValidationException::withMessages(['credits' => 'Ese cobro ya no está pendiente.']);
            }

            // El comprobante es opcional: el dinero se verifica contra el extracto.

            $this->within($cobro, $creditos, $idempotencyKey, $signedById, $actorId);

            return $cobro->refresh();
        });
    }

    /**
     * Dentro de una transacción, con el cobro bloqueado. Una corrección usa los
     * mismos créditos y deja libre el que ya no hace falta (`$usaSoloLoNecesario`).
     *
     * @param  list<int>  $creditos
     *
     * @throws ValidationException
     */
    public function within(FeeCharge $cobro, array $creditos, string $idempotencyKey, ?int $signedById, ?int $actorId, bool $usaSoloLoNecesario = false): void
    {
        $ids = array_values(array_unique(array_map('intval', $creditos)));
        $apertura = $this->creditos->book();

        if ($ids === [] || $apertura === null) {
            throw ValidationException::withMessages(['credits' => 'Elegí al menos un crédito del extracto.']);
        }

        // Por `id`, para que dos identificaciones no se traben entre sí.
        $bloqueados = BankTransaction::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        $identificables = $this->creditos->identifiable($apertura)->whereIn('id', $ids)->pluck('id')->all();

        if (count($identificables) !== count($ids)) {
            throw ValidationException::withMessages(['credits' => 'Uno de esos créditos ya no se puede identificar en Aranceles: está imputado o no es de sus cuentas.']);
        }

        $saldo = FeeChargeBalance::query()->findOrFail($cobro->id);
        $falta = Decimal::sub((string) $saldo->total, (string) $saldo->funded);
        $identificado = '0.00';

        // Del más antiguo al más nuevo: es el orden en que entró el dinero.
        foreach ($bloqueados->sortBy([['transaction_date', 'asc'], ['id', 'asc']]) as $credito) {
            if (! Decimal::isNegative(Decimal::sub('0', $falta))) {
                if ($usaSoloLoNecesario) {
                    continue;
                }

                throw ValidationException::withMessages(['credits' => 'Con los créditos anteriores ya se completa el cobro: sacá el del '.$credito->transaction_date?->format('d/m/Y').'.']);
            }

            $libre = $this->libre->for($credito);
            $toma = Decimal::isNegative(Decimal::sub($libre, $falta)) ? $libre : $falta;

            $recepcion = $this->registrar->handle(
                $credito,
                $toma,
                self::clave($idempotencyKey, $credito->id),
                $this->fondos->cashBoxId(),
                actorId: $actorId,
                notes: 'Arancel de '.$cobro->payer_name,
            );

            $this->fondos->link($cobro, $recepcion, $toma, $actorId);
            $falta = Decimal::sub($falta, $toma);
            $identificado = Decimal::add($identificado, $toma);
        }

        $this->auditar->handle('arancel.credito-identificado', $cobro, metadata: [
            'amount' => $identificado,
            'falta' => $falta,
        ], actorId: $actorId);

        if (! Decimal::isNegative(Decimal::sub('0', $falta))) {
            $this->completar->handle($cobro, $signedById, $actorId);
        }
    }

    private static function clave(string $idempotencyKey, int $credito): string
    {
        return $idempotencyKey.':credito-'.$credito;
    }
}
