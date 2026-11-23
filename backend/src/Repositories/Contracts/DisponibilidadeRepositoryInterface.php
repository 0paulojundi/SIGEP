<?php
declare(strict_types=1);

namespace Sigep\Repositories\Contracts;

interface DisponibilidadeRepositoryInterface extends RepositoryInterface
{
    public function byOrientador(string $orientadorId): array;
}
