<?php

declare(strict_types=1);

namespace Tests\Feature\Aranceles;

use App\Models\User;
use App\Modules\Aranceles\Actions\AddFeeTypePrice;
use App\Modules\Aranceles\Actions\CollectFeeInCash;
use App\Modules\Aranceles\Actions\CorrectFeeCharge;
use App\Modules\Aranceles\Actions\OpenFeeCashBook;
use App\Modules\Aranceles\Actions\ReplaceFeeReceipt;
use App\Modules\Aranceles\Actions\VoidFeeCollection;
use App\Modules\Aranceles\Actions\VoidFeeTypePrice;
use App\Modules\Aranceles\Enums\FeeChargeStatus;
use App\Modules\Aranceles\Enums\FeeReasonCode;
use App\Modules\Aranceles\Models\FeeCharge;
use App\Modules\Aranceles\Models\FeeChargeBalance;
use App\Modules\Aranceles\Models\FeeChargeCorrection;
use App\Modules\Aranceles\Models\FeeChargeFunding;
use App\Modules\Aranceles\Models\FeeChargeReceipt;
use App\Modules\Aranceles\Models\FeeType;
use App\Modules\Aranceles\Pdf\FeeReceiptPdf;
use App\Modules\Aranceles\Support\FeeChargeFunds;
use App\Modules\Banking\Actions\ConfirmCashDepositCredit;
use App\Modules\Banking\Actions\RecordTransferTicket;
use App\Modules\Banking\Actions\StartCashTransfer;
use App\Modules\Banking\Enums\CashTransferStatus;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Banking\Models\CashToBankTransferItem;
use App\Modules\Ledger\Actions\ClosePeriod;
use App\Modules\Ledger\Actions\PostJournalEntry;
use App\Modules\Ledger\Actions\RecordCashCount;
use App\Modules\Ledger\Actions\RegisterCashFundReceipt;
use App\Modules\Ledger\Actions\ReviewCashCount;
use App\Modules\Ledger\Enums\Currency;
use App\Modules\Ledger\Enums\FinancialEventType;
use App\Modules\Ledger\Enums\FundReceiptOrigin;
use App\Modules\Ledger\Enums\LedgerAccount;
use App\Modules\Ledger\Enums\PaymentMedium;
use App\Modules\Ledger\Models\FundReceipt;
use App\Modules\Ledger\Models\PeriodClosing;
use App\Modules\Ledger\Support\CashBalance;
use App\Modules\Ledger\Support\CashDayBook;
use App\Modules\Ledger\Support\CashDayTakings;
use App\Modules\Ledger\Support\EntryLine;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Enums\ReceiptStatus;
use App\Modules\Shared\Models\Attachment;
use App\Modules\Shared\Models\AuditEvent;
use App\Modules\Shared\Models\CashBox;
use App\Modules\Shared\Support\Reason;
use App\Support\BusinessDate;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\BuildsSharedBankAccount;
use Tests\Concerns\CollectsInstallments;
use Tests\TestCase;

/**
 * El cobro en efectivo, su recibo y lo que se le puede hacer después: anularlo,
 * reemplazar el recibo y corregirlo (reglas A20 a A95), en el Action y en la base.
 */
class CobroDeArancelesTest extends TestCase
{
    use BuildsSharedBankAccount;
    use CollectsInstallments {
        cuota as cuotaDeHaberes;
        cobrar as cobrarCuota;
    }
    use RefreshDatabase;

    private User $admin;

    private int $caja;

