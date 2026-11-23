<?php
declare(strict_types=1);

namespace Sigep\Repositories\Pdo;

use Sigep\Database\Connection;
use Sigep\Repositories\Contracts\RepositoryInterface;

/**
 * Base da persistência em banco (PDO). Ainda NÃO usada: depende do esquema, que não foi criado nesta etapa.
 *
 * Como usar (veja README):
 *  1. crie as tabelas;
 *  2. em cada repositório PDO, declare $table, $idColumn e $columns (propriedade do array => coluna);
 *  3. implemente os métodos específicos do contrato (ex.: findByEmail) com $this->selectOne()/select().
 *
 * Todo SQL passa por prepared statements; nomes de colunas vêm de $columns (lista fixa), nunca do usuário.
 * TODO(DB): código ainda não testado contra um banco real.
 */
abstract class PdoRepository implements RepositoryInterface
{
    protected string $table = '';
    protected string $idColumn = 'id';
    /** @var array<string,string> chave do array (camelCase) => coluna (snake_case) */
    protected array $columns = [];
    protected string $prefix = 'X';
    protected int $pad = 3;

    public function __construct(protected Connection $db) {}

    public function all(): array { return array_map([$this, 'fromRow'], $this->select('SELECT * FROM ' . $this->table)); }

    public function find(string $id): ?array
    {
        $row = $this->selectOne('SELECT * FROM ' . $this->table . ' WHERE ' . $this->idColumn . ' = :id', ['id' => $id]);
        return $row ? $this->fromRow($row) : null;
    }

    public function create(array $row): array
    {
        if (empty($row['id'])) {
            $row['id'] = $this->nextId();
        }
        $cols = $this->toColumns($row);
        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $this->table, implode(', ', array_keys($cols)), implode(', ', array_map(fn ($c) => ':' . $c, array_keys($cols))));
        $this->run($sql, $cols);
        return $this->find((string) $row['id']) ?? $row;
    }

    public function update(string $id, array $changes): ?array
    {
        unset($changes['id']);
        $cols = $this->toColumns($changes);
        if ($cols) {
            $set = implode(', ', array_map(fn ($c) => "$c = :$c", array_keys($cols)));
            $this->run("UPDATE {$this->table} SET $set WHERE {$this->idColumn} = :__id", $cols + ['__id' => $id]);
        }
        return $this->find($id);
    }

    public function delete(string $id): bool
    {
        return $this->run('DELETE FROM ' . $this->table . ' WHERE ' . $this->idColumn . ' = :id', ['id' => $id]) > 0;
    }

    /** TODO(DB): gerar ids como no front (A0001...) ou migrar para AUTO_INCREMENT. */
    protected function nextId(): string
    {
        $max = (int) ($this->selectOne("SELECT MAX(CAST(SUBSTRING({$this->idColumn}, " . (strlen($this->prefix) + 1) . ') AS UNSIGNED)) AS m FROM ' . $this->table)['m'] ?? 0);
        return $this->prefix . str_pad((string) ($max + 1), $this->pad, '0', STR_PAD_LEFT);
    }

    protected function select(string $sql, array $params = []): array
    {
        $st = $this->db->pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    protected function selectOne(string $sql, array $params = []): ?array { return $this->select($sql, $params)[0] ?? null; }

    protected function run(string $sql, array $params = []): int
    {
        $st = $this->db->pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    protected function toColumns(array $row): array
    {
        $out = [];
        foreach ($this->columns as $key => $col) {
            if (array_key_exists($key, $row)) {
                $v = $row[$key];
                $out[$col] = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (is_bool($v) ? (int) $v : $v);
            }
        }
        return $out;
    }

    protected function fromRow(array $row): array
    {
        $out = [];
        foreach ($this->columns as $key => $col) {
            $out[$key] = $row[$col] ?? null;
        }
        return $out;
    }
}
