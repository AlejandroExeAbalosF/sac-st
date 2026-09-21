<?php

declare(strict_types=1);

namespace App\Support\Navigation;

/**
 * Un eslabón del camino de migas.
 *
 * `href` es opcional a propósito: hay eslabones que nombran una sección y no
 * una pantalla —«Configuración», por ejemplo— y llevarlos a algún lado sería
 * inventar un destino. Sin `href` se dibujan como texto.
 */
final readonly class Crumb
{
    public function __construct(
        public string $title,
        public ?string $href = null,
    ) {}

    public static function make(string $title, ?string $href = null): self
    {
        return new self($title, $href);
    }

    /**
     * @return array{title: string, href: string|null}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'href' => $this->href,
        ];
    }
}
