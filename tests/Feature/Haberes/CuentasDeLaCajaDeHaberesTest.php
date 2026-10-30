<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Banking\Actions\LinkBankAccountToCashBox;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\CashBoxBankAccount;
use App\Modules\Banking\Models\CashToBankTransfer;
use App\Modules\Haberes\Actions\CollectAndIssueReceipt;
use App\Modules\Haberes\Actions\DepositCashToBank;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Ledger\Actions\RegisterOpeningBalance;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Shared\Models\CashBox;
use Carbon\CarbonImmutable;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * La caja de Haberes opera con sus cuentas habilitadas — ver
 * `docs/haberes-reglas.md#cuentas-de-la-caja`.
 *
 * Sin ninguna habilitada no se controla, como operaba antes: eso lo cubre
 * el resto de la suite de Haberes, que no crea vínculos.
 */
class CuentasDeLaCajaDeHaberesTest extends TestCase
{
    use RefreshDatabase;

    private BankAccount $a;

    private BankAccount $b;

    private BankAccount $ajena;

    protected function setUp(): void
    {
        parent::setUp();

        $this->abrirLibrosSinSaldo();
        $this->seed(HaberesDemoSeeder::class);

        $this->a = $this->cuentaNueva('310000000000001', 'Cta. Cte. A — Haberes');
        $this->b = $this->cuentaNueva('310000000000002', 'Cta. Cte. B — Haberes');
        $this->ajena = $this->cuentaNueva('310000000000003', 'Cta. Cte. de otra caja');

        $habilitar = app(LinkBankAccountToCashBox::class);
        $habilitar->handle($this->caja(), $this->a->id, null, true, $this->quienAbre());
        $habilitar->handle($this->caja(), $this->b->id, CarbonImmutable::parse('2026-10-01'), false, $this->quienAbre());
    }

