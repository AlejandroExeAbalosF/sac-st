<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Shared\Audit\AuditActionDefinition;
use App\Modules\Shared\Audit\AuditCatalog;
use App\Modules\Shared\Audit\AuditSubjectResolver;
use App\Modules\Shared\Data\OperationAuditEventData;
use App\Modules\Shared\Http\Requests\OperationAuditFilterRequest;
use App\Modules\Shared\Models\AuditEvent;
use App\Modules\Shared\Support\OperationAuditQuery;
use App\Support\BusinessDate;
use App\Support\Excel\SpreadsheetWriter;
use Carbon\CarbonImmutable;
use Generator;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Qué se hizo en el sistema, quién y cuándo, de punta a punta.
 *
 * Los historiales de cada expediente responden por una cosa; esta pantalla
 * responde las preguntas que no empiezan por un expediente: quién anuló
 * cobros este mes, quién reabrió un período, quién forzó un CBU. Es de
 * solo lectura sin excepciones: los eventos no se editan ni se marcan, y
 * la base lo impide de todos modos.
 */
final class OperationAuditController extends Controller
{
    public function __construct(
        private readonly AuditCatalog $catalog,
        private readonly OperationAuditQuery $auditoria,
    ) {}

    public function index(OperationAuditFilterRequest $request): Response
    {
        $filters = $request->filters();
        $pagina = $this->auditoria->query($filters)->paginate(50)->withQueryString();

        // Se presenta la página entera de una vez —una consulta por tipo de
        // sujeto, no una por fila— y después cada evento toma el suyo.
        $presentados = collect($this->auditoria->present($pagina->getCollection(), $request->user()))
            ->keyBy(fn (OperationAuditEventData $evento): int => $evento->id);

        return Inertia::render('configuracion/auditoria', [
            'events' => $pagina->through(
                fn (AuditEvent $evento): OperationAuditEventData => $presentados->get($evento->id)
                    ?? throw new LogicException("El evento {$evento->id} no se presentó."),
            ),
            'users' => User::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])
                ->values()
                ->all(),
            'actionGroups' => array_map(
                fn (array $grupo): array => [
                    'category' => $grupo['category'],
                    'actions' => array_map(
                        fn (AuditActionDefinition $accion): array => [
                            'value' => $accion->code,
                            'label' => $accion->label,
                            'critical' => $accion->isCritical(),
                        ],
                        $grupo['actions'],
                    ),
                ],
                $this->catalog->actionsByCategory(),
            ),
            'subjects' => array_map(
                fn (AuditSubjectResolver $sujeto): array => ['value' => $sujeto->subjectType(), 'label' => $sujeto->label()],
                $this->catalog->subjects(),
            ),
            'filters' => $filters,
            'can' => [
                'export' => $request->user()?->can('auditoria.operaciones.exportar') ?? false,
            ],
        ]);
    }

    /**
     * Lo mismo que la pantalla, en una planilla: una fila por cambio.
     *
     * Una fila por cambio y no por evento porque es como se trabaja en la
     * planilla: filtrar por «Importe» o por un CBU en particular exige que
     * cada dato tenga su celda. Un evento sin cambios ocupa igual una fila,
     * para que la cuenta de eventos cierre.
     */
    public function export(OperationAuditFilterRequest $request): StreamedResponse
    {
        $filters = $request->filters();
        $viewer = $request->user();

        return SpreadsheetWriter::download(
            sprintf('auditoria-%s.xlsx', BusinessDate::today()->format('Y-m-d')),
            [
                'N.º evento',
                'Fecha y hora (Salta)',
                'Usuario',
                'Código de acción',
                'Acción',
                'Crítica',
                'Entidad',
                'ID entidad',
                'Descripción',
                'Campo',
                'Antes',
                'Después',
                'IP',
            ],
            $this->rows($filters, $viewer),
        );
    }

    /**
     * @param  array{desde: string|null, hasta: string|null, usuario: int|'sistema'|null, accion: string|null, entidad: string|null, criticas: bool}  $filters
     * @return Generator<int, list<string|int|null>>
     */
    private function rows(array $filters, ?User $viewer): Generator
    {
        $zona = (string) config('app.display_timezone');

        foreach ($this->auditoria->chunks($filters) as $bloque) {
            foreach ($this->auditoria->present($bloque, $viewer) as $evento) {
                $comun = $this->common($evento, $zona);
                $cambios = $evento->changes === [] ? [null] : $evento->changes;

                foreach ($cambios as $cambio) {
                    yield [
                        ...$comun,
                        $cambio?->field,
                        $cambio?->before,
                        $cambio?->after,
                        $evento->ipAddress,
                    ];
                }
            }
        }
    }

    /**
     * @return list<string|int|null>
     */
    private function common(OperationAuditEventData $evento, string $zona): array
    {
        return [
            $evento->id,
            CarbonImmutable::parse($evento->occurredAt)->timezone($zona)->format('d/m/Y H:i:s'),
            $evento->userName ?? 'Sistema',
            $evento->action,
            $evento->label,
            $evento->critical ? 'Sí' : 'No',
            $evento->subjectLabel,
            $evento->subjectId,
            $evento->subjectDescription,
        ];
    }
}
