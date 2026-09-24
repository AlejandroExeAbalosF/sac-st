<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Http\Requests;

use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Support\BusinessDate;
use App\Support\Money\Decimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Lo que la pantalla de apertura puede mandar.
 *
 * **No lleva el contraasiento.** El total que va a `LEGACY_FUNDS` lo suma
 * el Action: si viajara desde el navegador sería un número que puede no
 * coincidir con la suma de sus partes, y el asiento quedaría sin balancear
 * por un dato que el sistema ya tiene.
 */
final class RegisterOpeningBalanceRequest extends FormRequest
{
    /**
     * El importe se normaliza antes de validarse.
     *
     * La planilla del área escribe `9.852.300,00`, y eso es lo que el
     * operador copia. Para `numeric` no es un número, así que la respuesta
     * volvía con la clave `balances.CASH_ON_HAND` —que la pantalla no
     * pintaba en ningún lado— y el botón parecía no hacer nada.
     *
     * `Decimal::parse` ya lee ese formato: es el mismo que interpreta los
     * archivos del banco. La incoherencia era validar con una regla más
     * estricta que el parser que este mismo request usa dos métodos más
     * abajo.
     *
     * **Lo que no se puede leer se deja como vino.** Normalizarlo a `null`
     * lo volvería un campo vacío y un importe mal tipeado se descartaría
     * en silencio; dejándolo crudo, `numeric` falla y el operador ve el
     * error al lado de su renglón.
     */
    protected function prepareForValidation(): void
    {
        $crudos = $this->input('balances');

        if (! is_array($crudos)) {
            return;
        }

        $normalizados = [];

        foreach ($crudos as $cuenta => $importe) {
            if (! is_string($importe)) {
                $normalizados[$cuenta] = $importe;

                continue;
            }

            $normalizados[$cuenta] = Decimal::parse($importe) ?? $importe;
        }

        $this->merge(['balances' => $normalizados]);

        /*
         * Los importes de los cheques se escriben igual que los saldos
         * —con puntos de miles y coma decimal— y `numeric` los rechazaría.
         * Lo que no se pueda interpretar queda crudo a propósito, para que
         * falle a la vista en vez de convertirse en otro número.
         */
        $cheques = $this->input('cheques');

        if (is_array($cheques)) {
            $this->merge([
                'cheques' => array_map(
                    function (mixed $cheque): mixed {
                        if (! is_array($cheque) || ! isset($cheque['amount'])) {
                            return $cheque;
                        }

                        $cheque['amount'] = Decimal::parse((string) $cheque['amount']) ?? $cheque['amount'];

                        return $cheque;
                    },
                    $cheques,
                ),
            ]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cashBoxId' => ['required', 'integer', Rule::exists('cash_boxes', 'id')->where('is_active', true)],
            'currency' => ['required', Rule::enum(Currency::class)],
            /*
             * La fecha del saldo, que es la víspera del arranque: lo que
             * hay en el cajón el día anterior al primer movimiento. No
             * puede ser futura, y tampoco tiene por qué ser hoy — la carga
             * suele hacerse unos días después de la fecha que declara.
             */
            'date' => ['required', 'date', 'before_or_equal:'.BusinessDate::today()->toDateString()],
            'balances' => ['present', 'array'],
            'balances.*' => ['nullable', 'numeric', 'min:0'],
            /*
             * Obligatoria solo si se declara saldo de depósitos directos.
             * El Action lo vuelve a exigir: acá se gana el mensaje al lado
             * del campo en vez de un error suelto arriba del formulario.
             */
            'bankAccountId' => [
                'nullable',
                'integer',
                Rule::exists('bank_accounts', 'id')->where('is_active', true),
            ],
            'notes' => ['nullable', 'string', 'max:300'],

            /*
             * Los billetes del cajón. El efectivo de la apertura sale de
             * acá: contarlo una vez es lo que le da composición al fajo que
             * después se arrastra sin recontar.
             */
            'denominations' => ['present', 'array'],
            'denominations.*' => ['integer', 'min:0', 'max:100000'],

            /*
             * La cartera de cheques, uno por renglón. Es opcional: el área
             * puede abrir declarando solo el total, y entonces el
             * inventario del reverso arranca vacío. Que la suma coincida
             * con ese total lo comprueba el Action, que es donde vive la
             * regla.
             */
            'cheques' => ['sometimes', 'array'],
            'cheques.*.number' => ['required', 'string', 'max:40'],
            'cheques.*.bank' => ['required', 'string', 'max:80'],
            'cheques.*.issueDate' => ['required', 'date', 'before_or_equal:'.BusinessDate::today()->toDateString()],
            'cheques.*.amount' => ['required', 'numeric', 'gt:0'],
            'cheques.*.expediente' => ['nullable', 'string', 'max:40'],
            'cheques.*.company' => ['nullable', 'string', 'max:160'],
            'cheques.*.beneficiary' => ['nullable', 'string', 'max:160'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        $nombres = [
            'cashBoxId' => 'caja',
            'date' => 'fecha del saldo',
            'balances' => 'saldos',
            'bankAccountId' => 'cuenta bancaria',
            'cheques' => 'cheques en cartera',
            'denominations' => 'billetes del cajón',
        ];

        /*
         * Cada renglón se nombra como lo ve el operador. Sin esto, un
         * importe mal escrito devuelve «Escribí un número en
         * balances.CASH_ON_HAND»: el código del plan de cuentas, que no
         * aparece en ninguna pantalla.
         */
        foreach (self::openableAccounts() as $cuenta) {
            $nombres['balances.'.$cuenta->value] = mb_strtolower($cuenta->label());
        }

        return $nombres;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'date.before_or_equal' => 'El saldo inicial es lo que ya está en el cajón: no puede tener fecha futura.',
        ];
    }

    /**
     * Los billetes contados, sin los renglones en cero.
     *
     * @return array<int, int>
     */
    public function denominations(): array
    {
        /** @var array<array-key, mixed> $crudas */
        $crudas = $this->validated('denominations') ?? [];
        $billetes = [];

        foreach ($crudas as $denominacion => $cantidad) {
            if ((int) $cantidad > 0) {
                $billetes[(int) $denominacion] = (int) $cantidad;
            }
        }

        return $billetes;
    }

    /**
     * @return list<array{number: string, bank: string, issueDate: string, amount: string, expediente: ?string, company: ?string, beneficiary: ?string}>
     */
    public function cheques(): array
    {
        /** @var array<array-key, array<string, mixed>> $crudos */
        $crudos = $this->validated('cheques') ?? [];
        $cartera = [];

        foreach ($crudos as $cheque) {
            $cartera[] = [
                'number' => (string) $cheque['number'],
                'bank' => (string) $cheque['bank'],
                'issueDate' => (string) $cheque['issueDate'],
                'amount' => (string) $cheque['amount'],
                'expediente' => self::opcional($cheque['expediente'] ?? null),
                'company' => self::opcional($cheque['company'] ?? null),
                'beneficiary' => self::opcional($cheque['beneficiary'] ?? null),
            ];
        }

        return $cartera;
    }

    private static function opcional(mixed $valor): ?string
    {
        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }

    /**
     * Los saldos como cadenas decimales, indexados por cuenta.
     *
     * Nunca pasan por `float`, ni siquiera para normalizarlos: la regla del
     * modelo es que el punto flotante no existe en ningún punto de la pila.
     * Los ceros se descartan acá —una cuenta sin saldo no es una pata del
     * asiento— y el Action los descartaría igual.
     *
     * @return array<string, numeric-string>
     */
    public function balances(): array
    {
        $saldos = [];

        /** @var array<array-key, mixed> $crudos */
        $crudos = $this->validated('balances') ?? [];

        foreach ($crudos as $cuenta => $importe) {
            if ($importe === null || $importe === '') {
                continue;
            }

            $escalado = Decimal::parse((string) $importe);

            if ($escalado === null || Decimal::equals($escalado, '0')) {
                continue;
            }

            $saldos[(string) $cuenta] = $escalado;
        }

        return $saldos;
    }

    /**
     * Las cuentas que la apertura admite: dónde puede estar el dinero.
     *
     * @return list<LedgerAccount>
     */
    public static function openableAccounts(): array
    {
        return array_values(array_filter(
            LedgerAccount::cases(),
            fn (LedgerAccount $cuenta): bool => $cuenta->isLocation(),
        ));
    }
}
