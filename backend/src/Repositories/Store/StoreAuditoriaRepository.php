<?php
declare(strict_types=1);

namespace Sigep\Repositories\Store;

use Sigep\Repositories\Contracts\AuditoriaRepositoryInterface;

final class StoreAuditoriaRepository extends StoreRepository implements AuditoriaRepositoryInterface
{
    protected string $collection = 'auditoria';
    protected string $prefix = 'L';
    protected int $pad = 6;

    public function registrar(array $registro): array { return $this->create($registro); }

    public function buscar(array $f): array
    {
        $rows = $this->where(function ($l) use ($f) {
            $dia = substr($l['quando'], 0, 10);
            return (empty($f['usuarioId']) || $l['usuarioId'] === $f['usuarioId'])
                && (empty($f['cat']) || $l['cat'] === $f['cat'])
                && (empty($f['de']) || $dia >= $f['de'])
                && (empty($f['ate']) || $dia <= $f['ate']);
        });
        usort($rows, fn ($a, $b) => strcmp($b['quando'], $a['quando']) ?: strcmp($b['id'], $a['id']));
        return $rows;
    }
}
