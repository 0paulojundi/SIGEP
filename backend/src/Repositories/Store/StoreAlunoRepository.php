<?php
declare(strict_types=1);

namespace Sigep\Repositories\Store;

use Sigep\Repositories\Contracts\AlunoRepositoryInterface;

final class StoreAlunoRepository extends StoreRepository implements AlunoRepositoryInterface
{
    protected string $collection = 'alunos';

    public function byResponsavel(string $responsavelId): array
    {
        return $this->where(fn ($a) => in_array($responsavelId, $a['responsaveisIds'] ?? [], true));
    }
}
