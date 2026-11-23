<?php
declare(strict_types=1);

namespace Sigep\Repositories\Store;

use Sigep\Repositories\Contracts\PasswordResetRepositoryInterface;

final class StorePasswordResetRepository extends StoreRepository implements PasswordResetRepositoryInterface
{
    protected string $collection = 'password_resets';
    protected string $prefix = 'T';
    protected int $pad = 6;

    public function findByHash(string $tokenHash): ?array
    {
        return $this->where(fn ($t) => hash_equals($t['tokenHash'], $tokenHash))[0] ?? null;
    }

    public function invalidarDoUsuario(string $usuarioId): void
    {
        foreach ($this->where(fn ($t) => $t['usuarioId'] === $usuarioId && empty($t['usadoEm'])) as $t) {
            $this->update($t['id'], ['usadoEm' => date('c')]);
        }
    }
}
