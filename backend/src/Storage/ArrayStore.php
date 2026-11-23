<?php
declare(strict_types=1);

namespace Sigep\Storage;

/** Armazenamento só em memória do processo (testes automatizados). */
class ArrayStore implements Store
{
    /** @var array<string,array<string,array>> */
    protected array $data = [];

    public function all(string $collection): array { return array_values($this->load($collection)); }
    public function find(string $collection, string $id): ?array { return $this->load($collection)[$id] ?? null; }

    public function insert(string $collection, array $row): array
    {
        $rows = $this->load($collection);
        $id = (string) ($row['id'] ?? '');
        if ($id === '' || isset($rows[$id])) {
            throw new \RuntimeException("Id inválido ou duplicado em $collection: '$id'");
        }
        $rows[$id] = $row;
        $this->save($collection, $rows);
        return $row;
    }

    public function update(string $collection, string $id, array $changes): ?array
    {
        $rows = $this->load($collection);
        if (!isset($rows[$id])) {
            return null;
        }
        unset($changes['id']);
        $rows[$id] = array_merge($rows[$id], $changes);
        $this->save($collection, $rows);
        return $rows[$id];
    }

    public function delete(string $collection, string $id): bool
    {
        $rows = $this->load($collection);
        if (!isset($rows[$id])) {
            return false;
        }
        unset($rows[$id]);
        $this->save($collection, $rows);
        return true;
    }

    public function nextId(string $collection, string $prefix, int $pad = 3): string
    {
        $max = 0;
        foreach (array_keys($this->load($collection)) as $id) {
            if (str_starts_with((string) $id, $prefix) && ctype_digit(substr((string) $id, strlen($prefix)))) {
                $max = max($max, (int) substr((string) $id, strlen($prefix)));
            }
        }
        return $prefix . str_pad((string) ($max + 1), $pad, '0', STR_PAD_LEFT);
    }

    protected function load(string $collection): array { return $this->data[$collection] ?? []; }
    protected function save(string $collection, array $rows): void { $this->data[$collection] = $rows; }
}
