<?php

declare(strict_types=1);

namespace App\Modules\Shared\Models;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cuenta bancaria particular que puede utilizarse para pagar a una persona.
 *
 * @property int $id
 * @property int $person_id
 * @property string $cbu
 * @property string|null $alias
 * @property string|null $bank_name
 * @property string|null $account_number
 * @property string|null $holder_name
 * @property string|null $holder_document
 * @property string $verification_status `unverified`, `verified` o `rejected`.
 * @property string|null $rejection_reason
 * @property string|null $forced_verification_reason Si no es nulo, alguien la dio por buena salteando las guardas.
 * @property string|null $forced_verification_bypass `checksum`, `virtual_wallet` o las dos.
 * @property int|null $verified_by
 * @property CarbonInterface|null $verified_at
 * @property CarbonInterface|null $valid_from
 * @property CarbonInterface|null $valid_to
 * @property bool $is_active
 */
final class PersonBankAccount extends Model
{
    protected $fillable = [
        'person_id',
        'cbu',
        'alias',
        'bank_name',
        'account_number',
        'holder_name',
        'holder_document',
        'verification_status',
        'rejection_reason',
        'forced_verification_reason',
        'forced_verification_bypass',
        'verified_by',
        'verified_at',
        'valid_from',
        'valid_to',
        'is_active',
    ];

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** @return BelongsTo<User, $this> */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** @return array{id:int,cbu:string,verificationStatus:string,isActive:bool} */
    public function toOption(): array
    {
        return [
            'id' => $this->id,
            'cbu' => $this->cbu,
            'verificationStatus' => $this->verification_status,
            'isActive' => $this->is_active,
        ];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
