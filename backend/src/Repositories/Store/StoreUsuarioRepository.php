<?php
declare(strict_types=1);

namespace Sigep\Repositories\Store;

use Sigep\Repositories\Contracts\UsuarioRepositoryInterface;

final class StoreUsuarioRepository extends StoreRepository implements UsuarioRepositoryInterface
{
    protected string $collection = 'usuarios';
    protected string $prefix = 'U';

    public function findByEmail(string $email): ?array
    {
        $email = mb_strtolower(trim($email));
        return $this->where(fn ($u) => mb_strtolower($u['email']) === $email)[0] ?? null;
    }

    public function findByCpf(string $cpf): ?array
    {
        return $cpf === '' ? null : ($this->where(fn ($u) => ($u['cpf'] ?? '') === $cpf)[0] ?? null);
    }

    public function byPerfil(string $perfil): array { return $this->where(fn ($u) => $u['perfil'] === $perfil); }
}
