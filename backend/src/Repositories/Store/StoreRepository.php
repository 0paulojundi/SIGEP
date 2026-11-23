<?php
declare(strict_types=1);

namespace Sigep\Repositories\Store;

use Sigep\Repositories\Contracts\RepositoryInterface;
use Sigep\Storage\Store;

/** Base das implementações TEMPORÁRIAS (sobre Store). TODO(DB): equivalentes em Repositories/Pdo. */
abstract class StoreRepository implements RepositoryInterface
{
    protected string $collection = '';
    protected string $prefix = 'X';
    protected int $pad = 3;

    public function __construct(protected Store $store) {}

    public function all(): array { return $this->store->all($this->collection); }
    public function find(string $id): ?array { return $this->store->find($this->collection, $id); }

    public function create(array $row): array
    {
        if (empty($row['id'])) {
            $row['id'] = $this->store->nextId($this->collection, $this->prefix, $this->pad);
        }
        return $this->store->insert($this->collection, $row);
    }

    public function update(string $id, array $changes): ?array { return $this->store->update($this->collection, $id, $changes); }
    public function delete(string $id): bool { return $this->store->delete($this->collection, $id); }

    protected function where(callable $fn): array { return array_values(array_filter($this->all(), $fn)); }
}
