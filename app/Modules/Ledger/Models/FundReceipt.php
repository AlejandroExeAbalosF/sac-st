<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Models;

use App\Models\User;
use App\Modules\Ledger\Enums\ChequeStatus;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Enums\ResidualStatus;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Models\Person;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una recepción de fondos — §9.4 del DER.
 *
 * El dinero entró y todavía no tiene dueño. Es la cara legible del evento
 * financiero que la asienta, y con él es uno a uno.
 *
 * **No sabe cuánto le queda sin asignar.** Ese importe se calcula
 * restándole sus asignaciones, y las asignaciones viven en Haberes —Ledger
 * no puede verlas—. Quien necesite el remanente lo pide desde ahí, que es
 * el módulo que sabe a qué se asignó.
 *
 * @property int $id
 * @property int $financial_event_id
 * @property int|null $cash_box_id
 * @property int|null $depositor_id
 * @property PaymentMedium $medium
 * @property numeric-string $amount
 * @property CarbonInterface $received_date
 * @property string|null $cheque_number
 * @property string|null $cheque_bank
 * @property CarbonInterface|null $cheque_issue_date
 * @property ChequeStatus|null $cheque_status
 * @property ResidualStatus $residual_status Siempre `Open`: nada escribe el otro valor todavía (ver el enum).
 * @property string|null $residual_note
 * @property int|null $residual_acknowledged_by
 * @property CarbonInterface|null $residual_acknowledged_at
 * @property int|null $received_by
 * @property string|null $notes
 * @property int|null $reversal_event_id El asiento que la deshizo.
 * @property CarbonInterface|null $reversed_at
 * @property int|null $reversed_by
 * @property string|null $reversal_reason
 */
final class FundReceipt extends Model
{
    protected $fillable = [
        'financial_event_id',
        'cash_box_id',
        'depositor_id',
        'medium',
        /*
         * La escribe la apertura, que es la única que sabe en qué libro
         * está parada. El resto de las recepciones todavía son de pesos por
         * construcción --sus Actions no reciben moneda-- y dependen del
         * default de la columna.
         */
        'currency',
        'amount',
        'received_date',
        'cheque_number',
        'cheque_bank',
        'cheque_issue_date',
        'cheque_status',
        /*
         * Lo que decía el papel de un cheque cargado en la apertura.
         * Snapshots, no vínculos: cuando el cheque se impute a una cuota
         * real manda el snapshot del recibo.
         */
        'expediente_number_snapshot',
        'counterparty_name_snapshot',
        'beneficiary_name_snapshot',
        'residual_status',
        'residual_note',
        'residual_acknowledged_by',
        'residual_acknowledged_at',
        'received_by',
        'notes',
    ];

    /** @return BelongsTo<FinancialEvent, $this> */
    public function financialEvent(): BelongsTo
    {
        return $this->belongsTo(FinancialEvent::class);
    }

    /** @return BelongsTo<CashBox, $this> */
    public function cashBox(): BelongsTo
    {
        return $this->belongsTo(CashBox::class);
    }

    /** Quién puso el dinero, cuando se lo pudo identificar. */
    /** @return BelongsTo<Person, $this> */
    public function depositor(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'depositor_id');
    }

    /**
     * Quién la revirtió, cuando se revirtió.
     *
     * @return BelongsTo<User, $this>
     */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * Los cheques que el organismo todavía tiene.
     *
     * Es el inventario del reverso de la planilla de caja, sin tabla de
     * arqueo propia.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeChequesInCustody(Builder $query): Builder
    {
        return $query->where('cheque_status', ChequeStatus::InCustody);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'medium' => PaymentMedium::class,
            'cheque_status' => ChequeStatus::class,
            'residual_status' => ResidualStatus::class,
            'amount' => 'decimal:2',
            'received_date' => 'date',
            'reversed_at' => 'datetime',
            'cheque_issue_date' => 'date',
            'residual_acknowledged_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
