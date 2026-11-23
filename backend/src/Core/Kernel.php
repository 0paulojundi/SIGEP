<?php
declare(strict_types=1);

namespace Sigep\Core;

use Sigep\Middleware\AuthMiddleware;
use Sigep\Middleware\CsrfMiddleware;
use Sigep\Middleware\MiddlewareInterface;
use Sigep\Middleware\PermissionMiddleware;
use Sigep\Services\PermissionService;

/** Recebe a requisição, aplica segurança (origem, CORS, cabeçalhos), roda os middlewares e o controller. */
final class Kernel
{
    public function __construct(private Container $container, private Router $router, private string $root)
    {
    }

    public function handle(Request $request): Response
    {
        try {
            $response = $this->dispatch($request);
        } catch (HttpException $e) {
            $response = Response::error($e->status, $e->errorCode, $e->getMessage(), $e->details, $e->headers);
        } catch (\Throwable $e) {
            $this->log($request, $e);
            $debug = Env::bool('APP_DEBUG', false);
            $response = Response::error(500, 'erro_interno', 'Erro interno do servidor.', $debug ? ['erro' => $e->getMessage(), 'em' => basename($e->getFile()) . ':' . $e->getLine()] : []);
        }
        return $this->decorar($request, $response);
    }

    private function dispatch(Request $req): Response
    {
        if ($req->method === 'OPTIONS') {
            return new Response(204);
        }
        $this->verificarOrigem($req);
        [$route, $params] = $this->router->match($req);
        $req->params = $params;

        $core = function (Request $r) use ($route): Response {
            [$class, $method] = $route['handler'];
            return $this->container->get($class)->$method($r);
        };
        $pipeline = $core;
        foreach (array_reverse($route['middleware']) as $spec) {
            $mw = $this->middleware($spec);
            $next = $pipeline;
            $pipeline = fn (Request $r): Response => $mw->handle($r, $next);
        }
        return $pipeline($req);
    }

    private function middleware(string $spec): MiddlewareInterface
    {
        if (str_starts_with($spec, 'perm:')) {
            return new PermissionMiddleware($this->container->get(PermissionService::class), substr($spec, 5));
        }
        return match ($spec) {
            'auth' => $this->container->get(AuthMiddleware::class),
            'csrf' => $this->container->get(CsrfMiddleware::class),
            default => throw new \RuntimeException("Middleware desconhecido: $spec"),
        };
    }

    /** Defesa extra contra CSRF: requisições que alteram dados não podem vir de outra origem. */
    private function verificarOrigem(Request $req): void
    {
        if (in_array($req->method, ['GET', 'HEAD'], true)) {
            return;
        }
        $origin = $req->header('Origin');
        if (!$origin || $this->origemPermitida($origin)) {
            return;
        }
        $p = parse_url($origin);
        $host = ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
        if ($host !== '' && strcasecmp($host, (string) $req->header('Host', '')) === 0) {
            return;
        }
        throw new HttpException(403, 'origem_invalida', 'Origem não permitida.');
    }

    private function origemPermitida(string $origin): bool
    {
        $lista = array_filter(array_map('trim', explode(',', (string) Env::get('CORS_ALLOWED_ORIGINS', ''))));
        return in_array($origin, $lista, true);
    }

    private function decorar(Request $req, Response $res): Response
    {
        $res->headers += [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
        ];
        $origin = $req->header('Origin');
        if ($origin && $this->origemPermitida($origin)) {
            $res->headers += [
                'Access-Control-Allow-Origin' => $origin,
                'Access-Control-Allow-Credentials' => 'true',
                'Access-Control-Allow-Headers' => 'Content-Type, X-CSRF-Token',
                'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
                'Vary' => 'Origin',
            ];
        }
        return $res;
    }

    private function log(Request $req, \Throwable $e): void
    {
        $dir = $this->root . '/storage/logs';
        if (is_dir($dir) || @mkdir($dir, 0700, true)) {
            @file_put_contents($dir . '/app.log', sprintf("[%s] %s %s — %s: %s (%s:%d)\n", date('c'), $req->method, $req->path, get_class($e), $e->getMessage(), basename($e->getFile()), $e->getLine()), FILE_APPEND | LOCK_EX);
        }
    }
}
