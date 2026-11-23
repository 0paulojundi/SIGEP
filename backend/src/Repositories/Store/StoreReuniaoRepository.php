<?php
declare(strict_types=1);

namespace Sigep\Repositories\Store;

use Sigep\Repositories\Contracts\ReuniaoRepositoryInterface;

final class StoreReuniaoRepository extends StoreRepository implements ReuniaoRepositoryInterface
{
    protected string $collection = 'reunioes';
    protected string $prefix = 'M';

    public function paraUsuario(string $usuarioId): array
    {
        return $this->where(fn ($r) => $r['criadoPor'] === $usuarioId || in_array($usuarioId, $r['participantes'], true));
    }
}
