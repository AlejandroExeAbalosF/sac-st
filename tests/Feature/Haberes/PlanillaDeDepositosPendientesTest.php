<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Models\User;
use App\Modules\Banking\Models\BankAccount;
use App\Modules\Haberes\Enums\DepositKind;
use App\Modules\Haberes\Enums\DepositTicketStatus;
use App\Modules\Haberes\Models\DepositTicket;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Haberes\Pdf\PendingDepositSheet;
use App\Support\Pdf\WorksheetData;
use Carbon\CarbonImmutable;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La cola de comprobantes, en papel.
 *
 * El dibujo se prueba aparte de la consulta, igual que en la planilla de
 * caja: `PendingDepositSheet` no consulta nada —recibe los tickets y arma
 * la hoja—, así que se le pueden dar datos a mano y comprobar qué escribe.
 * La ruta, por su lado, solo tiene que contestar un PDF a quien
 * corresponde.
 */
class PlanillaDeDepositosPendientesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HaberesDemoSeeder::class);
    }

    public function test_la_ruta_devuelve_un_pdf(): void
    {
        $this->ticket();

        $respuesta = $this->actingAs($this->operador())->get(route('depositos.planilla'));

        $respuesta->assertOk();
        $this->assertSame('application/pdf', $respuesta->headers->get('content-type'));
    }

    /** En línea y no como descarga: lo normal es mirarla y mandarla a imprimir. */
    public function test_la_planilla_sale_en_linea(): void
    {
        $this->ticket();

        $respuesta = $this->actingAs($this->operador())->get(route('depositos.planilla'));

        $this->assertStringStartsWith(
            'inline',
            (string) $respuesta->headers->get('content-disposition'),
        );
    }

    /**
     * `depositos.ver` alcanza a los cuatro roles, así que el caso se prueba
     * con un usuario sin ninguno: es la única forma de comprobar que la
     * ruta está detrás del permiso y no simplemente abierta.
     */
    public function test_sin_permiso_no_hay_planilla(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('depositos.planilla'))
            ->assertForbidden();
    }

    /**
     * Solo los que esperan.
     *
     * Un comprobante descartado no es una cola de trabajo: es historia, y
     * meterlo en la hoja obligaría a quien la usa a saltear renglones.
     *
     * El estado `matched` no entra en el caso porque la base no deja
     * inventarlo —`deposit_tickets_matched_check` exige el movimiento con
     * el que se cruzó—, y montar un extracto para probar un filtro por
     * estado sería pagar de más por la misma respuesta.
     */
    public function test_la_hoja_deja_afuera_lo_descartado(): void
    {
        $this->ticket(amount: '100000.00');
        $this->ticket(amount: '380000.00', estado: DepositTicketStatus::Discarded);

        $hoja = $this->hoja();

        $this->assertCount(1, $hoja->rows);
        $this->assertSame(['Total (1 comprobantes)' => '100.000,00'], $hoja->totals);
    }

    /** Los más viejos arriba: un ticket de hace tres semanas es una señal. */
    public function test_los_mas_viejos_van_primero(): void
    {
        $this->ticket(amount: '111.00', fecha: '2026-06-20');
        $this->ticket(amount: '222.00', fecha: '2026-06-02');

        $hoja = $this->hoja();

        // La primera columna es la fecha del depósito.
        $this->assertSame('02/06/2026', $hoja->rows[0][0]);
        $this->assertSame('20/06/2026', $hoja->rows[1][0]);
    }

    /**
     * Cada moneda con su total.
     *
     * El ticket no declara moneda: declara a qué cuenta fue. Sumar pesos
     * con dólares en un solo número sería escribir una cifra que no existe.
     */
    public function test_los_totales_se_separan_por_moneda(): void
    {
        $this->ticket(amount: '100000.00');
        $this->ticket(amount: '500.00', cuenta: $this->cuenta('USD'));

        $hoja = $this->hoja();

        $this->assertSame(
            ['Total ARS' => '100.000,00', 'Total USD' => '500,00'],
            $hoja->totals,
        );
    }

    /** La hoja vacía dice que no hay nada, no muestra una tabla sin filas. */
    public function test_sin_pendientes_la_hoja_lo_dice(): void
    {
        $hoja = $this->hoja();

        $this->assertSame([], $hoja->rows);
        $this->assertSame('No hay depósitos esperando acreditación.', $hoja->emptyMessage);
    }

    /** El papel dice a qué momento corresponde y que no acredita nada. */
    public function test_la_hoja_se_declara_hoja_de_trabajo(): void
    {
        $hoja = $this->hoja();

        $this->assertSame('Depósitos esperando acreditación', $hoja->title);
        $this->assertSame('Quien la pidió', $hoja->generatedBy);
        $this->assertStringContainsString('no acredita nada', (string) $hoja->footnote);
    }

    private function hoja(): WorksheetData
    {
        $tickets = DepositTicket::query()
            ->with(['expediente.employer', 'account', 'installment'])
            ->waiting()
            ->get();

        return app(PendingDepositSheet::class)->build(
            $tickets,
            CarbonImmutable::parse('2026-06-25 11:00'),
            'Quien la pidió',
        );
    }

    private function ticket(
        string $amount = '100000.00',
        DepositTicketStatus $estado = DepositTicketStatus::Waiting,
        string $fecha = '2026-06-10',
        ?BankAccount $cuenta = null,
    ): DepositTicket {
        // `forceCreate`: `created_by` no es asignable en masa a propósito
        // —lo pone el Action que registra—, y acá el ticket se arma a mano.
        return DepositTicket::query()->forceCreate([
            'expediente_id' => Expediente::query()->orderBy('id')->firstOrFail()->id,
            'bank_account_id' => ($cuenta ?? $this->cuenta())->id,
            'deposited_at' => $fecha,
            'amount' => $amount,
            'deposit_kind' => DepositKind::CashDeposit,
            'status' => $estado,
            // Descartar exige motivo: un comprobante caído sin explicación no
            // se puede retomar, y la base no lo deja pasar.
            'discarded_reason' => $estado === DepositTicketStatus::Discarded
                ? 'El depósito se anuló.'
                : null,
            'created_by' => $this->operador()->id,
        ]);
    }

    private function cuenta(string $moneda = 'ARS'): BankAccount
    {
        return BankAccount::query()->firstOrCreate(
            ['account_number' => '1945269'.($moneda === 'ARS' ? '3' : '9')],
            [
                'label' => 'Cta. Cte. — Haberes '.$moneda,
                'bank_name' => 'Banco Macro',
                'currency' => $moneda,
                'is_active' => true,
            ],
        );
    }
}
