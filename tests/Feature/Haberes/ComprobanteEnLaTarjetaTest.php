<?php

declare(strict_types=1);

namespace Tests\Feature\Haberes;

use App\Modules\Banking\Models\BankAccount;
use App\Modules\Haberes\Enums\DepositKind;
use App\Modules\Haberes\Enums\DepositTicketStatus;
use App\Modules\Haberes\Models\BeneficiaryInstallment;
use App\Modules\Haberes\Models\DepositTicket;
use App\Modules\Haberes\Models\Expediente;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Models\Attachment;
use Database\Seeders\HaberesDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * El comprobante que muestra la tarjeta es el suyo, en las dos pantallas.
 *
 * La foto y la recepción del ticket se buscan en lote —una consulta para
 * todo el plan, no una por cuota—, y el lote se arma antes de saber cuál
 * de los comprobantes va a mostrarse. Estos tests fijan las dos cosas que
 * ese atajo puede romper: que el lote quede indexado por ticket y no por
 * cuota, y que las dos pantallas lo pidan.
 *
 * No es teórico. Una cuota tiene varios comprobantes cuando el depósito
 * llegó fraccionado (§2.1.9), y agrupar por cuota hacía que la tarjeta
 * mostrara un ticket con la foto de otro.
 */
class ComprobanteEnLaTarjetaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(HaberesDemoSeeder::class);
    }

    /**
     * Con dos comprobantes vigentes, la foto es la del que se muestra.
     *
     * La tarjeta enseña el más nuevo. Si el adjunto se resolviera por
     * cuota, mostraría el del otro papel: el operador vería un importe y
     * una foto que no se corresponden, y el error no se nota mirando.
     */
    public function test_con_dos_comprobantes_la_foto_es_la_del_que_se_muestra(): void
    {
        $cuota = $this->cuota();

        $viejo = $this->comprobante($cuota, '2026-04-20');
        $nuevo = $this->comprobante($cuota, '2026-04-24');

        /*
         * El adjunto del comprobante viejo se carga último, a propósito:
         * así es el de id más alto. Agrupar los adjuntos por cuota y
         * quedarse con el `MAX()` devolvía justamente ese, y el orden
         * inverso haría que el error acertara por casualidad.
         */
        $delNuevo = $this->adjuntoDe($nuevo);
        $delViejo = $this->adjuntoDe($viejo);

        $this->actingAs($this->operador())
            ->get(route('haberes.haber.show', [$cuota->haber->expediente, $cuota->haber]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('haber.installments.0.depositTicket.id', $nuevo->id)
                ->where('haber.installments.0.depositTicket.attachmentId', $delNuevo->id));

        $this->assertNotSame(
            $delViejo->id,
            $delNuevo->id,
            'Los dos comprobantes tienen que tener adjuntos distintos para que el test pruebe algo.',
        );
    }

    /**
     * Y la pantalla del expediente muestra la foto igual que la del haber.
     *
     * Las dos arman la misma tarjeta. Cuando la consulta por ticket salió
     * del DTO, esta pantalla se quedó sin quien la hiciera y el
     * comprobante cargado desapareció sin que nada fallara.
     */
    public function test_la_pantalla_del_expediente_tambien_trae_la_foto(): void
    {
        $cuota = $this->cuota();
        $ticket = $this->comprobante($cuota, '2026-04-24');
        $adjunto = $this->adjuntoDe($ticket);

        $this->actingAs($this->operador())
            ->followingRedirects()
            ->get(route('expedientes.show', $cuota->haber->expediente_id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where(
                    'expediente.haberes.0.installments.0.depositTicket.attachmentId',
                    $adjunto->id,
                ));
    }

    /*
    |---------------------------------------------------------------------
    | Andamiaje
    |---------------------------------------------------------------------
    */

    private function cuota(): BeneficiaryInstallment
    {
        return Expediente::query()
            ->whereNotNull('employer_id')
            ->orderBy('id')
            ->firstOrFail()
            ->haberes()->orderBy('id')->firstOrFail()
            ->installments()->orderBy('installment_number')->firstOrFail();
    }

    private function comprobante(BeneficiaryInstallment $cuota, string $fecha): DepositTicket
    {
        return DepositTicket::query()->create([
            'beneficiary_installment_id' => $cuota->id,
            'haber_id' => $cuota->haber_id,
            'expediente_id' => $cuota->haber->expediente_id,
            'bank_account_id' => $this->cuenta()->id,
            'amount' => '1000.00',
            'deposited_at' => $fecha,
            'deposit_kind' => DepositKind::CashDeposit,
            'status' => DepositTicketStatus::Waiting,
            'created_by' => $this->operador()->id,
        ]);
    }

    private function cuenta(): BankAccount
    {
        return BankAccount::query()->firstOrCreate(
            ['account_number' => '23456789'],
            [
                'label' => 'Cta. Cte. 2693 — Haberes en consignación',
                'bank_name' => 'Banco Macro',
                'currency' => 'ARS',
                'is_active' => true,
            ],
        );
    }

    private function adjuntoDe(DepositTicket $ticket): Attachment
    {
        return Attachment::query()->create([
            'subject_type' => AttachmentSubject::DepositTicket->value,
            'subject_id' => $ticket->id,
            'document_type' => 'deposit_ticket',
            'title' => 'Comprobante '.$ticket->id,
            'storage_disk' => 'local',
            'object_key' => 'tickets/'.$ticket->id.'.jpg',
            'original_filename' => 'ticket.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'sha256' => hash('sha256', (string) $ticket->id),
            'source' => 'scanned',
            'created_by' => $this->operador()->id,
        ]);
    }
}
