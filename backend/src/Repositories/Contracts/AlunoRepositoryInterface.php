<?php
declare(strict_types=1);

namespace Sigep\Repositories\Contracts;

/** O id do aluno é o RM. */
interface AlunoRepositoryInterface extends RepositoryInterface
{
    public function byResponsavel(string $responsavelId): array;
}
