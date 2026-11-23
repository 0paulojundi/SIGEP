<?php
declare(strict_types=1);

namespace Sigep\Services;

use Sigep\Core\HttpException;
use Sigep\Models\Perfil;
use Sigep\Repositories\Contracts\NotificacaoRepositoryInterface;
use Sigep\Repositories\Contracts\UsuarioRepositoryInterface;

/** RF14 — notificações no sistema (e-mail fica preparado em MailerInterface). */
final class NotificationService
{
    public function __construct(private NotificacaoRepositoryInterface $repo, private UsuarioRepositoryInterface $users)
    {
    }

    /** Cria uma notificação por destinatário (sem repetir, sem avisar quem fez a ação). Retorna quantas criou. */
    public function notificar(array $destinatarios, string $texto, string $cat, ?string $exceto = null, array $ref = []): int
    {
        $n = 0;
        foreach (array_unique(array_filter($destinatarios)) as $id) {
            if ($id === $exceto) {
                continue;
            }
            $u = $this->users->find((string) $id);
            if (!$u || $u['status'] !== 'Ativo' || (($u['prefs']['notifSistema'] ?? true) === false)) {
                continue;
            }
            $this->repo->create([
                'usuarioId' => $u['id'], 'texto' => $texto, 'cat' => $cat, 'lida' => false,
                'data' => date('Y-m-d'), 'criadoEm' => date('c'),
                'refTipo' => $ref['tipo'] ?? null, 'refId' => $ref['id'] ?? null,
            ]);
            $n++;
        }
        return $n;
    }

    public function idsAdministradores(): array
    {
        return array_column($this->users->byPerfil(Perfil::ADMIN), 'id');
    }

    public function listar(array $user, array $q): array
    {
        $rows = $this->repo->doUsuario($user['id']);
        if (!empty($q['cat'])) {
            $rows = array_filter($rows, fn ($n) => $n['cat'] === $q['cat']);
        }
        if (isset($q['lida']) && $q['lida'] !== '') {
            $lida = filter_var($q['lida'], FILTER_VALIDATE_BOOLEAN);
            $rows = array_filter($rows, fn ($n) => $n['lida'] === $lida);
        }
        usort($rows, fn ($a, $b) => strcmp($b['criadoEm'], $a['criadoEm']) ?: strcmp($b['id'], $a['id']));
        $rows = array_values($rows);
        return ['itens' => $rows, 'naoLidas' => count(array_filter($this->repo->doUsuario($user['id']), fn ($n) => !$n['lida']))];
    }

    public function marcar(array $user, string $id, bool $lida): array
    {
        $n = $this->repo->find($id);
        if (!$n || $n['usuarioId'] !== $user['id']) {
            throw HttpException::notFound('Notificação não encontrada.');
        }
        return $this->repo->update($id, ['lida' => $lida]);
    }

    public function marcarTodas(array $user): int
    {
        return $this->repo->marcarTodasComoLidas($user['id']);
    }
}
