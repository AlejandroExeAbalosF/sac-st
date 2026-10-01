<?php

declare(strict_types=1);

namespace App\Modules\Haberes\Support;

use App\Modules\Haberes\Models\LegacyDocument;
use App\Modules\Shared\Enums\AttachmentSubject;
use App\Modules\Shared\Models\Attachment;
use App\Support\Money\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/**
 * Los controles de un papel del sistema anterior antes de guardarlo.
 *
 * El error más probable de una carga manual de muchos expedientes es
 * cargar dos veces el mismo papel. Hay dos niveles:
 *
 * - **El mismo número el mismo día** es el mismo papel, sin duda: se
 *   rechaza, y la base lo vuelve a rechazar con su índice único.
 * - **El mismo número con otra fecha** puede ser otro papel —no se sabe si
 *   la numeración de los talonarios se reinicia— o el mismo con la fecha
 *   mal tipeada. Se avisa, y pasa si el operador confirma.
 *
 * La foto ya cargada se trata como el segundo caso: casi siempre es el
 * mismo papel, pero dos fotos idénticas de papeles distintos no se pueden
 * descartar del todo.
 */
final class LegacyPaperCheck
{
    /**
     * @param  list<LegacyPaper>  $papers
     *
     * @throws ValidationException
     */
    public function assertBefore(array $papers, CarbonInterface $opening): void
    {
        $errores = [];

        foreach ($papers as $papel) {
            if ($papel->issuedOn->greaterThanOrEqualTo($opening)) {
                $errores[$papel->field.'Date'] = sprintf(
                    'El papel tiene que ser anterior a la apertura de la caja (%s).',
                    $opening->format('d/m/Y'),
                );
            }
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }
    }

    /**
     * @param  list<LegacyPaper>  $papers
     *
     * @throws ValidationException
     */
    public function assertNotLoaded(array $papers, bool $confirmed): void
    {
        $errores = [];
        $avisos = [];

        foreach ($papers as $papel) {
            $existentes = LegacyDocument::query()
                ->current()
                ->with('installment.haber.beneficiary:id,name', 'installment.haber.expediente:id,display_number')
                ->where('kind', $papel->kind->value)
                ->whereRaw('upper(btrim(number)) = upper(btrim(?))', [$papel->number])
                ->get();

            foreach ($existentes as $existente) {
                if ($existente->issued_on->isSameDay($papel->issuedOn)) {
                    $errores[$papel->field.'Number'] = sprintf(
                        'El %s n.º %s del %s ya está cargado: %s.',
                        mb_strtolower($papel->kind->label()),
                        $existente->number,
                        $existente->issued_on->format('d/m/Y'),
                        $this->whereIs($existente),
                    );

                    continue;
                }

                $avisos[$papel->field.'Number'] = sprintf(
                    'Ya hay un %s n.º %s con fecha %s, por %s: %s. Si es otro papel, confirmalo.',
                    mb_strtolower($papel->kind->label()),
                    $existente->number,
                    $existente->issued_on->format('d/m/Y'),
                    Decimal::format($existente->amount),
                    $this->whereIs($existente),
                );
            }
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        if ($avisos !== [] && ! $confirmed) {
            throw ValidationException::withMessages([
                ...$avisos,
                'confirmDuplicates' => 'Revisá los avisos: si son papeles distintos, confirmalo y volvé a guardar.',
            ]);
        }
    }

    /**
     * Si alguna foto recién guardada ya estaba cargada para otro papel.
     *
     * Se mira después de guardar porque el hash es el de la imagen ya
     * normalizada, que es lo que `StoreAttachment` compara.
     *
     * @param  list<Attachment>  $stored
     * @param  list<LegacyPaper>  $papers
     *
     * @throws ValidationException
     */
    public function assertPhotosNotLoaded(array $stored, array $papers, bool $confirmed): void
    {
        if ($confirmed || $stored === []) {
            return;
        }

        $propios = array_map(fn (Attachment $adjunto): int => $adjunto->id, $stored);
        $avisos = [];

        foreach ($stored as $indice => $adjunto) {
            $repetida = Attachment::query()
                ->where('subject_type', AttachmentSubject::LegacyDocument->value)
                ->where('sha256', $adjunto->sha256)
                ->whereNotIn('id', $propios)
                ->exists();

            if (! $repetida) {
                continue;
            }

            $papel = $this->paperOf($adjunto, $papers) ?? $papers[$indice] ?? null;

            if ($papel !== null) {
                $avisos[$papel->field.'Photo'] = 'Esta foto ya está cargada para otro papel del sistema anterior. Si es otro papel, confirmalo.';
            }
        }

        if ($avisos !== []) {
            throw ValidationException::withMessages([
                ...$avisos,
                'confirmDuplicates' => 'Revisá los avisos: si son papeles distintos, confirmalo y volvé a guardar.',
            ]);
        }
    }

    /** @param  list<LegacyPaper>  $papers */
    private function paperOf(Attachment $adjunto, array $papers): ?LegacyPaper
    {
        foreach ($papers as $papel) {
            if ($adjunto->document_type === 'legacy_'.$papel->kind->value) {
                return $papel;
            }
        }

        return null;
    }

    private function whereIs(LegacyDocument $documento): string
    {
        $cuota = $documento->installment;
        $haber = $cuota->haber;

        return sprintf(
            'cuota %d de %s, expediente %s',
            $cuota->installment_number,
            $haber->beneficiary->name,
            $haber->expediente->display_number,
        );
    }
}
