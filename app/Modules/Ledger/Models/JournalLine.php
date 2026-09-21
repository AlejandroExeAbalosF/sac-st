<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Models;

use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Models\Person;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una línea del libro diario — §9.4 del DER.
 *
 * Inmutable sin matices: ni se edita ni se borra. Corregir un asiento es
 * revertirlo con otro, que es como funcionan los libros contables desde
 * que existen los libros contables.
 *
 * No tiene `updated_at` a propósito. Una fila que no cambia nunca no
 * necesita registrar cuándo cambió, y la columna invitaría a creer que sí
 * puede pasar.
 *
 * @property int $id
 * @property int $financial_event_id
 * @property LedgerAccount $account_code
 * @property numeric-string $debit
 * @property numeric-string $credit
 * @property int|null $cash_box_id
 * @property int|null $bank_account_id
 * @property int|null $depositor_id
 * @property int|null $haber_id
 * @property int|null $beneficiary_installment_id
 * @property string|null $description
 * @property CarbonInterface $created_at
 */
final class JournalLine extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'financial_event_id',
        'account_code',
        'debit',
        'credit',
        'cash_box_id',
        'bank_account_id',
        'depositor_id',
        'haber_id',
        'beneficiary_installment_id',
        'description',
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

    /** @return BelongsTo<Person, $this> */
    public function depositor(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'depositor_id');
    }

    /*
     * `bank_account_id`, `haber_id` y `beneficiary_installment_id` no
     * tienen relación acá, y es la regla de fronteras: Ledger solo puede
     * apoyarse en Shared. Quien necesite resolverlos lo hace desde su
     * propio módulo, que es el que sabe qué significan.
     */

    /** El importe de la línea, sin importar de qué lado esté. */
    public function amount(): string
    {
        return $this->isDebit() ? $this->debit : $this->credit;
    }

    public function isDebit(): bool
    {
        return bccomp($this->debit, '0', 2) === 1;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'account_code' => LedgerAccount::class,
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }
}