    /** Depositar ofrece solo las vigentes hoy, la predeterminada incluida. */
    public function test_the_deposit_screen_offers_only_the_linked_accounts(): void
    {
        $this->actingAs($this->operador())
            ->get(route('haberes.installments.transfer.create', $this->cuotaCobrada()))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('haberes/traslados/create')
                ->has('accounts', 2)
                ->where('accounts', fn ($cuentas): bool => collect($cuentas)->pluck('id')->sort()->values()->all() === [$this->a->id, $this->b->id]),
            );
    }

    /** El Action rechaza una cuenta que no es de la caja, o que no valía ese día. */
    public function test_depositing_needs_an_account_valid_on_the_deposit_date(): void
    {
        $cuota = $this->cuotaCobrada();

        $this->assertRechazo(fn () => $this->depositar($cuota, $this->ajena, '2026-08-25'));
        // B empieza el 01/10: un depósito de agosto no puede ir ahí.
        $this->assertRechazo(fn () => $this->depositar($cuota, $this->b, '2026-08-25'));
        $this->assertSame(0, CashToBankTransfer::query()->count());

        $this->depositar($cuota, $this->a, '2026-08-25');
        $this->assertSame($this->a->id, CashToBankTransfer::query()->sole()->bank_account_id);
    }

    /** Un comprobante de depósito tiene que ser de una cuenta de la caja vigente ese día. */
    public function test_a_deposit_ticket_needs_a_linked_account(): void
    {
        $cuota = $this->cuota();

        $this->actingAs($this->operador())
            ->post(route('haberes.installments.ticket', $cuota), $this->comprobante($this->ajena, '2026-04-24'))
            ->assertSessionHasErrors(['bankAccountId' => 'Esa cuenta no está habilitada para la caja de Haberes en la fecha del depósito.']);

        $this->actingAs($this->operador())
            ->post(route('haberes.installments.ticket', $cuota), $this->comprobante($this->b, '2026-04-24'))
            ->assertSessionHasErrors('bankAccountId');

        $this->actingAs($this->operador())
            ->post(route('haberes.installments.ticket', $cuota), $this->comprobante($this->a, '2026-04-24'))
            ->assertSessionHasNoErrors();
    }

    /**
     * Desactivar una cuenta en Banco corta los extractos nuevos, no la
     * vigencia en la caja: el formulario la ofrece y el comprobante se guarda.
     */
    public function test_an_account_inactive_for_imports_still_takes_tickets_while_linked(): void
    {
        $this->a->forceFill(['is_active' => false])->save();
        $cuota = $this->cuota();

        $this->actingAs($this->operador())
            ->get(route('haberes.installments.ticket.create', $cuota))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('accounts', fn ($cuentas): bool => collect($cuentas)->contains('id', $this->a->id)));

        $this->actingAs($this->operador())
            ->post(route('haberes.installments.ticket', $cuota), $this->comprobante($this->a, '2026-04-24'))
            ->assertSessionHasNoErrors();
    }

    /**
     * Abrir un libro con su cuenta la habilita para la caja, predeterminada
     * y sin inicio, igual que en Multas; abrir sin cuenta no habilita nada.
     */
    public function test_opening_a_book_with_an_account_links_it(): void
    {
        $dolares = BankAccount::query()->create([
            'account_number' => '310000000000999', 'label' => 'Cta. en dólares', 'bank_name' => 'Banco Macro',
            'currency' => 'USD', 'is_active' => true,
        ]);

        app(RegisterOpeningBalance::class)->handle(
            cashBoxId: $this->caja(),
            balances: [],
            date: CarbonImmutable::parse('2026-10-01'),
            actorId: $this->quienAbre(),
            currency: Currency::Usd,
            bankAccountId: $dolares->id,
            declaredEmpty: true,
        );

        $vinculo = CashBoxBankAccount::query()->where('bank_account_id', $dolares->id)->sole();
        expect($vinculo->cash_box_id)->toBe($this->caja())
            ->and($vinculo->currency)->toBe('USD')
            ->and($vinculo->valid_from)->toBeNull()
            ->and($vinculo->is_default)->toBeTrue()
            // La apertura en pesos, sin cuenta, no dejó vínculo: los dos de pesos son los del escenario.
            ->and(CashBoxBankAccount::query()->where('currency', 'ARS')->count())->toBe(2);
    }

    /** Administrar las cuentas de Haberes es del administrador; mirarlas, de quien ve la caja. */
    public function test_only_the_administrator_manages_the_accounts(): void
    {
        $this->actingAs($this->operador())
            ->get(route('caja.cuentas.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('caja/cuentas')
                ->has('accounts', 2)
                ->where('can.manage', false),
            );

        $this->actingAs($this->operador())
            ->post(route('caja.cuentas.store'), ['bankAccountId' => $this->ajena->id])
            ->assertForbidden();

        $this->actingAs($this->operador('administrador'))
            ->post(route('caja.cuentas.store'), ['bankAccountId' => $this->ajena->id])
            ->assertSessionHasNoErrors();
    }

    private function assertRechazo(callable $accion): void
    {
        try {
            $accion();
            $this->fail('El depósito tenía que rechazarse.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('bankAccountId', $e->errors());
        }
    }

    private function depositar(BeneficiaryInstallment $cuota, BankAccount $cuenta, string $fecha): CashToBankTransfer
    {
        return app(DepositCashToBank::class)->handle(
            installment: $cuota,
            ticket: [
                'bankAccountId' => $cuenta->id,
                'depositDate' => CarbonImmutable::parse($fecha),
                'depositTime' => '10:35:00',
                'operationNumber' => '995979830',
                'terminal' => null,
                'notes' => null,
            ],
            foto: UploadedFile::fake()->image('ticket.jpg'),
            idempotencyKey: 'traslado-'.Str::random(8),
            actorId: $this->operador()->id,
        );
    }

    /** @return array<string, mixed> */
    private function comprobante(BankAccount $cuenta, string $fecha): array
    {
        return [
            'bankAccountId' => $cuenta->id,
            'depositedAt' => $fecha,
            'depositedTime' => '09:36',
            'operationNumber' => (string) random_int(1000000, 9999999),
            'photo' => UploadedFile::fake()->image('ticket.jpg'),
        ];
    }

    private function cuotaCobrada(): BeneficiaryInstallment
    {
        $cuota = $this->cuota();
        $cuota->forceFill(['expected_medium' => 'cash'])->save();

        app(CollectAndIssueReceipt::class)->handle(
            installment: $cuota->refresh(),
            idempotencyKey: 'cobro-'.Str::random(8),
            actorId: $this->operador()->id,
        );

        return $cuota->refresh();
    }

    private function cuota(): BeneficiaryInstallment
    {
        return Expediente::query()
            ->whereNotNull('employer_id')
            ->orderBy('id')
            ->firstOrFail()
            ->haberes()
            ->orderBy('id')
            ->firstOrFail()
            ->installments()
            ->orderBy('installment_number')
            ->firstOrFail();
    }

    private function cuentaNueva(string $numero, string $nombre): BankAccount
    {
        return BankAccount::query()->create([
            'account_number' => $numero,
            'label' => $nombre,
            'bank_name' => 'Banco Macro',
            'currency' => 'ARS',
            'is_active' => true,
        ]);
    }

    private function caja(): int
    {
        return (int) CashBox::query()->where('code', CashBox::HABERES)->value('id');
    }
}
