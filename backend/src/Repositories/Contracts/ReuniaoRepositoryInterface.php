<?php
declare(strict_types=1);

namespace Sigep\Repositories\Contracts;

interface ReuniaoRepositoryInterface extends RepositoryInterface
{
    /** Reuniões criadas pelo usuário ou em que ele é participante. */
    public function paraUsuario(string $usuarioId): array;
}
