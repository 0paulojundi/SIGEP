<?php
declare(strict_types=1);

namespace Sigep\Middleware;

use Sigep\Core\HttpException;
use Sigep\Core\Request;
use Sigep\Core\Response;
use Sigep\Services\PermissionService;

/** Exige a permissão declarada na rota (várias separadas por vírgula = basta uma). */
final class PermissionMiddleware implements MiddlewareInterface
{
    public function __construct(private PermissionService $perms, private string $permissao)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $u = $request->user();
        if (!$u) {
            throw HttpException::unauthorized();
        }
        $this->perms->exigir($u, $this->permissao);
        return $next($request);
    }
}
