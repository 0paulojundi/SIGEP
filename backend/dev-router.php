<?php
declare(strict_types=1);

/**
 * Servidor de DESENVOLVIMENTO (front + API na mesma origem, sem CORS e com cookie de sessão funcionando).
 * Rode na raiz do projeto:   php -S localhost:8000 -t . backend/dev-router.php
 *   Front: http://localhost:8000/frontend/sigep.html      API: http://localhost:8000/api/health
 * Em produção (Apache/InfinityFree) este arquivo não é usado: vale o backend/.htaccess.
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Nunca entregar arquivos internos pelo servidor de desenvolvimento.
if (preg_match('#^/(\.env|backend/(storage|src|config|bin|tests|routes|docs)|\.git)#', $path) || (str_ends_with($path, '.php') && !in_array($path, ['/index.php', '/backend/index.php', '/frontend/index.php'], true))) {
    http_response_code(403);
    echo 'Acesso negado.';
    return true;
}
if ($path === '/' || $path === '/index.php') {
    require dirname(__DIR__) . '/index.php';
    return true;
}
if ($path === '/frontend' || $path === '/frontend/') {
    require dirname(__DIR__) . '/frontend/index.php';
    return true;
}
if (str_starts_with($path, '/api/')) {
    require __DIR__ . '/index.php';
    return true;
}
return false; // arquivos estáticos (frontend/sigep.html etc.)
