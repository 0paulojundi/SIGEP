<?php
declare(strict_types=1);

namespace Sigep\Storage;

/**
 * Armazenamento TEMPORÁRIO (substitui o banco nesta etapa).
 * TODO(DB): quando o MySQL existir, esta interface deixa de ser usada — os repositórios PDO a substituem.
 */
interface Store
{
    /** @return array<int,array> linhas da coleção, na ordem de inserção */
    public function all(string $collection): array;
    public function find(string $collection, string $id): ?array;
    /** A linha precisa ter a chave 'id'. Lança exceção se o id já existir. */
    public function insert(string $collection, array $row): array;
    public function update(string $collection, string $id, array $changes): ?array;
    public function delete(string $collection, string $id): bool;
    /** Próximo id no padrão PREFIXO + número com zeros à esquerda (ex.: A0035). */
    public function nextId(string $collection, string $prefix, int $pad = 3): string;
}
