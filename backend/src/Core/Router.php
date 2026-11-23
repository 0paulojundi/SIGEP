<?php
declare(strict_types=1);

namespace Sigep\Core;

final class Router
{
    /** @var array<int,array{method:string,pattern:string,regex:string,handler:array,middleware:array,meta:array}> */
    private array $routes = [];

    public function add(string $method, string $pattern, array $handler, array $middleware = [], array $meta = []): void
    {
        $regex = '#^' . preg_replace('#\{([a-zA-Z_]+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = compact('method', 'pattern', 'regex', 'handler', 'middleware', 'meta');
    }

    public function routes(): array
    {
        return $this->routes;
    }

    /** @return array{0:array,1:array} rota + parâmetros */
    public function match(Request $req): array
    {
        $pathMatched = false;
        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $req->path, $m)) {
                $pathMatched = true;
                if ($route['method'] === $req->method) {
                    $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
                    return [$route, array_map('rawurldecode', $params)];
                }
            }
        }
        if ($pathMatched) {
            throw new HttpException(405, 'metodo_nao_permitido', 'Método HTTP não permitido para esta rota.');
        }
        throw HttpException::notFound('Rota não encontrada.');
    }
}
