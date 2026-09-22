<?php

declare(strict_types=1);

namespace App\Modules\Shared\Audit;

use App\Models\User;
use LogicException;

/**
 * El único lugar donde se dice qué significa cada evento de auditoría.
 *
 * Antes los rótulos vivían en el panel de historial, del lado del front, y
 * se desfasaron: el panel traducía códigos que el servidor nunca emitió y
 * dejaba en crudo otros que sí. Con dos pantallas mirando los mismos
 * eventos, un segundo mapa hubiera sido el mismo error dos veces.
 *
 * Las registraciones duplicadas fallan al arrancar. Lo que no se registró
 * no rompe ninguna pantalla —se muestra con su código—, pero
 * `RecordAuditEvent` sí lo rechaza fuera de producción, que es donde
 * conviene enterarse.
 */
final class AuditCatalog
{
    /**
     * Los metadatos que se muestran en cualquier acción que los traiga.
     *
     * El motivo es la única parte del evento que explica por qué alguien
     * lo hizo, y vive en la metadata aun cuando la acción también cambia
     * campos.
     */
    private const COMMON_METADATA = ['motivo' => 'Motivo'];

    /** Adonde van los códigos que nadie registró. */
    private const FALLBACK_CATEGORY = 'Otras';

    /** @var array<string, int> */
    private array $categories = [];

    /** @var array<string, AuditActionDefinition> */
    private array $actions = [];

    /** @var array<string, AuditSubjectResolver> */
    private array $subjects = [];

    /** @var array<string, AuditReferenceResolver> campo => resolver */
    private array $references = [];

    public function registerCategory(string $name, int $order): void
    {
        if (isset($this->categories[$name])) {
            throw new LogicException("La categoría de auditoría «{$name}» ya está registrada.");
        }

        $this->categories[$name] = $order;
    }

    public function registerActions(AuditActionDefinition ...$definitions): void
    {
        foreach ($definitions as $definition) {
            if (isset($this->actions[$definition->code])) {
                throw new LogicException("La acción de auditoría «{$definition->code}» ya está registrada.");
            }

            if (! isset($this->categories[$definition->category])) {
                throw new LogicException(
                    "La acción «{$definition->code}» usa la categoría «{$definition->category}», que no está registrada.",
                );
            }

            $this->actions[$definition->code] = $definition;
        }
    }

    public function registerSubject(AuditSubjectResolver $resolver): void
    {
        $type = $resolver->subjectType();

        if (isset($this->subjects[$type])) {
            throw new LogicException("El sujeto de auditoría «{$type}» ya tiene quien lo describa.");
        }

        $this->subjects[$type] = $resolver;
    }

    public function registerReference(AuditReferenceResolver $resolver): void
    {
        foreach ($resolver->fields() as $field) {
            if (isset($this->references[$field])) {
                throw new LogicException("El campo «{$field}» ya tiene quien traduzca sus referencias.");
            }

            $this->references[$field] = $resolver;
        }
    }

    public function has(string $code): bool
    {
        return isset($this->actions[$code]);
    }

    /**
     * La definición de un código, o una provisoria que lo muestra tal cual.
     */
    public function action(string $code): AuditActionDefinition
    {
        return $this->actions[$code] ?? new AuditActionDefinition($code, $code, self::FALLBACK_CATEGORY);
    }

    /**
     * Los metadatos visibles de una acción: primero los suyos, después los
     * comunes.
     *
     * @return array<string, string>
     */
    public function metadataFor(string $code): array
    {
        return [...$this->action($code)->metadata, ...self::COMMON_METADATA];
    }

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_keys($this->actions);
    }

    /**
     * @return list<string>
     */
    public function criticalCodes(): array
    {
        return array_values(array_map(
            fn (AuditActionDefinition $definition): string => $definition->code,
            array_filter($this->actions, fn (AuditActionDefinition $definition): bool => $definition->isCritical()),
        ));
    }

    /**
     * Las acciones agrupadas por categoría, en el orden en que se
     * registraron las categorías y alfabético dentro de cada una.
     *
     * @return list<array{category: string, actions: list<AuditActionDefinition>}>
     */
    public function actionsByCategory(): array
    {
        $grupos = [];

        foreach ($this->actions as $definition) {
            $grupos[$definition->category][] = $definition;
        }

        uksort($grupos, fn (string $a, string $b): int => $this->categories[$a] <=> $this->categories[$b]);

        $resultado = [];

        foreach ($grupos as $category => $definitions) {
            usort($definitions, fn (AuditActionDefinition $a, AuditActionDefinition $b): int => strcmp($a->label, $b->label));
            $resultado[] = ['category' => $category, 'actions' => $definitions];
        }

        return $resultado;
    }

    public function subject(string $type): ?AuditSubjectResolver
    {
        return $this->subjects[$type] ?? null;
    }

    /**
     * Los sujetos registrados, ordenados por su nombre.
     *
     * @return list<AuditSubjectResolver>
     */
    public function subjects(): array
    {
        $subjects = array_values($this->subjects);
        usort($subjects, fn (AuditSubjectResolver $a, AuditSubjectResolver $b): int => strcmp($a->label(), $b->label()));

        return $subjects;
    }

    public function subjectLabel(string $type): string
    {
        return $this->subject($type)?->label() ?? $type;
    }

    /**
     * Nombre y enlace de cada sujeto, sin huecos.
     *
     * El que ya no existe, el de un tipo que nadie registró y el que el
     * resolver no supo nombrar salen como «Cuota #123»: estable, sin
     * enlace, y suficiente para buscarlo en la base si hace falta.
     *
     * @param  list<int>  $ids
     * @return array<int, AuditSubjectDescription>
     */
    public function describe(string $type, array $ids, ?User $viewer): array
    {
        $ids = array_values(array_unique($ids));
        $resolved = $ids === [] ? [] : ($this->subject($type)?->describe($ids, $viewer) ?? []);
        $label = $this->subjectLabel($type);

        $descriptions = [];

        foreach ($ids as $id) {
            $descriptions[$id] = $resolved[$id] ?? new AuditSubjectDescription("{$label} #{$id}");
        }

        return $descriptions;
    }

    /**
     * @return array<string, AuditReferenceResolver>
     */
    public function references(): array
    {
        return $this->references;
    }
}
