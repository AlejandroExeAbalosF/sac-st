<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Modules\Shared\Actions\StoreAttachment;
use App\Modules\Shared\Enums\AttachmentSubject;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * El archivo se escribe antes que su fila. Si la base rechaza la fila, el
 * archivo no puede quedar en el disco sin nada que lo nombre.
 */
class AdjuntoRechazadoTest extends TestCase
{
    use RefreshDatabase;

    public function test_si_la_base_rechaza_la_fila_el_archivo_no_queda_en_el_disco(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION test_rechaza_adjunto() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'adjunto rechazado por el test';
            END $$ LANGUAGE plpgsql;
            CREATE TRIGGER test_rechaza_adjunto BEFORE INSERT ON attachments
                FOR EACH ROW EXECUTE FUNCTION test_rechaza_adjunto();
            SQL);

        try {
            app(StoreAttachment::class)->handle(
                file: UploadedFile::fake()->create('papel.pdf', 20, 'application/pdf'),
                subject: AttachmentSubject::Fine,
                subjectId: 1,
                documentType: 'fine_support',
                userId: null,
            );
            $this->fail('La base tenía que rechazar el adjunto.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('adjunto rechazado por el test', $exception->getMessage());
        }

        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
