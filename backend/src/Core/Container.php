<?php
declare(strict_types=1);

namespace Sigep\Core;

/** Contêiner mínimo com autowiring por reflexão. As interfaces de repositório são ligadas em App.php. */
final class Container
{
    private array $bindings = [];
    private array $instances = [];

    public function bind(string $abstract, callable|string $concrete): void
    {
        $this->bindings[$abstract] = $concrete;
        unset($this->instances[$abstract]);
    }

    public function instance(string $abstract, object $obj): void
    {
        $this->instances[$abstract] = $obj;
    }

    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        if (isset($this->bindings[$id])) {
            $b = $this->bindings[$id];
            $obj = is_callable($b) ? $b($this) : $this->get($b);
            return $this->instances[$id] = $obj;
        }
        if (!class_exists($id)) {
            throw new \RuntimeException("Dependência não registrada: $id");
        }
        $ref = new \ReflectionClass($id);
        $ctor = $ref->getConstructor();
        $args = [];
        if ($ctor) {
            foreach ($ctor->getParameters() as $p) {
                $t = $p->getType();
                if ($t instanceof \ReflectionNamedType && !$t->isBuiltin()) {
                    $args[] = $this->get($t->getName());
                } elseif ($p->isDefaultValueAvailable()) {
                    $args[] = $p->getDefaultValue();
                } else {
                    throw new \RuntimeException("Não foi possível resolver \${$p->getName()} de $id");
                }
            }
        }
        return $this->instances[$id] = $ref->newInstanceArgs($args);
    }
}
