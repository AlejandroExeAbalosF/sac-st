<?php

declare(strict_types=1);

namespace App\Modules\Banking\Data;

use App\Modules\Banking\Models\BankAccount;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Una cuenta del organismo, tal como la ve la pantalla.
 *
 * Los tres últimos campos no son columnas: los agrega la consulta del
 * controlador con `withCount` y `withMax`. Contar los extractos en el
 * listado evita el pedido por fila que haría falta para mostrarlos.
 */
#[TypeScript]
final class BankAccountData extends Data
{
    public function __construct(
        public int $id,
        public string $label,
        public string $bankName,
        public ?string $accountNumber,
        public ?string $cbu,
        public ?string $alias,
        public string $currency,
        public bool $isActive,
        public int $importCount,
        /**
         * Último saldo que informó un extracto. Es el saldo del banco, no
         * el del libro: no incluye lo que el sistema sabe y el banco
         * todavía no vio.
         *
         * @var numeric-string|null
         */
        public ?string $lastKnownBalance,
        public ?string $lastImportedAt,
    ) {}

    public static function fromModel(BankAccount $account): self
    {
        $importedAt = self::aggregate($account, 'last_imported_at');

        return new self(
            id: $account->id,
            label: $account->label,
            bankName: $account->bank_name,
            accountNumber: $account->account_number,
            cbu: $account->cbu,
            alias: $account->alias,
            currency: $account->currency,
            isActive: $account->is_active,
            importCount: (int) (self::aggregate($account, 'imports_count') ?? 0),
            lastKnownBalance: self::amount(self::aggregate($account, 'last_known_balance')),
            lastImportedAt: $importedAt instanceof CarbonInterface
                ? $importedAt->toIso8601String()
                : (is_string($importedAt) ? $importedAt : null),
        );
    }

    /**
     * Lee un agregado de la consulta sin exigir que esté.
     *
     * `getAttribute()` no sirve acá: el modelo corre con
     * `preventAccessingMissingAttributes`, así que pedir un `withCount` que
     * la consulta no hizo revienta con una excepción. Y no siempre se
     * hace: el selector de cuentas de la pantalla de importación no
     * necesita contar extractos para ofrecer una lista.
     *
     * La alternativa —repetir `withCount` en cada consulta que termine
     * acá— es la que se olvida el día que alguien agrega la cuarta.
     */
    private static function aggregate(BankAccount $account, string $key): mixed
    {
        return $account->getAttributes()[$key] ?? null;
    }

    /**
     * @return numeric-string|null
     */
    private static function amount(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = (string) $value;

        return is_numeric($text) ? $text : null;
    }
}
