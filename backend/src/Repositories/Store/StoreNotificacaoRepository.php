<?php
declare(strict_types=1);

namespace Sigep\Repositories\Store;

use Sigep\Repositories\Contracts\NotificacaoRepositoryInterface;

final class StoreNotificacaoRepository extends StoreRepository implements NotificacaoRepositoryInterface
{
    protected string $collection = 'notificacoes';
    protected string $prefix = 'N';
    protected int $pad = 6;

    public function doUsuario(string $usuarioId): array { return $this->where(fn ($n) => $n['usuarioId'] === $usuarioId); }

    public function marcarTodasComoLidas(string $usuarioId): int
    {
        $n = 0;
        foreach ($this->doUsuario($usuarioId) as $row) {
            if (!$row['lida']) {
                $this->update($row['id'], ['lida' => true]);
                $n++;
            }
        }
        return $n;
    }
}
