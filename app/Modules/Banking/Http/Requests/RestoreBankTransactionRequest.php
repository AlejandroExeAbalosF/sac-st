<?php

declare(strict_types=1);

namespace App\Modules\Banking\Http\Requests;

use App\Modules\Banking\Enums\BankingReasonAct;
use App\Modules\Shared\Support\Reason;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/** Reincorporar un movimiento exige el motivo, de la lista del acto. */
final class RestoreBankTransactionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return Reason::rules(BankingReasonAct::Restore, max: 300);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return Reason::messages();
    }

    public function reason(): Reason
    {
        return Reason::fromValidated($this->validated(), BankingReasonAct::Restore)
            ?? throw new LogicException('El motivo es obligatorio y ya se validó.');
    }
}
