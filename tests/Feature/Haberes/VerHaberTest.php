<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Haberes\Actions\CollectAndIssueReceipt;
use App\Modules\Haberes\Actions\VoidCashCollection;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Models\Haber;
use Database\Seeders\CashBoxSeeder;
use Database\Seeders\DocumentSeriesSeeder;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Detalle de un haber.
 *
 * El haber vivía dentro del expediente como una fila desplegable, y ahí
 * entraba mientras la cuota fuera un importe. Ahora tiene concepto,
 * etiqueta, medio previsto, observaciones y estado propio.
 */
class VerHaberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HaberesDemoSeeder::class);
    }

    public function test_it_shows_the_haber_with_its_installments_and_its_expediente(): void
    {
        $haber = $this->haberDe('45905/2018');

        $this->actingAs($this->operador())
            ->get(route('haberes.haber.show', [$haber->expediente, $haber]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('haberes/haber-show')
                ->where('beneficiario.name', 'Tinte, Olga Isabel')
                ->has('haber.installments', 2)
                // El marco: el haber no se entiende suelto.
                ->where('expediente.displayNumber', '45905/2018')
                ->where('expediente.employerName', 'Frigorífico del Norte SA'));
    }

    /**
     * El concepto viaja resuelto: la cuota que no tiene el suyo muestra el
     * del haber, y la que sí lo tiene muestra el propio. Es el caso Tinte.
     */
    public function test_each_installment_shows_its_resolved_concept(): void
    {
        $haber = $this->haberDe('45905/2018');

        $this->actingAs($this->operador())
            ->get(route('haberes.haber.show', [$haber->expediente, $haber]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('haber.installments.0.concept', 'Haberes adeudados')
                ->where('haber.installments.1.concept', 'Sac proporcional'));
    }

    /**
     * El ordinal se lee dentro de su expediente.
     *
     * El mismo número en otro expediente es otro haber, no este: es lo que
     * permite que la dirección diga «el primer haber» sin ambigüedad. Y un
     * número que ese expediente no tiene no abre nada.
     */
    public function test_the_number_is_read_inside_its_expediente(): void
    {
        $haber = $this->haberDe('45905/2018');
        $otro = $this->expediente('77420/2024');

        $this->assertSame(1, $haber->haber_number);

        $this->actingAs($this->operador())
            ->get(route('haberes.haber.show', [$otro, $haber]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('expediente.displayNumber', '77420/2024')
                ->whereNot('haber.id', $haber->id));

        $this->actingAs($this->operador())
            ->get(route('haberes.haber.show', [$otro, 99]))
            ->assertNotFound();
    }

    public function test_whoever_only_reads_can_open_it_but_not_edit(): void
    {
        $haber = $this->haberDe('125957/2026');

        $this->actingAs($this->operador('consulta'))
            ->get(route('haberes.haber.show', [$haber->expediente, $haber]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canEdit', false)
                ->where('canCancel', false));
    }

    /** El enlace desde el expediente necesita el id del expediente. */
    public function test_the_list_carries_the_expediente_of_each_haber(): void
    {
        $expediente = $this->expediente('120326/2019');

        $this->actingAs($this->operador())
            ->get(route('expedientes.show', $expediente))
            ->assertInertia(fn (Assert $page) => $page
                ->where('expediente.haberes.0.expedienteId', $expediente->id));
    }

    /**
     * La pantalla abre con recibos, vigentes y anulados.
     *
     * Es la unica prueba que la ejercita **con comprobantes emitidos**:
     * todas las demas la abren antes de que exista uno, y por eso nadie
     * vio que faltaba cargar una relacion hasta que reviento en pantalla.
     *
     * Cobra las **dos** cuotas a proposito. Eloquent marca los modelos
     * como celosos del lazy loading solo cuando la consulta trajo mas de
     * una fila, asi que con un unico recibo la relacion faltante se
     * resuelve en silencio y la prueba pasaria igual estando rota
     * —`InstallmentReceiptDataTest` explica el mecanismo—.
     */
    public function test_it_opens_with_issued_and_voided_receipts(): void
    {
        $this->seed(CashBoxSeeder::class);
        $this->seed(DocumentSeriesSeeder::class);

        $haber = $this->haberDe('125957/2026');
        $operador = $this->operador();

        $cobrar = fn (BeneficiaryInstallment $cuota) => app(CollectAndIssueReceipt::class)->handle(
            installment: $cuota->refresh(),
            idempotencyKey: 'ver-haber:'.uniqid(),
            actorId: $operador->id,
        );

        $cuotas = $haber->installments()->orderBy('installment_number')->get();

        $cuotas->each(function (BeneficiaryInstallment $cuota) use ($cobrar): void {
            $cuota->forceFill(['expected_medium' => 'cash'])->save();
            $cobrar($cuota);
        });

        // Y una de las dos, ademas, anulada y vuelta a emitir: es lo que
        // pone las dos relaciones en la misma pantalla.
        $primera = $cuotas->firstOrFail();

        app(VoidCashCollection::class)->handle(
            installment: $primera->refresh(),
            reason: 'El importe se cargo con un cero de mas.',
            idempotencyKey: 'ver-haber:anular',
            actorId: $operador->id,
        );

        $cobrar($primera);

        $this->actingAs($operador)
            ->get(route('haberes.haber.show', [$haber->expediente, $haber]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('haber.installments.0.incomeReceipt.status', 'issued')
                ->where('haber.installments.1.incomeReceipt.status', 'issued')
                ->where('haber.installments.0.voidedReceipts.0.status', 'replaced'));
    }

    /**
     * La ficha dice cuándo se tocó el haber, o no dice nada.
     *
     * Sale de `audit_events`, igual que en el listado y en el detalle del
     * expediente: son tres pantallas dibujando el mismo hecho y tienen que
     * coincidir. Leer `updated_at` no serviría —anular el expediente le
     * mueve el timestamp a cada haber sin que nadie lo haya tocado—.
     */
    public function test_la_ficha_del_haber_dice_cuando_se_lo_modifico(): void
    {
        $haber = $this->haberDe('120326/2019');

        $this->actingAs($this->operador())
            ->get(route('haberes.haber.show', [$haber->expediente, $haber]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('haber.lastChange', null));

        $contador = $this->operador('contador');

        $this->actingAs($contador)
            ->patch(route('haberes.haber.cancel', [$haber->expediente, $haber]), [
                'reason' => 'El acta no lo reconocía; se cargó de más.',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->operador())
            ->get(route('haberes.haber.show', [$haber->expediente, $haber]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('haber.lastChange.by', $contador->refresh()->name)
                ->where('haber.lastChange.at', fn (mixed $fecha): bool => is_string($fecha) && $fecha !== '')
            );
    }

    private function haberDe(string $numero): Haber
    {
        return $this->expediente($numero)->haberes()->orderBy('id')->firstOrFail();
    }

    private function expediente(string $numero): Expediente
    {
        return Expediente::query()->where('display_number', $numero)->firstOrFail();
    }
}
