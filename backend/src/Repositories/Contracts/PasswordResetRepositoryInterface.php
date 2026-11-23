<?php
declare(strict_types=1);

namespace Sigep\Repositories\Contracts;

interface PasswordResetRepositoryInterface extends RepositoryInterface
{
    public function findByHash(string $tokenHash): ?array;
    public function invalidarDoUsuario(string $usuarioId): void;
}
