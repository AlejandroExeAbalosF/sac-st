<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Shared\Actions\UpdatePerson;
use App\Modules\Shared\Http\Requests\ResolvePersonRequest;
use App\Modules\Shared\Http\Requests\SearchPeopleRequest;
use App\Modules\Shared\Http\Requests\StorePersonRequest;
use App\Modules\Shared\Http\Requests\UpdatePersonRequest;
use App\Modules\Shared\Models\Person;
use App\Modules\Shared\Models\PersonBankAccount;
use App\Support\Database\Like;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class PersonController extends Controller
{
    private const RESULT_LIMIT = 20;

    /**
     * Pantalla del maestro.
     *
     * Existe porque el documento del empleador es opcional: alguien
     * cargado hoy con la razón social nada más tiene que poder recibir su
     * CUIT cuando llegue el expediente siguiente. Sin esta pantalla, un
     * nombre mal tipeado queda impreso en los comprobantes para siempre.
     */
    public function page(Request $request): Response
    {
        $termino = $request->string('q')->toString();
        $texto = Person::normalizeName($termino);
        $digitos = preg_replace('/\D+/', '', $termino) ?? '';

        $personas = Person::query()
            ->with(['roles:person_id,role', 'owner'])
            ->when($texto !== '', function ($query) use ($texto, $digitos): void {
                $query->where(function ($persona) use ($texto, $digitos): void {
                    $persona->where('search_name', 'like', Like::contains($texto));

                    if ($digitos !== '') {
                        $persona->orWhere('document', 'like', Like::contains($digitos));
                    }
                });
            })
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (Person $persona): array => [
                ...$persona->toOption(),
                // Los tres campos que edita el diálogo de corrección. El
                // que corresponde según el tipo viene con dato; los otros,
                // en `null`.
                'firstName' => $persona->first_name,
                'lastName' => $persona->last_name,
                'legalName' => $persona->legal_name,
                'owner' => $persona->owner?->toOption(),
                'taxIdentifier' => $persona->tax_identifier,
                'isActive' => $persona->is_active,
                'roles' => $persona->roles->pluck('role')->values()->all(),
            ]);

        return Inertia::render('personas/index', [
            'personas' => $personas,
            'filters' => ['q' => $termino],
        ]);
    }

    public function update(UpdatePersonRequest $request, Person $person, UpdatePerson $actualizar): RedirectResponse
    {
        $actualizar->handle($person, $request->personAttributes());

        return back()->with('status', "Ficha de {$person->name} actualizada.");
    }

    public function index(SearchPeopleRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = Person::query()->active();

        if (isset($data['role'])) {
            $query->forRole($data['role']);
        }

        if (isset($data['type'])) {
            $query->where('type', $data['type']);
        }

        $rawSearch = $request->string('q')->toString();
        $search = Person::normalizeName($rawSearch);
        $document = preg_replace('/\D+/', '', $rawSearch) ?? '';

        if ($search !== '') {
            $query->where(function ($people) use ($search, $document): void {
                $people->where('search_name', 'like', Like::contains($search));

                if ($document !== '') {
                    $people->orWhere('document', 'like', Like::contains($document));
                }
            });
        }

        $people = $query
            ->orderBy('name')
            ->limit(self::RESULT_LIMIT + 1)
            ->get();

        return response()->json([
            'data' => $people
                ->take(self::RESULT_LIMIT)
                ->map(fn (Person $person): array => $person->toOption())
                ->values(),
            'meta' => [
                'hasMore' => $people->count() > self::RESULT_LIMIT,
            ],
        ]);
    }

    /**
     * La persona física que tiene ese documento, si está en el maestro.
     *
     * Contesta mientras se tipea, para que el titular de una organización
     * se resuelva sin abrir un buscador aparte. Un paso que hay que
     * acordarse de dar es un paso que el día apurado no se da, y ahí entra
     * la segunda ficha del mismo humano.
     */
    public function resolve(ResolvePersonRequest $request): JsonResponse
    {
        $persona = Person::query()
            ->where('type', 'individual')
            ->where('document', $request->documento())
            ->first();

        return response()->json([
            'data' => $persona?->toOption(),
            // El DNI ya normalizado: si vino un CUIL, es el que tenía adentro.
            'documentNumber' => $request->documento(),
        ]);
    }

    public function store(StorePersonRequest $request): JsonResponse
    {
        $data = $request->validated();
        $existing = $this->findExisting($request->documento(), $request->nombre());

        if ($existing !== null) {
            return $this->reuse($existing, $request->rol(), $request->cbu(), $request->identificadorTributario());
        }

        try {
            [$person, $bankAccount] = DB::transaction(function () use ($request): array {
                $person = Person::query()->create([
                    ...$request->personAttributes(),
                    'owner_person_id' => $this->owner($request),
                    'is_active' => true,
                    'created_by' => $request->user()?->id,
                ]);

                $this->attachRole($person, $request->rol());

                return [$person, $this->bankAccount($person, $request->cbu())];
            });
        } catch (UniqueConstraintViolationException $excepcion) {
            // Dos operadores pudieron dar de alta la misma ficha al mismo
            // tiempo. La restricción gana y reutilizamos esa fila.
            $person = $this->findExisting($request->documento(), $request->nombre());

            if ($person === null) {
                throw $excepcion;
            }

            return $this->reuse($person, $request->rol(), $request->cbu(), $request->identificadorTributario());
        }

        return response()->json([
            'data' => $person->toOption(),
            'bankAccount' => $bankAccount?->toOption(),
            'reused' => false,
            'message' => $bankAccount === null
                ? 'Persona registrada y seleccionada.'
                : 'Persona y CBU registrados y seleccionados.',
        ], 201);
    }

    /**
     * La ficha que ya existe para estos datos, si la hay.
     *
     * Con documento manda el documento; sin documento manda el nombre
     * normalizado. Es el mismo reparto que hacen los dos índices parciales.
     *
     * No busca por nombre entre las que sí tienen documento: dos personas
     * distintas pueden llamarse igual, y unificarlas en silencio le
     * atribuiría plata a quien no corresponde. Ese caso lo frena el
     * `FormRequest` con un mensaje que apunta a la ficha existente.
     */
    private function findExisting(?string $documento, string $nombre): ?Person
    {
        if ($documento !== null) {
            return Person::query()->where('document', $documento)->first();
        }

        return Person::query()
            ->whereNull('document')
            ->where('search_name', Person::normalizeName($nombre))
            ->first();
    }

    private function reuse(Person $person, ?string $role, ?string $cbu, ?string $taxIdentifier): JsonResponse
    {
        /*
         * Acá el tipo lo manda la ficha existente, no lo que se envió en el
         * formulario, así que la validación del FormRequest no alcanza: la
         * base rechazaría el rol con un CHECK y el operador vería un error
         * sin explicación. El mensaje legible sale de este lado.
         */
        if ($role === 'beneficiary' && $person->type !== 'individual') {
            throw ValidationException::withMessages([
                'document' => 'Esa ficha ya está registrada como organización, y un beneficiario tiene que ser una persona física.',
            ]);
        }

        [$granted, $bankAccount] = DB::transaction(function () use ($person, $role, $cbu, $taxIdentifier): array {
            /*
             * El expediente trajo el CUIL de alguien que ya estaba cargado
             * solo con el DNI. Es el mismo caso que el domicilio o el
             * teléfono: el papel nuevo completa un dato que faltaba. Se
             * llena únicamente si estaba vacío —nada que ya tenga valor se
             * pisa desde un alta contextual— y el CHECK garantiza que ese
             * número corresponda a este documento.
             */
            if ($taxIdentifier !== null && $person->tax_identifier === null) {
                $person->forceFill(['tax_identifier' => $taxIdentifier])->save();
            }

            return [
                $this->attachRole($person, $role),
                $this->bankAccount($person, $cbu),
            ];
        });

        return response()->json([
            'data' => $person->toOption(),
            'bankAccount' => $bankAccount?->toOption(),
            'reused' => true,
            'message' => $granted
                ? 'Ya estaba en el maestro. Le agregamos este rol y la seleccionamos.'
                : 'Ya estaba en el maestro. Usamos la ficha existente.',
        ]);
    }

    /**
     * El titular de la organización que se está dando de alta.
     *
     * El alta contextual lo identifica por su documento y no por su id:
     * el operador tiene el papel delante y tipea el número. Si esa persona
     * ya está en el maestro se usa la ficha que hay —nunca se crea una
     * segunda—; si no está, se registra **sin rol**, porque el titular no
     * interviene en el circuito.
     */
    private function owner(StorePersonRequest $request): ?int
    {
        $documento = $request->documentoDelTitular();

        if ($documento === null) {
            return null;
        }

        $persona = Person::query()
            ->where('type', 'individual')
            ->where('document', $documento)
            ->first();

        return ($persona ?? Person::query()->create([
            'type' => 'individual',
            'first_name' => $request->nombreDelTitular(),
            'last_name' => $request->apellidoDelTitular(),
            'document' => $documento,
            'tax_identifier' => $request->identificadorTributarioDelTitular(),
            'is_active' => true,
            'created_by' => $request->user()?->id,
        ]))->id;
    }

    /**
     * @return bool si el rol no lo tenía y se le acaba de otorgar
     */
    private function attachRole(Person $person, ?string $role): bool
    {
        // Sin rol no hay nada que otorgar: es el caso del titular de una
        // organización, que está en el maestro pero no interviene.
        if ($role === null) {
            return false;
        }

        return DB::table('person_roles')->insertOrIgnore([
            'person_id' => $person->id,
            'role' => $role,
            'person_type' => $person->type,
            'created_at' => now(),
        ]) > 0;
    }

    private function bankAccount(Person $person, ?string $cbu): ?PersonBankAccount
    {
        if ($cbu === null) {
            return null;
        }

        $account = $person->bankAccounts()->firstOrCreate(
            ['cbu' => $cbu],
            [
                'verification_status' => 'unverified',
                'is_active' => true,
            ],
        );

        if (! $account->is_active) {
            throw ValidationException::withMessages([
                'cbu' => 'Ese CBU ya está dado de baja para esta persona. Revisá su ficha antes de usarlo.',
            ]);
        }

        if ($account->verification_status === 'rejected') {
            throw ValidationException::withMessages([
                'cbu' => $account->rejection_reason === null
                    ? 'Ese CBU fue rechazado y no puede volver a utilizarse.'
                    : "Ese CBU fue rechazado: {$account->rejection_reason}",
            ]);
        }

        return $account;
    }
}
