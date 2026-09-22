<?php

declare(strict_types=1);

namespace App\Modules\Banking\Audit\Subjects;

use App\Models\User;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Shared\Audit\AuditSubjectDescription;
use App\Modules\Shared\Audit\BaseAuditSubjectResolver;

final class BankAccountSubject extends BaseAuditSubjectResolver
{
    public function subjectType(): string
    {
        return 'BankAccount';
    }

    public function label(): string
    {
        return 'Cuenta del organismo';
    }

    public function fields(): array
    {
        return [
            'label' => 'Nombre',
            'bank_name' => 'Banco',
            'account_number' => 'Número de cuenta',
            'cbu' => 'CBU',
            'alias' => 'Alias',
            'currency' => 'Moneda',
            'is_active' => 'Activa',
        ];
    }

    public function describe(array $ids, ?User $viewer): array
    {
        $url = $this->can($viewer, 'banco.cuentas.ver') ? route('banco.cuentas.index') : null;

        $descripciones = [];

        foreach (BankAccount::query()->whereIn('id', $ids)->get(['id', 'label']) as $cuenta) {
            $descripciones[$cuenta->id] = new AuditSubjectDescription($cuenta->label, $url);
        }

        return $descripciones;
    }
}
