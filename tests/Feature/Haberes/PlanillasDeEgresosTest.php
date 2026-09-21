<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Models\User;
use App\Modules\Haberes\Data\CounterPayoutRowData;
use App\Modules\Haberes\Data\UnconfirmedTransferRowData;
use App\Modules\Haberes\Enums\DisbursementStatus;
use App\Modules\Haberes\Pdf\CounterPayoutSheet;
use App\Modules\Haberes\Pdf\UnconfirmedTransferSheet;
use App\Support\Pdf\WorksheetData;
use Carbon\CarbonImmutable;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Las dos planillas del egreso.
 *
 * El dibujo se prueba con datos armados a mano, igual que la planilla de
 * caja: ninguna de las dos hojas consulta nada —reciben las filas que la
 * pantalla ya tiene y arman el papel—, y eso permite probar qué escriben
 * sin montar el circuito entero. Que las filas sean las correctas es
 * asunto de `PantallaDeEgresosTest`.
 */
class PlanillasDeEgresosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HaberesDemoSeeder::class);
    }

    public function test_la_ruta_devuelve_un_pdf_del_mostrador(): void
    {
        $respuesta = $this->actingAs($this->operador())->get(route('planillas.print'));

        $respuesta->assertOk();
        $this->assertSame('application/pdf', $respuesta->headers->get('content-type'));
    }

    public function test_la_ruta_devuelve_un_pdf_de_las_transferencias(): void
    {
        $respuesta = $this->actingAs($this->operador())
            ->get(route('planillas.print', ['cola' => 'transferencias']));

        $respuesta->assertOk();
        $this->assertSame('application/pdf', $respuesta->headers->get('content-type'));
    }

    /** En línea y no como descarga, igual que los comprobantes. */
    public function test_la_planilla_sale_en_linea(): void
    {
        $respuesta = $this->actingAs($this->operador())->get(route('planillas.print'));

        $this->assertStringStartsWith(
            'inline',
            (string) $respuesta->headers->get('content-disposition'),
        );
    }

    public function test_sin_permiso_no_hay_planilla(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('planillas.print'))
            ->assertForbidden();
    }

    /**
     * El total es el número por el que se imprime la hoja.
     *
     * Y llega sumado desde afuera: el mismo que la pantalla muestra, para
     * que el cajero no cuente un efectivo distinto del que leyó.
     */
    public function test_la_hoja_del_mostrador_lleva_el_total_que_le_dan(): void
    {
        $hoja = $this->hojaDeMostrador([
            $this->filaDeMostrador('34-000001/2026', 'PÉREZ, Juan', '1000.50'),
            $this->filaDeMostrador('34-000002/2026', 'GÓMEZ, Ana', '2500.00'),
        ], total: '3500.50');

        $this->assertCount(2, $hoja->rows);
        $this->assertSame(['Total a entregar (2 cuotas)' => '3.500,50'], $hoja->totals);
    }

    /** La casilla va vacía: la tilda quien entrega, no el sistema. */
    public function test_la_hoja_del_mostrador_deja_la_casilla_en_blanco(): void
    {
        $hoja = $this->hojaDeMostrador([
            $this->filaDeMostrador('34-000001/2026', 'PÉREZ, Juan', '1000.50'),
        ]);

        $ultima = array_key_last($hoja->columns);

        $this->assertTrue($hoja->columns[$ultima]->tickBox);
        $this->assertSame('Retirado', $hoja->columns[$ultima]->label);
        $this->assertSame('', $hoja->rows[0][$ultima]);
    }

    /**
     * El papel dice que la marca no reemplaza al recibo.
     *
     * Sin esa línea, la planilla firmada o tildada empieza a funcionar como
     * constancia de entrega y el recibo de egreso pasa a ser un trámite.
     */
    public function test_la_hoja_del_mostrador_se_declara_hoja_de_trabajo(): void
    {
        $hoja = $this->hojaDeMostrador([]);

        $this->assertStringContainsString('no reemplaza', (string) $hoja->footnote);
        $this->assertStringContainsString('recibo de egreso', (string) $hoja->footnote);
        $this->assertSame('No hay cuotas en efectivo listas para entregar.', $hoja->emptyMessage);
    }

    /**
     * Con una sola caja el dato va arriba; con varias, en su columna.
     *
     * Una columna que dice siempre lo mismo gasta ancho que en esta hoja lo
     * necesita el apellido.
     */
    public function test_la_caja_sube_al_encabezado_cuando_es_una_sola(): void
    {
        $hoja = $this->hojaDeMostrador([
            $this->filaDeMostrador('34-000001/2026', 'PÉREZ, Juan', '1000.50', 'Caja Haberes'),
            $this->filaDeMostrador('34-000002/2026', 'GÓMEZ, Ana', '2500.00', 'Caja Haberes'),
        ]);

        $this->assertStringContainsString('Caja Haberes', (string) $hoja->subtitle);
        $this->assertSame(
            ['Expediente', 'Beneficiario', 'Documento', 'Haber / Cuota', 'Concepto', 'Importe', 'Recibo ingreso', 'Retirado'],
            array_map(fn ($columna): string => $columna->label, $hoja->columns),
        );
    }

    public function test_con_dos_cajas_aparece_la_columna(): void
    {
        $hoja = $this->hojaDeMostrador([
            $this->filaDeMostrador('34-000001/2026', 'PÉREZ, Juan', '1000.50', 'Caja Haberes'),
            $this->filaDeMostrador('34-000002/2026', 'GÓMEZ, Ana', '2500.00', 'Caja Aranceles'),
        ]);

        $this->assertContains(
            'Caja',
            array_map(fn ($columna): string => $columna->label, $hoja->columns),
        );
    }

    /** Cada fila dice en qué mitad del circuito quedó trabada. */
    public function test_la_hoja_de_transferencias_escribe_el_estado_de_cada_egreso(): void
    {
        $hoja = app(UnconfirmedTransferSheet::class)->build(
            [$this->filaDeTransferencia(DisbursementStatus::ReportReceived)],
            '1000.00',
            CarbonImmutable::parse('2026-06-25 11:00'),
            'Quien la pidió',
        );

        // Orden, informe, débito y estado, en ese orden.
        $this->assertSame('OP 0001-00000123', $hoja->rows[0][4]);
        $this->assertSame('20/06/2026', $hoja->rows[0][5]);
        $this->assertSame('—', $hoja->rows[0][6]);
        $this->assertSame('Transferencia informada', $hoja->rows[0][7]);
    }

    /** Tildar la hoja no postea ningún asiento, y el papel lo dice. */
    public function test_la_hoja_de_transferencias_se_declara_hoja_de_trabajo(): void
    {
        $hoja = app(UnconfirmedTransferSheet::class)->build(
            [],
            '0',
            CarbonImmutable::parse('2026-06-25 11:00'),
            null,
        );

        $this->assertStringContainsString('no postea ningún asiento', (string) $hoja->footnote);
        $this->assertSame('No hay transferencias esperando confirmación.', $hoja->emptyMessage);
    }

    /** El nombre del archivo lleva el día: la hoja de ayer no es la de hoy. */
    public function test_el_archivo_se_nombra_con_la_fecha(): void
    {
        $hoja = $this->hojaDeMostrador([]);

        $this->assertSame('planilla-mostrador-2026-06-25.pdf', $hoja->filename());
    }

    /** @param  list<CounterPayoutRowData>  $filas */
    private function hojaDeMostrador(array $filas, string $total = '0'): WorksheetData
    {
        return app(CounterPayoutSheet::class)->build(
            $filas,
            $total,
            CarbonImmutable::parse('2026-06-25 11:00'),
            'Quien la pidió',
        );
    }

    private function filaDeMostrador(
        string $expediente,
        string $beneficiario,
        string $importe,
        ?string $caja = null,
    ): CounterPayoutRowData {
        return new CounterPayoutRowData(
            installmentId: 1,
            expedienteId: 1,
            haberNumber: 1,
            expedienteNumber: $expediente,
            beneficiaryName: $beneficiario,
            beneficiaryDocument: '38.357.040',
            installmentLabel: 'Haber 1 · Cuota 1 de 3',
            employerName: 'CIACSA S.A.',
            concept: 'Indemnización',
            amount: $importe,
            incomeReceiptNumber: 'RI 0001-00000045',
            cashBoxName: $caja,
        );
    }

    private function filaDeTransferencia(DisbursementStatus $estado): UnconfirmedTransferRowData
    {
        return new UnconfirmedTransferRowData(
            disbursementId: 1,
            installmentId: 1,
            expedienteId: 1,
            haberNumber: 1,
            expedienteNumber: '34-000001/2026',
            beneficiaryName: 'PÉREZ, Juan',
            installmentLabel: 'Haber 1 · Cuota 1',
            amount: '1000.00',
            paymentOrderNumber: 'OP 0001-00000123',
            reportedAt: '2026-06-20',
            debitObservedAt: null,
            status: $estado,
            missingStep: 'Falta que el débito aparezca en el extracto.',
            waitingDays: 5,
        );
    }
}
