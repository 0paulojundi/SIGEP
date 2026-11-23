<?php
declare(strict_types=1);

namespace Sigep\Middleware;

use Sigep\Core\HttpException;
use Sigep\Core\Request;
use Sigep\Core\Response;
use Sigep\Core\SessionInterface;

/** Proteção CSRF: toda operação que altera dados precisa enviar o cabeçalho X-CSRF-Token (entregue no login e em /api/auth/me). */
final class CsrfMiddleware implements MiddlewareInterface
{
    public function __construct(private SessionInterface $session)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        if (!in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            $enviado = (string) $request->header('X-CSRF-Token', '');
            if ($enviado === '' || !hash_equals($this->session->csrfToken(), $enviado)) {
                throw new HttpException(403, 'csrf_invalido', 'Token CSRF ausente ou inválido.');
            }
        }
        return $next($request);
    }
}
