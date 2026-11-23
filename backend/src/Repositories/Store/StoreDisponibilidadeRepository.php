<?php
declare(strict_types=1);

namespace Sigep\Repositories\Store;

use Sigep\Repositories\Contracts\DisponibilidadeRepositoryInterface;

final class StoreDisponibilidadeRepository extends StoreRepository implements DisponibilidadeRepositoryInterface
{
    protected string $collection = 'disponibilidades';
    protected string $prefix = 'D';

    public function byOrientador(string $orientadorId): array
    {
        return $this->where(fn ($d) => $d['orientadorId'] === $orientadorId);
    }
}
