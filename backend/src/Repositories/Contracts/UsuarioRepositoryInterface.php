<?php
declare(strict_types=1);

namespace Sigep\Repositories\Contracts;

interface UsuarioRepositoryInterface extends RepositoryInterface
{
    public function findByEmail(string $email): ?array;
    public function findByCpf(string $cpfSomenteDigitos): ?array;
    /** @return array<int,array> */
    public function byPerfil(string $perfil): array;
}