    private int $clave = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->operador('administrador');
        $this->caja = (int) CashBox::query()->where('code', CashBox::ARANCELES)->value('id');
    }

    protected function quienAbre(): int
    {
        return $this->admin->id;
    }

    private function abrir(string $efectivo = '0'): void
    {
        $hayEfectivo = ! Decimal::equals($efectivo, '0');

        app(OpenFeeCashBook::class)->handle(
            date: BusinessDate::today()->subDays(10),
            bankAccountId: $this->cuenta()->id,
            balances: $hayEfectivo ? [LedgerAccount::CashOnHand->value => $efectivo] : [],
            denominations: $hayEfectivo ? $this->billetesPara($efectivo) : [],
            notes: null,
            declaredEmpty: ! $hayEfectivo,
            actorId: $this->admin->id,
        );
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function linea(int $codigo, array $extra = []): array
    {
        return ['feeTypeId' => FeeType::query()->where('code', $codigo)->value('id'), 'quantity' => 1, ...$extra];
    }

    /** @param list<array<string, mixed>>|null $lineas */
    private function cobrar(?array $lineas = null, ?CarbonImmutable $fecha = null, ?string $talonario = null, bool $puede = true): FeeCharge
    {
        return app(CollectFeeInCash::class)->handle(
            'AG Max SRL',
            null,
            $lineas ?? [$this->linea(14), $this->linea(25, ['quantity' => 2])],
            'clave-'.(++$this->clave),
            $this->admin->id,
            $fecha,
            $talonario,
            null,
            $puede,
        );
    }

    private function total(FeeCharge $cobro): string
    {
        return (string) FeeChargeBalance::query()->whereKey($cobro->id)->value('total');
    }

    private function efectivo(): string
    {
        return Decimal::scale(app(CashBalance::class)->of(LedgerAccount::CashOnHand, $this->caja, Currency::Ars));
    }

    private function motivo(FeeReasonCode $codigo = FeeReasonCode::DataEntryError, ?string $detalle = null): Reason
    {
        return new Reason($codigo, $detalle);
    }

    /** @param callable(): mixed $escritura */
    private function assertLaBaseRechaza(callable $escritura, string $mensaje): void
    {
        try {
            DB::transaction(function () use ($escritura): void {
                $escritura();
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
        } catch (QueryException $e) {
            $this->assertStringContainsString($mensaje, $e->getMessage());

            return;
        }

        $this->fail('La base tenía que rechazar la escritura: '.$mensaje);
    }

    /** @param callable(): mixed $accion */
    private function assertRechazaEn(string $campo, callable $accion, ?string $mensaje = null): void
    {
        try {
            $accion();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($campo, $e->errors());

            if ($mensaje !== null) {
                $this->assertStringContainsString($mensaje, $e->errors()[$campo][0]);
            }

            return;
        }

        $this->fail("Tenía que rechazar en {$campo}.");
    }

    private function depositoDe(FundReceipt $recepcion, string $importe, CashTransferStatus $estado = CashTransferStatus::Deposited): void
    {
        Storage::fake('local');

        $traslado = app(StartCashTransfer::class)->handle(
            $this->caja, Currency::Ars, $importe, BusinessDate::today(), null, null,
            'deposito-'.$recepcion->id.'-'.(++$this->clave), $this->admin->id,
        );

        $this->assertSame($recepcion->id, CashToBankTransferItem::query()->where('cash_to_bank_transfer_id', $traslado->id)->value('fund_receipt_id'));

        $traslado = app(RecordTransferTicket::class)->handle(
            $traslado, $importe, ['depositDate' => BusinessDate::today()], UploadedFile::fake()->image('ticket.jpg'),
            'comprobante-'.$traslado->id, $this->admin->id,
        );

        if ($estado === CashTransferStatus::BankConfirmed) {
            $credito = $this->movimiento(BankAccount::query()->findOrFail($traslado->bank_account_id), BusinessDate::today()->toDateString(), $importe);

            app(ConfirmCashDepositCredit::class)->handle($traslado, $credito, 'acreditacion-'.$traslado->id, $this->admin->id);
        }
    }

    private function recepcionDe(FeeCharge $cobro): FundReceipt
    {
        return FundReceipt::query()->findOrFail(FeeChargeFunding::query()->where('fee_charge_id', $cobro->id)->whereNull('released_at')->value('fund_receipt_id'));
    }

    // ── La caja y su apertura ─────────────────────────────

    public function test_the_opening_cash_gets_a_receipt_without_counting_as_income(): void
    {
        $this->abrir('1000');

        $recepcion = FundReceipt::query()->where('cash_box_id', $this->caja)->firstOrFail();

        $this->assertSame(FundReceiptOrigin::Opening, $recepcion->origin);
        $this->assertSame('1000.00', $recepcion->amount);
        $this->assertSame('1000.00', $this->efectivo());
        $this->assertSame('0.00', Decimal::scale((string) app(CashDayTakings::class)->of($this->caja, BusinessDate::today()->subDays(10), Currency::Ars)));
    }

    public function test_an_empty_opening_needs_no_receipt(): void
    {
        $this->abrir();

        $this->assertSame(0, FundReceipt::query()->where('cash_box_id', $this->caja)->count());
    }

    public function test_the_opening_does_not_take_a_bank_balance(): void
    {
        $this->assertRechazaEn('balances', fn () => app(OpenFeeCashBook::class)->handle(
            date: BusinessDate::today(),
            bankAccountId: $this->cuenta()->id,
            balances: [LedgerAccount::BankAccount->value => '100'],
            denominations: [],
            notes: null,
            declaredEmpty: false,
            actorId: $this->admin->id,
        ));
    }

    public function test_opening_cash_does_not_back_a_charge(): void
    {
        $this->abrir('1000');
        $cobro = $this->cobrar();
        $apertura = FundReceipt::query()->where('cash_box_id', $this->caja)->where('origin', FundReceiptOrigin::Opening)->firstOrFail();

        $this->assertLaBaseRechaza(fn () => FeeChargeFunding::query()->create([
            'fee_charge_id' => $cobro->id,
            'fund_receipt_id' => $apertura->id,
            'amount' => '1',
        ]), 'no respalda este cobro');
    }

    public function test_the_day_screens_open_with_the_box_of_aranceles(): void
    {
        $this->abrir();

        foreach (['aranceles.caja.dia', 'aranceles.caja.arqueos.index', 'aranceles.caja.cierres.index', 'aranceles.caja.calendario', 'aranceles.caja.apertura.index', 'aranceles.caja.cuentas.index'] as $ruta) {
            $this->actingAs($this->admin)->get(route($ruta))->assertOk();
        }
    }

    // ── El cobro ──────────────────────────────────────────

    public function test_collecting_creates_the_charge_money_and_receipt_in_one_act(): void
    {
        $this->abrir();
        $cobro = $this->cobrar();

        $this->assertSame(FeeChargeStatus::Paid, $cobro->status);
        $this->assertSame('220.00', $this->total($cobro));
        $this->assertSame('220.00', $this->efectivo());
        $this->assertSame('220.00', Decimal::scale(app(CashBalance::class)->of(LedgerAccount::CollectedFunds, $this->caja, Currency::Ars)));

        $comprobante = FeeChargeReceipt::query()->where('fee_charge_id', $cobro->id)->with('receipt')->firstOrFail();

        $this->assertStringStartsWith('0110', $comprobante->receipt->formatted_number);
        $this->assertSame('AG Max SRL', $comprobante->receipt->counterparty_name_snapshot);
        $this->assertSame('220.00', $comprobante->receipt->amount);
        $this->assertCount(2, $comprobante->snapshot['lines']);
        $this->assertTrue(AuditEvent::query()->where('action', 'arancel.cobrado')->where('subject_id', $cobro->id)->exists());
    }

    public function test_a_repeated_click_returns_the_same_charge(): void
    {
        $this->abrir();
        $cobrar = app(CollectFeeInCash::class);
        $linea = [$this->linea(14)];

        $uno = $cobrar->handle('Quien', null, $linea, 'misma', $this->admin->id, null, null, null, true);
        $dos = $cobrar->handle('Quien', null, $linea, 'misma', $this->admin->id, null, null, null, true);

        $this->assertSame($uno->id, $dos->id);
        $this->assertSame('120.00', $this->efectivo());
    }

    public function test_it_does_not_collect_with_the_box_closed_to_begin_with(): void
    {
        $this->assertRechazaEn('items', fn () => $this->cobrar(), 'todavía no se abrió');
    }

    /** @param array<string, mixed> $extra @return TestResponse<Response> */
    private function cobrarPorPantalla(array $extra): TestResponse
    {
        return $this->actingAs($this->admin)->post(route('aranceles.cobros.store'), [
            'payerName' => 'AG Max SRL',
            'idempotencyKey' => 'con-foto-'.(++$this->clave),
            'items' => [$this->linea(14)],
            ...$extra,
        ]);
    }

    public function test_the_talonario_photo_is_stored_with_the_receipt_and_survives_its_replacement(): void
    {
        Storage::fake('local');
        $this->abrir();

        $this->cobrarPorPantalla([
            'talonarioNumber' => '00686701',
            'talonarioPhoto' => UploadedFile::fake()->image('hoja.jpg'),
        ])->assertSessionHasNoErrors();

        $cobro = FeeCharge::query()->sole();
        $recibo = (int) FeeChargeReceipt::query()->where('fee_charge_id', $cobro->id)->value('receipt_id');
        $foto = Attachment::query()->forSubject(AttachmentSubject::Receipt, $recibo)->sole();

        $this->assertSame('talonario_photo', $foto->document_type);
        $this->assertSame('Foto del talonario N.º 00686701', $foto->title);
        Storage::disk($foto->storage_disk)->assertExists($foto->object_key);

        app(ReplaceFeeReceipt::class)->handle($cobro, 'AG MAX S.R.L.', null, [], $this->motivo(FeeReasonCode::WrongPayer), null, $this->admin->id);

        $this->actingAs($this->admin)->get(route('aranceles.cobros.index'))
            ->assertInertia(fn (Assert $p) => $p->where('charges.data.0.talonarioPhotoId', $foto->id));
    }

    public function test_a_talonario_photo_requires_its_number_on_both_sides(): void
    {
        Storage::fake('local');
        $this->abrir();

        $this->cobrarPorPantalla(['talonarioPhoto' => UploadedFile::fake()->image('sin-numero.jpg')])
            ->assertSessionHasErrors('talonarioNumber');

        $this->assertRechazaEn('talonarioNumber', fn () => app(CollectFeeInCash::class)->handle(
            'AG Max SRL', null, [$this->linea(14)], 'sin-numero', $this->admin->id, null, null, null, true,
            UploadedFile::fake()->image('sin-numero.jpg'),
        ));

        $this->assertSame(0, FeeCharge::query()->count());
        $this->assertSame(0, Attachment::query()->count());
        Storage::disk('local')->assertDirectoryEmpty('/');
    }

    public function test_a_talonario_photo_must_be_a_scanned_document(): void
    {
        Storage::fake('local');
        $this->abrir();

        $this->cobrarPorPantalla([
            'talonarioNumber' => '00686701',
            'talonarioPhoto' => UploadedFile::fake()->create('planilla.xlsx', 10, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ])->assertSessionHasErrors('talonarioPhoto');

        $this->assertSame(0, FeeCharge::query()->count());
    }

    public function test_the_talonario_photo_is_deleted_if_the_charge_rolls_back(): void
    {
        Storage::fake('local');
        $this->abrir();

        AuditEvent::creating(function (AuditEvent $evento): void {
            if ($evento->action === 'arancel.cobrado') {
                throw new RuntimeException('Falla después de guardar la foto.');
            }
        });

        try {
            app(CollectFeeInCash::class)->handle(
                'AG Max SRL', null, [$this->linea(14)], 'vuelve-atras', $this->admin->id, null, '00686701', null, true,
                UploadedFile::fake()->image('hoja.jpg'),
            );
            $this->fail('El cobro tenía que fallar.');
        } catch (RuntimeException $e) {
            $this->assertSame('Falla después de guardar la foto.', $e->getMessage());
        }

        $this->assertSame(0, FeeCharge::query()->count());
        $this->assertSame(0, Attachment::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_damaged_photo_is_marked_on_its_field_and_nothing_is_written(): void
    {
        Storage::fake('local');
        $this->abrir();

        $this->cobrarPorPantalla([
            'talonarioNumber' => '00686701',
            'talonarioPhoto' => $this->pngDanado(),
        ])->assertSessionHasErrors([
            'talonarioPhoto' => 'El archivo no se puede abrir: está dañado o incompleto. Quitalo o elegí otro.',
        ]);

        $this->assertSame(0, FeeCharge::query()->count());
        $this->assertSame(0, Attachment::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());

        // Otro archivo, con el mismo intento: el error no deja nada trabado.
        $this->cobrarPorPantalla([
            'talonarioNumber' => '00686701',
            'talonarioPhoto' => UploadedFile::fake()->image('otra.jpg'),
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, FeeCharge::query()->count());
    }

    /** Cabecera válida y datos rotos: `getimagesize()` lo acepta, decodificarlo no. */
    private function pngDanado(): UploadedFile
    {
        $imagen = imagecreatetruecolor(200, 200);
        ob_start();
        imagepng($imagen);
        $png = (string) ob_get_clean();
        $datos = (int) strpos($png, 'IDAT') + 8;

        $ruta = (string) tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($ruta, substr($png, 0, $datos).str_repeat("\xAA", strlen($png) - $datos));
        $this->assertNotFalse(getimagesize($ruta));

        return new UploadedFile($ruta, 'hoja.png', 'image/png', null, true);
    }

    public function test_the_attachment_links_are_offered_only_to_who_can_open_them(): void
    {
        $sinPermiso = $this->operador('consulta');
        $sinPermiso->syncRoles([]);
        $sinPermiso->givePermissionTo('aranceles.ver');

        foreach (['aranceles.cobros.index', 'aranceles.depositos.index'] as $pantalla) {
            $this->actingAs($sinPermiso)->get(route($pantalla))
                ->assertInertia(fn (Assert $p) => $p->where('can.viewAttachments', false));
            $this->actingAs($this->admin)->get(route($pantalla))
                ->assertInertia(fn (Assert $p) => $p->where('can.viewAttachments', true));
        }
    }

    public function test_the_list_does_not_query_per_charge(): void
    {
        Storage::fake('local');
        $this->abrir();

        $consultas = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->admin)->get(route('aranceles.cobros.index'))->assertOk();
            $cuantas = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $cuantas;
        };

        // La primera petición además carga los permisos, que quedan en caché.
        $consultas();

        $this->cobrar([$this->linea(14)]);
        $conUno = $consultas();

        foreach (range(1, 5) as $_) {
            $this->cobrar([$this->linea(14)]);
        }

        $this->assertSame($conUno, $consultas());
    }

    public function test_the_rows_keep_the_data_of_each_charge(): void
    {
        Storage::fake('local');
        $this->abrir();

        $this->cobrarPorPantalla([
            'payerName' => 'Con foto',
            'talonarioNumber' => '00686701',
            'talonarioPhoto' => UploadedFile::fake()->image('hoja.jpg'),
            'items' => [$this->linea(14)],
        ])->assertSessionHasNoErrors();
        $this->cobrarPorPantalla(['payerName' => 'Sin foto', 'items' => [$this->linea(25, ['quantity' => 2])]])->assertSessionHasNoErrors();

        $foto = Attachment::query()->sole()->id;

        $this->actingAs($this->admin)->get(route('aranceles.cobros.index'))
            ->assertInertia(fn (Assert $p) => $p
                ->where('charges.data.0.payerName', 'Sin foto')
                ->where('charges.data.0.total', '100.00')
                ->where('charges.data.0.lines.0.amount', '100.00')
                ->where('charges.data.0.talonarioPhotoId', null)
                ->where('charges.data.1.payerName', 'Con foto')
                ->where('charges.data.1.total', '120.00')
                ->where('charges.data.1.lines.0.amount', '120.00')
                ->where('charges.data.1.talonarioPhotoId', $foto));
    }

    public function test_a_previous_day_needs_a_talonario_and_uses_that_days_price(): void
    {
        $this->abrir();
        $hoy = BusinessDate::today();
        $ayer = $hoy->subDay();

        app(AddFeeTypePrice::class)->handle(FeeType::query()->where('code', 14)->firstOrFail(), $hoy, '500', null, $this->admin->id);

        $this->assertRechazaEn('talonarioNumber', fn () => $this->cobrar([$this->linea(14)], $ayer));

        $viejo = $this->cobrar([$this->linea(14)], $ayer, '00686701');
        $nuevo = $this->cobrar([$this->linea(14)]);

        $this->assertSame('120.00', $this->total($viejo));
        $this->assertSame($ayer->toDateString(), $viejo->pricing_date->toDateString());
        $this->assertSame('500.00', $this->total($nuevo));

        $this->assertRechazaEn('talonarioNumber', fn () => $this->cobrar([$this->linea(14)], $ayer, '00686701'));
    }

    public function test_it_rejects_an_inactive_type_or_a_future_date(): void
    {
        $this->abrir();
        DB::table('fee_types')->where('code', 5)->update(['is_active' => false]);

        $this->assertRechazaEn('items.0.feeTypeId', fn () => $this->cobrar([$this->linea(5)]));
        $this->assertRechazaEn('receivedOn', fn () => $this->cobrar(null, BusinessDate::today()->addDay()));
    }

    // ── El cálculo y el importe ───────────────────────────

    public function test_quantity_must_be_a_positive_integer(): void
    {
        $this->abrir();

        $this->assertRechazaEn('items.0.quantity', fn () => $this->cobrar([$this->linea(25, ['quantity' => 0])]));
    }

    public function test_the_percentage_is_calculated_with_the_rate_of_the_price(): void
    {
        $this->abrir();

        $cobro = $this->cobrar([$this->linea(15, ['quantity' => null, 'baseAmount' => '123456'])]);
        $item = $cobro->items()->firstOrFail();

        $this->assertSame('86.42', $item->calculated_amount);
        $this->assertSame('86.42', $this->total($cobro));
        $this->assertNull($item->quantity);
        $this->assertSame('0.0700', $item->rate);

        $this->assertRechazaEn('items.0.baseAmount', fn () => $this->cobrar([$this->linea(15, ['quantity' => null])]));
    }

    public function test_a_percentage_that_rounds_to_zero_can_be_written_by_hand_with_a_reason(): void
    {
        $this->abrir();
        $base = ['quantity' => null, 'baseAmount' => '5'];

        $this->assertRechazaEn('items.0.amount', fn () => $this->cobrar([$this->linea(15, $base)]), '$ 0,00');

        $cobro = $this->cobrar([$this->linea(15, [...$base, 'amount' => '10', 'reasonCode' => 'exception', 'reason' => 'Mínimo autorizado'])]);
        $item = $cobro->items()->firstOrFail();

        $this->assertSame('0.00', $item->calculated_amount);
        $this->assertSame('10.00', $item->original_amount);
    }

    public function test_changing_the_amount_needs_permission_and_reason(): void
    {
        $this->abrir();

        $this->assertRechazaEn('items.0.amount', fn () => $this->cobrar([$this->linea(14, ['amount' => '100'])], puede: false), 'permiso');
        $this->assertRechazaEn('items.0.reasonCode', fn () => $this->cobrar([$this->linea(14, ['amount' => '100'])]));
        $this->assertRechazaEn('items.0.reason', fn () => $this->cobrar([$this->linea(14, ['amount' => '100', 'reasonCode' => 'exception'])]));

        $cobro = $this->cobrar([$this->linea(14, ['amount' => '100', 'reasonCode' => 'rounding'])]);
        $item = $cobro->items()->firstOrFail();

        $this->assertSame('120.00', $item->calculated_amount);
        $this->assertSame('100.00', $item->original_amount);
        $this->assertSame('rounding', $item->change_reason_code);
        $this->assertSame('100.00', $this->efectivo());
    }

    public function test_the_same_amount_written_by_hand_needs_no_reason(): void
    {
        $this->abrir();

        $cobro = $this->cobrar([$this->linea(14, ['amount' => '120'])], puede: false);

        $this->assertNull($cobro->items()->firstOrFail()->change_reason_code);
    }

    // ── La pantalla ───────────────────────────────────────

    public function test_the_screens_collect_and_show_the_receipt(): void
    {
        $this->abrir();

        $this->actingAs($this->admin)->get(route('aranceles.cobros.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('aranceles/nuevo')->where('isOpen', true)->has('feeTypes', 25));

        $this->actingAs($this->admin)
            ->post(route('aranceles.cobros.store'), [
                'payerName' => ' AG Max SRL ',
                'payerCuit' => '30-71044289-2',
                'idempotencyKey' => 'pantalla-1',
                'items' => [$this->linea(14)],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $cobro = FeeCharge::query()->firstOrFail();
        $recibo = (int) FeeChargeReceipt::query()->where('fee_charge_id', $cobro->id)->value('receipt_id');

        $this->assertSame('AG Max SRL', $cobro->payer_name);
        $this->assertSame('30710442892', $cobro->payer_cuit);

        $this->actingAs($this->operador('consulta'))
            ->get(route('aranceles.cobros.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('aranceles/cobros')
                ->where('charges.data.0.total', '120.00')
                ->where('can.collect', false));

        $this->actingAs($this->admin)->get(route('aranceles.recibos.view', $recibo))
            ->assertOk()
            ->assertSee('AG Max SRL')
            ->assertSee('Homologación')
            ->assertSee('————————')
            ->assertSee('Período')
            ->assertHeaderMissing('X-Frame-Options-Denied');
    }

    public function test_the_preview_checks_without_writing(): void
    {
        $this->abrir();
        $datos = ['payerName' => 'Quien', 'items' => [$this->linea(14)]];

        $this->actingAs($this->admin)
            ->getJson(route('aranceles.cobros.preview', $datos))
            ->assertOk()
            ->assertJsonStructure(['numero']);

        $this->actingAs($this->admin)->get(route('aranceles.cobros.preview', $datos))->assertOk()->assertSee('Quien');

        $this->assertSame(0, FeeCharge::query()->count());
    }

    public function test_only_whoever_may_collects(): void
    {
        $this->actingAs($this->operador('consulta'))->post(route('aranceles.cobros.store'), [])->assertForbidden();
        $this->actingAs($this->operador('contador'))->get(route('aranceles.cobros.create'))->assertForbidden();
    }

    public function test_a_long_receipt_continues_on_another_sheet_with_the_same_number(): void
    {
        $this->abrir();
        $cobro = $this->cobrar(array_map(fn (int $c): array => $this->linea($c), [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14]));
        $comprobante = FeeChargeReceipt::query()->where('fee_charge_id', $cobro->id)->with('receipt')->firstOrFail();

        $pdf = app(FeeReceiptPdf::class)->render($comprobante);

        $this->assertGreaterThan(1, $pdf->getDomPDF()->getCanvas()->get_page_count());
        $this->assertCount(14, $comprobante->snapshot['lines']);
    }

    // ── Anular ────────────────────────────────────────────

    public function test_voiding_reverses_the_money_and_keeps_the_number(): void
    {
        $this->abrir();
        $cobro = $this->cobrar();
        $recibo = FeeChargeReceipt::query()->where('fee_charge_id', $cobro->id)->with('receipt')->firstOrFail()->receipt;

        app(VoidFeeCollection::class)->handle($cobro, $this->motivo(), $this->admin->id);

        $cobro->refresh();
        $recibo->refresh();

        $this->assertSame(FeeChargeStatus::Voided, $cobro->status);
        $this->assertSame(ReceiptStatus::Voided, $recibo->status);
        $this->actingAs($this->admin)->get(route('aranceles.recibos.view', $recibo->id))->assertSee('ANULADO');
        $this->actingAs($this->admin)->get(route('aranceles.cobros.index'))->assertInertia(fn (Assert $p) => $p
            ->where('charges.data.0.voidReason', 'Error de carga'));
        $this->assertSame('0.00', $this->efectivo());
        $this->assertSame('0.00', Decimal::scale(app(CashBalance::class)->of(LedgerAccount::CollectedFunds, $this->caja, Currency::Ars)));
        $this->assertSame('0.00', Decimal::scale(app(CashBalance::class)->of(LedgerAccount::UnassignedFunds, $this->caja, Currency::Ars)));
        $this->assertTrue(AuditEvent::query()->where('action', 'arancel.cobro-anulado')->where('subject_id', $cobro->id)->exists());

        // Un segundo envío no hace nada.
        app(VoidFeeCollection::class)->handle($cobro, $this->motivo(), $this->admin->id);
        $this->assertSame('0.00', $this->efectivo());
    }

    public function test_deposited_cash_cannot_be_voided(): void
    {
        $this->abrir();
        $cobro = $this->cobrar();
        $this->depositoDe($this->recepcionDe($cobro), '220');

        $this->assertRechazaEn('reason', fn () => app(VoidFeeCollection::class)->handle($cobro, $this->motivo(), $this->admin->id), 'salió de la caja');
        $this->assertSame(FeeChargeStatus::Paid, $cobro->refresh()->status);
    }

    public function test_voiding_needs_its_permission(): void
    {
        $this->abrir();
        $cobro = $this->cobrar();

        $this->actingAs($this->operador('administrativo'))
            ->post(route('aranceles.cobros.anular', $cobro), ['reasonCode' => 'duplicate'])
            ->assertForbidden();
        $this->actingAs($this->operador('contador'))
            ->post(route('aranceles.cobros.anular', $cobro), ['reasonCode' => 'other'])
            ->assertSessionHasErrors('reason');
    }

    // ── Reemplazar el recibo ──────────────────────────────

    public function test_replacing_the_receipt_moves_no_money_and_keeps_day_and_month(): void
    {
        $this->abrir();
        $cobro = $this->cobrar();
        $anterior = FeeChargeReceipt::query()->where('fee_charge_id', $cobro->id)->with('receipt')->firstOrFail()->receipt;
        $this->depositoDe($this->recepcionDe($cobro), '220');
        $efectivo = $this->efectivo();

        $nuevo = app(ReplaceFeeReceipt::class)->handle(
            $cobro,
            'AG MAX S.R.L.',
            '30710442892',
            [2 => 'Notificaciones (dos)'],
            $this->motivo(FeeReasonCode::WrongPayer),
            null,
            $this->admin->id,
        );

        $anterior->refresh();
        $this->assertSame(ReceiptStatus::Replaced, $anterior->status);
        $this->actingAs($this->admin)->get(route('aranceles.recibos.view', $anterior->id))->assertSee('REEMPLAZADO');
        $this->assertSame($anterior->id, $nuevo->receipt->replaces_receipt_id);
        $this->assertNotSame($anterior->formatted_number, $nuevo->receipt->formatted_number);
        $this->assertSame('AG MAX S.R.L.', $nuevo->receipt->counterparty_name_snapshot);
        $this->assertSame('Notificaciones (dos)', $nuevo->snapshot['lines'][1]['name']);
        $this->assertSame($anterior->issue_date->toDateString(), $nuevo->receipt->issue_date->toDateString());
        $this->assertSame($efectivo, $this->efectivo());
        $this->assertSame('220.00', $nuevo->receipt->amount);

        $libro = app(CashDayBook::class)->entries($this->caja, BusinessDate::today(), BusinessDate::today());
        $this->assertSame([$nuevo->receipt->formatted_number], array_column($libro['income'], 'number'));
    }

    public function test_replacing_with_nothing_new_is_rejected(): void
    {
        $this->abrir();
        $cobro = $this->cobrar();

        $this->assertRechazaEn('payerName', fn () => app(ReplaceFeeReceipt::class)->handle($cobro, 'AG Max SRL', null, [], $this->motivo(FeeReasonCode::WrongPayer), null, $this->admin->id));
    }

    public function test_a_replacement_cannot_change_the_amount_in_the_database(): void
    {
        $this->abrir();
        $cobro = $this->cobrar();

        $this->assertLaBaseRechaza(fn () => DB::table('fee_charge_receipts')->insert([
            'fee_charge_id' => $cobro->id,
            'receipt_id' => DB::table('receipts')->where('cash_box_id', '<>', $this->caja)->value('id') ?? FeeChargeReceipt::query()->value('receipt_id'),
            'snapshot' => '{}',
        ]), 'fee_charge_receipts');
    }

    // ── Corregir ──────────────────────────────────────────

    public function test_correcting_with_the_same_amount_keeps_the_money_where_it_is(): void
    {
        $this->abrir();
        $cobro = $this->cobrar([$this->linea(14)]);
        $recepcion = $this->recepcionDe($cobro);
        $this->depositoDe($recepcion, '120');
        $efectivo = $this->efectivo();

        // Otro tipo, el mismo importe: $ 120 de convenios (v).
        $nuevo = app(CorrectFeeCharge::class)->handle($cobro, 'AG Max SRL', null, [$this->linea(22)], $this->motivo(FeeReasonCode::WrongType), null, $this->admin->id, true);

        $this->assertSame(FeeChargeStatus::Voided, $cobro->refresh()->status);
        $this->assertSame(FeeChargeStatus::Paid, $nuevo->status);
        $this->assertSame($recepcion->id, $this->recepcionDe($nuevo)->id);
        $this->assertNull($recepcion->refresh()->reversed_at);
        $this->assertSame($efectivo, $this->efectivo());
        $this->assertSame('120.00', Decimal::scale(app(CashBalance::class)->of(LedgerAccount::CollectedFunds, $this->caja, Currency::Ars)));
        $this->assertSame($nuevo->id, FeeChargeCorrection::query()->where('corrected_charge_id', $cobro->id)->value('new_charge_id'));
        $this->assertSame(22, (int) $nuevo->items()->firstOrFail()->code_snapshot);

        // Un segundo envío devuelve el mismo cobro.
        $otra = app(CorrectFeeCharge::class)->handle($cobro, 'AG Max SRL', null, [$this->linea(22)], $this->motivo(FeeReasonCode::WrongType), null, $this->admin->id, true);
        $this->assertSame($nuevo->id, $otra->id);
    }

    public function test_correcting_the_amount_reverses_and_registers_the_right_one(): void
    {
        $this->abrir();
        // Se recibieron $ 220 y se cargaron $ 250.
        $cobro = $this->cobrar([$this->linea(14, ['amount' => '250', 'reasonCode' => 'rounding'])]);
        $this->assertSame('250.00', $this->efectivo());

        $nuevo = app(CorrectFeeCharge::class)->handle(
            $cobro,
            'AG Max SRL',
            null,
            [$this->linea(14), $this->linea(25, ['quantity' => 2])],
            $this->motivo(FeeReasonCode::WrongAmount),
            null,
            $this->admin->id,
            true,
        );

        $this->assertSame('220.00', $this->total($nuevo));
        $this->assertSame('220.00', $this->efectivo());
        $this->assertSame('220.00', Decimal::scale(app(CashBalance::class)->of(LedgerAccount::CollectedFunds, $this->caja, Currency::Ars)));
        $this->assertSame('0.00', Decimal::scale(app(CashBalance::class)->of(LedgerAccount::UnassignedFunds, $this->caja, Currency::Ars)));
        $this->assertSame(BusinessDate::today()->toDateString(), $nuevo->paid_on?->toDateString());
    }

    public function test_correcting_the_amount_of_a_deposit_is_blocked_or_pending_with_the_area(): void
    {
        $this->abrir();
        $enTransito = $this->cobrar([$this->linea(14)]);
        $this->depositoDe($this->recepcionDe($enTransito), '120');
        $this->assertRechazaEn('items', fn () => app(CorrectFeeCharge::class)->handle($enTransito, 'AG', null, [$this->linea(25)], $this->motivo(FeeReasonCode::WrongAmount), null, $this->admin->id, true), 'cancelá el traspaso');

        $acreditado = $this->cobrar([$this->linea(14)]);
        $this->depositoDe($this->recepcionDe($acreditado), '120', CashTransferStatus::BankConfirmed);
        $this->assertRechazaEn('items', fn () => app(CorrectFeeCharge::class)->handle($acreditado, 'AG', null, [$this->linea(25)], $this->motivo(FeeReasonCode::WrongAmount), null, $this->admin->id, true), 'con el área');
    }

    public function test_a_correction_uses_the_prices_of_the_original_day(): void
    {
        $this->abrir();
        $cobro = $this->cobrar([$this->linea(14)]);
        $tipo = FeeType::query()->where('code', 14)->firstOrFail();
        DB::table('fee_type_prices')->where('fee_type_id', $tipo->id);
        app(AddFeeTypePrice::class)->handle($tipo, BusinessDate::today()->addDay(), '999', null, $this->admin->id);

        $nuevo = app(CorrectFeeCharge::class)->handle($cobro, 'Otro nombre', null, [$this->linea(14, ['quantity' => 2])], $this->motivo(FeeReasonCode::WrongQuantity), null, $this->admin->id, true);

        $this->assertSame('240.00', $this->total($nuevo));
        $this->assertSame($cobro->pricing_date->toDateString(), $nuevo->pricing_date->toDateString());
    }

    public function test_a_voided_charge_is_not_corrected_nor_replaced(): void
    {
        $this->abrir();
        $cobro = $this->cobrar();
        app(VoidFeeCollection::class)->handle($cobro, $this->motivo(), $this->admin->id);

        $this->assertRechazaEn('reason', fn () => app(CorrectFeeCharge::class)->handle($cobro, 'AG', null, [$this->linea(14)], $this->motivo(FeeReasonCode::WrongType), null, $this->admin->id, true));
        $this->assertRechazaEn('reason', fn () => app(ReplaceFeeReceipt::class)->handle($cobro, 'Otro', null, [], $this->motivo(FeeReasonCode::WrongPayer), null, $this->admin->id));
    }

    public function test_correcting_needs_its_permission(): void
    {
        $this->abrir();
        $cobro = $this->cobrar();

        $this->actingAs($this->operador('contador'))
            ->post(route('aranceles.cobros.corregir.edit', $cobro), [])
            ->assertForbidden();
    }

    // ── Lo que impone la base ─────────────────────────────

    public function test_the_same_money_cannot_back_two_charges(): void
    {
        $this->abrir();
        $uno = $this->cobrar([$this->linea(14)]);
        $dos = $this->cobrar([$this->linea(25)]);

        $this->assertLaBaseRechaza(fn () => FeeChargeFunding::query()->create([
            'fee_charge_id' => $dos->id,
            'fund_receipt_id' => $this->recepcionDe($uno)->id,
            'amount' => '1',
        ]), 'vinculados a otros cobros');
    }

    public function test_a_reversed_receipt_and_a_receipt_of_another_box_do_not_back_a_charge(): void
    {
        $this->abrir();
        $cobro = $this->cobrar([$this->linea(14)]);
        $recepcion = $this->recepcionDe($cobro);
        app(VoidFeeCollection::class)->handle($cobro, $this->motivo(), $this->admin->id);

        $otro = $this->cobrar([$this->linea(25)]);

        $this->assertLaBaseRechaza(fn () => FeeChargeFunding::query()->create([
            'fee_charge_id' => $otro->id,
            'fund_receipt_id' => $recepcion->id,
            'amount' => '1',
        ]), 'revertida');

        $this->abrirMultas($this->cuenta('310000111222333'));

        $ajena = app(RegisterCashFundReceipt::class)->handle(
            amount: '5',
            idempotencyKey: 'ajena',
            cashBoxId: (int) CashBox::query()->where('code', CashBox::MULTAS)->value('id'),
            receivedDate: BusinessDate::today(),
            actorId: $this->admin->id,
        );

        $this->assertLaBaseRechaza(fn () => FeeChargeFunding::query()->create([
            'fee_charge_id' => $otro->id,
            'fund_receipt_id' => $ajena->id,
            'amount' => '1',
        ]), 'no respalda este cobro');
    }

    public function test_a_receipt_with_a_live_link_cannot_be_reversed(): void
    {
        $this->abrir();
        $recepcion = $this->recepcionDe($this->cobrar());

        $this->assertLaBaseRechaza(fn () => DB::table('fund_receipts')->where('id', $recepcion->id)->update([
            'reversal_event_id' => $recepcion->financial_event_id,
            'reversed_at' => now(),
            'reversal_reason' => 'Directo',
        ]), 'primero se libera');
    }

    public function test_a_charge_cannot_stay_without_lines_or_incomplete(): void
    {
        $this->abrir();

        $this->assertLaBaseRechaza(fn () => FeeCharge::query()->create([
            'payer_name' => 'Sin renglones',
            'medium' => 'cash',
            'status' => 'paid',
            'requested_on' => BusinessDate::today(),
            'pricing_date' => BusinessDate::today(),
            'paid_on' => BusinessDate::today(),
            'idempotency_key' => 'sin-renglones',
        ]), 'no tiene renglones');
    }

    public function test_the_database_rejects_editing_a_charge_or_its_lines(): void
    {
        $this->abrir();
        $cobro = $this->cobrar();

        $this->assertLaBaseRechaza(fn () => DB::table('fee_charges')->where('id', $cobro->id)->update(['payer_name' => 'Otro']), 'no se editan');
        $this->assertLaBaseRechaza(fn () => DB::table('fee_charges')->where('id', $cobro->id)->delete(), 'no se borra');
        $this->assertLaBaseRechaza(fn () => DB::table('fee_charge_items')->where('fee_charge_id', $cobro->id)->update(['original_amount' => '1']), 'no se edita');
    }

    public function test_the_database_keeps_a_rounding_small_and_a_change_with_its_reason(): void
    {
        $this->abrir();
        $cobro = $this->cobrar([$this->linea(14)]);
        $item = $cobro->items()->firstOrFail();
        $base = collect($item->getAttributes())->except('id')->all();

        $this->assertLaBaseRechaza(fn () => DB::table('fee_charge_items')->insert([...$base, 'line_number' => 2, 'original_amount' => '100']), 'fee_charge_items_change_has_reason');
        $this->assertLaBaseRechaza(fn () => DB::table('fee_charge_items')->insert([...$base, 'line_number' => 3, 'original_amount' => '100', 'change_reason_code' => 'less_accepted']), 'fee_charge_items_change_reason_code_check');
    }

    public function test_a_line_must_use_the_price_in_force(): void
    {
        $this->abrir();
        $cobro = $this->cobrar([$this->linea(14)]);
        $tipo = FeeType::query()->where('code', 14)->firstOrFail();
        $futuro = app(AddFeeTypePrice::class)->handle($tipo, BusinessDate::today()->addYear(), '999', null, $this->admin->id);
        $base = collect($cobro->items()->firstOrFail()->getAttributes())->except('id')->all();

        $this->assertLaBaseRechaza(fn () => DB::table('fee_charge_items')->insert([
            ...$base,
            'line_number' => 2,
            'fee_type_price_id' => $futuro->id,
            'unit_price' => '999',
            'calculated_amount' => '999',
            'original_amount' => '999',
        ]), 'precio vigente');
    }

    public function test_a_used_price_and_code_do_not_change(): void
    {
        $this->abrir();
        $cobro = $this->cobrar([$this->linea(14)]);
        $tipo = FeeType::query()->where('code', 14)->firstOrFail();
        $usado = $tipo->priceOn(BusinessDate::today());

        app(AddFeeTypePrice::class)->handle($tipo, BusinessDate::today()->addDay(), '999', null, $this->admin->id);

        $this->assertNotNull($usado);
        $this->assertRechazaEn('reason', fn () => app(VoidFeeTypePrice::class)->handle($usado, 'Motivo', $this->admin->id), 'ya se usó');
        $this->assertLaBaseRechaza(fn () => DB::table('fee_type_prices')->where('id', $usado->id)->update(['voided_at' => now(), 'void_reason' => 'Directo']), 'ya se usó');
        $this->assertLaBaseRechaza(fn () => DB::table('fee_types')->where('id', $tipo->id)->update(['code' => 99]), 'su código no cambia');
        $this->assertSame(FeeChargeStatus::Paid, $cobro->status);
    }

    public function test_aranceles_money_does_not_fund_an_installment(): void
    {
        $this->abrir();
        $recepcion = $this->recepcionDe($this->cobrar());
        $cuota = $this->cuotaDeHaberes('100/26', '1000.00');

        $this->assertLaBaseRechaza(fn () => DB::table('funding_allocations')->insert([
            'fund_receipt_id' => $recepcion->id,
            'beneficiary_installment_id' => $cuota->id,
            'haber_id' => $cuota->haber_id,
            'amount' => '1',
        ]), 'no se asigna a una cuota');
    }

    public function test_a_funds_applied_event_cannot_be_an_orphan(): void
    {
        $this->abrir();

        $this->assertLaBaseRechaza(fn () => app(PostJournalEntry::class)->handle(
            type: FinancialEventType::FundsApplied,
            idempotencyKey: 'huerfano',
            lines: [
                EntryLine::debit(LedgerAccount::UnassignedFunds, '1')->in(Currency::Ars)->onCashBox($this->caja),
                EntryLine::credit(LedgerAccount::CollectedFunds, '1')->in(Currency::Ars)->onCashBox($this->caja),
            ],
            date: BusinessDate::today(),
            cashBoxId: $this->caja,
            actorId: $this->admin->id,
        ), 'no corresponden a ningún pago');
    }

    // ── Arqueo, cierre y planilla ───────────────────────────────────────

    /** @param array<int, int> $billetes */
    private function cerrarHoy(array $billetes): PeriodClosing
    {
        $arqueo = app(RecordCashCount::class)->handle(
            cashBoxId: $this->caja,
            countedOn: BusinessDate::today(),
            denominations: $billetes,
            actorId: $this->admin->id,
        );
        app(ReviewCashCount::class)->handle($arqueo, $this->admin->id);

        return app(ClosePeriod::class)->handle($this->caja, BusinessDate::today(), actorId: $this->admin->id);
    }

    /** Sin cheques en la caja: cuenta, cierra y baja la planilla, con lo contado en su cuadro. */
    public function test_the_cash_box_counts_closes_and_exports_small_amounts(): void
    {
        Storage::fake('local');
        $this->abrir();
        $this->cobrar();

        $cierre = $this->cerrarHoy([200 => 1, 20 => 1]);

        $this->assertSame('220.00', $cierre->closing_cash);

        $this->actingAs($this->admin)->get(route('aranceles.caja.cierres.sheet', $cierre))->assertRedirect();

        $adjunto = $cierre->refresh()->sheetAttachment;
        $hoja = IOFactory::load(Storage::disk('local')->path($adjunto->object_key))
            ->getSheetByName('REVERSO '.BusinessDate::today()->format('dmy'))?->toArray(null, false, false) ?? [];
        $fila = array_values(array_filter($hoja, fn (array $f): bool => (float) $f[1] === 20.0))[0] ?? [];

        $this->assertSame([1.0, 20.0, 20.0], array_map(floatval(...), array_slice($fila, 0, 3)));
    }

    /** No circula nada menor de $ 50: el arqueo ofrece lo mismo que las otras cajas. */
    public function test_the_count_offers_no_bill_under_fifty(): void
    {
        $this->abrir();

        $this->actingAs($this->admin)->get(route('aranceles.caja.dia'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('suggestedDenominations', fn ($d): bool => collect($d)->min() === 50));

        $this->actingAs($this->admin)->get(route('caja.dia'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('suggestedDenominations', fn ($d): bool => collect($d)->min() === 50));
    }

    // ── El libro del día, cuando se anula otro día ──────────────────────

    /** @return array{income: list<array<string, mixed>>, expense: list<array<string, mixed>>} */
    private function libroDe(CarbonImmutable $dia): array
    {
        return app(CashDayBook::class)->entries($this->caja, $dia, $dia);
    }

    /** @param array{income: list<array<string, mixed>>, expense: list<array<string, mixed>>} $libro @return list<array{string, string}> */
    private function ingresos(array $libro): array
    {
        return array_map(fn (array $f): array => [$f['number'], $f['cash']], $libro['income']);
    }

    public function test_voiding_another_day_keeps_the_row_and_shows_the_reversal_on_its_day(): void
    {
        $this->abrir();
        $ayer = BusinessDate::today()->subDay();
        $cobro = $this->cobrar([$this->linea(12)], $ayer, 'T-1');

        app(VoidFeeCollection::class)->handle($cobro, $this->motivo(), $this->admin->id);

        $this->assertSame([['T-1', '300.00']], $this->ingresos($this->libroDe($ayer)));
        $this->assertSame([['Anulación del recibo T-1', '-300.00']], $this->ingresos($this->libroDe(BusinessDate::today())));
    }

    /** El mismo día no hay nada que explicar: ni el recibo ni la reversión. */
    public function test_voiding_the_same_day_leaves_no_rows(): void
    {
        $this->abrir();
        $cobro = $this->cobrar([$this->linea(12)]);

        app(VoidFeeCollection::class)->handle($cobro, $this->motivo(), $this->admin->id);

        $this->assertSame([], $this->ingresos($this->libroDe(BusinessDate::today())));
    }

    /** Lo cargado mal se corrige otro día: cada día explica su movimiento y el cierre suma lo mismo. */
    public function test_correcting_the_amount_another_day_adds_up_with_the_closing(): void
    {
        $this->abrir();
        $ayer = BusinessDate::today()->subDay();
        $cobro = $this->cobrar([$this->linea(14, ['amount' => '250', 'reasonCode' => 'rounding'])], $ayer, 'T-1');

        app(CorrectFeeCharge::class)->handle($cobro, 'AG Max SRL', null, [$this->linea(14), $this->linea(25, ['quantity' => 2])], $this->motivo(FeeReasonCode::WrongAmount), null, $this->admin->id, true);

        $hoy = $this->libroDe(BusinessDate::today());

        $this->assertSame([['T-1', '250.00']], $this->ingresos($this->libroDe($ayer)));
        $this->assertSame([
            [$this->numeroVigente(FeeChargeCorrection::query()->where('corrected_charge_id', $cobro->id)->value('new_charge_id')), '220.00'],
            ['Anulación del recibo T-1', '-250.00'],
        ], $this->ingresos($hoy));

        $cierre = $this->cerrarHoy([200 => 1, 20 => 1]);

        $this->assertSame('250.00', $cierre->opening_cash);
        $this->assertSame('-30.00', $cierre->received_cash);
        $this->assertSame('220.00', $cierre->closing_cash);
    }

    /** Si el dinero sigue en la caja, el renglón de ese día es el del recibo nuevo; no se duplica. */
    public function test_correcting_twice_shows_one_row_for_the_money_that_came_in(): void
    {
        $this->abrir();
        $ayer = BusinessDate::today()->subDay();
        $cobro = $this->cobrar(null, $ayer, 'T-1');
        $mismoImporte = [$this->linea(14), $this->linea(25, ['amount' => '100', 'reasonCode' => 'rounding'])];

        $segundo = app(CorrectFeeCharge::class)->handle($cobro, 'AG Max SRL', null, $mismoImporte, $this->motivo(FeeReasonCode::WrongAmount), null, $this->admin->id, true);
        $numeroSegundo = $this->numeroVigente($segundo->id);

        $this->assertSame([[$numeroSegundo, '220.00']], $this->ingresos($this->libroDe($ayer)));
        $this->assertSame([], $this->ingresos($this->libroDe(BusinessDate::today())));

        app(CorrectFeeCharge::class)->handle($segundo, 'AG Max SRL', null, [$this->linea(14)], $this->motivo(FeeReasonCode::WrongAmount), null, $this->admin->id, true);

        $this->assertSame([[$numeroSegundo, '220.00']], $this->ingresos($this->libroDe($ayer)));
        $this->assertSame(['Anulación del recibo '.$numeroSegundo, '-220.00'], $this->ingresos($this->libroDe(BusinessDate::today()))[1]);
    }

    private function numeroVigente(int|string|null $cobro): string
    {
        return (string) FeeChargeReceipt::query()
            ->where('fee_charge_id', $cobro)
            ->latest('id')
            ->firstOrFail()
            ->receipt
            ->formatted_number;
    }

    // ── Lo que cambia el total, en la base ──────────────────────────────

    public function test_the_database_rejects_a_new_line_on_a_paid_charge(): void
    {
        $this->abrir();
        $cobro = $this->cobrar();
        $renglon = (array) DB::table('fee_charge_items')->where('fee_charge_id', $cobro->id)->first();
        unset($renglon['id']);

        $this->assertLaBaseRechaza(function () use ($renglon): void {
            DB::table('fee_charge_items')->insert([...$renglon, 'line_number' => 3]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        }, 'cobro pagado');
    }

    /** Aunque se vincule más dinero en la misma transacción: el recibo sigue diciendo otro total. */
    public function test_the_database_rejects_a_paid_charge_whose_receipt_is_for_another_total(): void
    {
        $this->abrir();
        $cobro = $this->cobrar();
        $renglon = (array) DB::table('fee_charge_items')->where('fee_charge_id', $cobro->id)->first();
        unset($renglon['id']);
        $recepcion = app(RegisterCashFundReceipt::class)->handle(
            amount: (string) $renglon['original_amount'],
            idempotencyKey: 'otra-recepcion',
            cashBoxId: $this->caja,
            receivedDate: BusinessDate::today(),
            medium: PaymentMedium::Cash,
            actorId: $this->admin->id,
            notes: 'Más dinero',
            currency: Currency::Ars,
        );

        $this->assertLaBaseRechaza(function () use ($cobro, $renglon, $recepcion): void {
            DB::table('fee_charge_items')->insert([...$renglon, 'line_number' => 3]);
            app(FeeChargeFunds::class)->apply($cobro, $recepcion, (string) $renglon['original_amount'], BusinessDate::today(), $this->admin->id);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        }, 'recibo vigente por su total');
    }

    public function test_the_database_rejects_a_new_line_on_a_voided_charge(): void
    {
        $this->abrir();
        $cobro = $this->cobrar();
        $renglon = (array) DB::table('fee_charge_items')->where('fee_charge_id', $cobro->id)->first();
        unset($renglon['id']);

        app(VoidFeeCollection::class)->handle($cobro, $this->motivo(), $this->admin->id);

        $this->assertLaBaseRechaza(
            fn () => DB::table('fee_charge_items')->insert([...$renglon, 'line_number' => 3]),
            'anulado no recibe renglones',
        );
    }

    // ── Lo que ve quien corrige ─────────────────────────────────────────

    /** La pantalla calcula con la misma fecha de tarifa que el servidor. */
    public function test_the_correction_form_gets_the_original_pricing_date(): void
    {
        $this->abrir();
        $ayer = BusinessDate::today()->subDay();
        $cobro = $this->cobrar(null, $ayer, 'T-1');

        $this->actingAs($this->admin)->get(route('aranceles.cobros.corregir.edit', $cobro))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('corrects.pricingDate', $ayer->toDateString()));
    }

    /** Un CUIT quitado en el reemplazo no vuelve, ni en el listado ni al corregir. */
    public function test_a_cuit_removed_by_a_replacement_stays_removed(): void
    {
        $this->abrir();
        $cobro = app(CollectFeeInCash::class)->handle('Empresa', '30710442892', [$this->linea(14)], 'con-cuit', $this->admin->id, null, null, null, true);

        app(ReplaceFeeReceipt::class)->handle($cobro, 'Empresa', null, [], $this->motivo(FeeReasonCode::WrongPayer), null, $this->admin->id);

        $this->actingAs($this->admin)->get(route('aranceles.cobros.index'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('charges.data.0.payerCuit', null));
        $this->actingAs($this->admin)->get(route('aranceles.cobros.corregir.edit', $cobro))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('corrects.payerCuit', null));
    }

    public function test_decimal_multiplies_and_takes_percentages_without_float(): void
    {
        $this->assertSame('100.00', Decimal::times('50.00', 2));
        $this->assertSame('70.00', Decimal::percentOf('100000', '0.07'));
        $this->assertSame('86.42', Decimal::percentOf('123456', '0.0700'));
        $this->assertSame('35.04', Decimal::percentOf('50050', '0.07'));
        $this->assertSame('0.00', Decimal::percentOf('5', '0.07'));
    }
}
