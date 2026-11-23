<?php
declare(strict_types=1);

namespace Sigep\Repositories\Pdo;

use Sigep\Repositories\Contracts\UsuarioRepositoryInterface;

/**
 * EXEMPLO de referência para os demais repositórios PDO.
 * TODO(DB): ajuste $table e $columns ao esquema real. Os outros repositórios (Aluno, Agendamento, Reuniao,
 * Notificacao, Auditoria, Disponibilidade, Instituicao, PasswordReset, LoginThrottle) seguem o mesmo molde:
 * crie Pdo<Nome>Repository, estenda PdoRepository e implemente a interface de Contracts/<Nome>RepositoryInterface.
 */
final class PdoUsuarioRepository extends PdoRepository implements UsuarioRepositoryInterface
{
    protected string $table = 'usuarios'; // TODO(DB)
    protected string $prefix = 'U';
    protected array $columns = [          // TODO(DB): conferir nomes das colunas
        'id' => 'id', 'perfil' => 'perfil', 'nome' => 'nome', 'email' => 'email', 'cpf' => 'cpf',
        'telefone' => 'telefone', 'senhaHash' => 'senha_hash', 'status' => 'status', 'prefs' => 'prefs',
        'criadoEm' => 'criado_em', 'atualizadoEm' => 'atualizado_em', 'ultimoLogin' => 'ultimo_login',
    ];

    public function findByEmail(string $email): ?array
    {
        $row = $this->selectOne('SELECT * FROM ' . $this->table . ' WHERE LOWER(email) = LOWER(:e)', ['e' => trim($email)]);
        return $row ? $this->fromRow($row) : null;
    }

    public function findByCpf(string $cpf): ?array
    {
        $row = $this->selectOne('SELECT * FROM ' . $this->table . ' WHERE cpf = :c', ['c' => $cpf]);
        return $row ? $this->fromRow($row) : null;
    }

    public function byPerfil(string $perfil): array
    {
        return array_map([$this, 'fromRow'], $this->select('SELECT * FROM ' . $this->table . ' WHERE perfil = :p', ['p' => $perfil]));
    }
}
