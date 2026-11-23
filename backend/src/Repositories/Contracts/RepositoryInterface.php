<?php
declare(strict_types=1);

namespace Sigep\Repositories\Contracts;

/**
 * Contrato base de persistência. Os serviços só conhecem estas interfaces:
 * trocar a implementação (arquivos JSON → PDO/MySQL) não exige mexer em regras de negócio.
 */
interface RepositoryInterface
{
    public function all(): array;
    public function find(string $id): ?array;
    /** Cria a linha; gera o 'id' se não vier preenchido. Retorna a linha gravada. */
    public function create(array $row): array;
    public function update(string $id, array $changes): ?array;
    public function delete(string $id): bool;
}
