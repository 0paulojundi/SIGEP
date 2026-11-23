<?php
declare(strict_types=1);

namespace Sigep\Repositories\Contracts;

interface NotificacaoRepositoryInterface extends RepositoryInterface
{
    public function doUsuario(string $usuarioId): array;
    public function marcarTodasComoLidas(string $usuarioId): int;
}
