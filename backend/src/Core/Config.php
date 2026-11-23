<?php
declare(strict_types=1);

namespace Sigep\Core;

/** Acesso às listas e parâmetros de config/app.php. */
final class Config
{
    public function __construct(private array $items)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->items[$key] ?? $default;
    }
}
