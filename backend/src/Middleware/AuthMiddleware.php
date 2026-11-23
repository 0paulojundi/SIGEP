<?php
declare(strict_types=1);

namespace Sigep\Middleware;

use Sigep\Core\Env;
use Sigep\Core\HttpException;
use Sigep\Core\Request;
use Sigep\Core\Response;
use Sigep\Core\SessionInterface;
use Sigep\Repositories\Contracts\UsuarioRepositoryInterface;

/**
 * Exige usuário logado. A cada requisição recarrega o usuário (perfil/status mudam na hora)
 * e aplica expiração por inatividade e por tempo total de sessão.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private SessionInterface $session, private UsuarioRepositoryInterface $users)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $uid = $this->session->get('uid');
        if (!$uid) {
            throw HttpException::unauthorized();
        }
        $agora = time();
        $ocioso = $agora - (int) $this->session->get('last_seen', 0) > Env::int('SESSION_IDLE_SECONDS', 1800);
        $longa = $agora - (int) $this->session->get('login_at', 0) > Env::int('SESSION_ABSOLUTE_SECONDS', 28800);
        $u = $this->users->find((string) $uid);
        if ($ocioso || $longa || !$u || $u['status'] !== 'Ativo') {
            $this->session->destroy();
            throw HttpException::unauthorized('Sessão expirada. Faça login novamente.');
        }
        $this->session->set('last_seen', $agora);
        $request->attributes['user'] = $u;
        return $next($request);
    }
}
