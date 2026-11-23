<?php
declare(strict_types=1);

/** Gera docs/API.md a partir da tabela de rotas: php backend/bin/docs.php */
require __DIR__ . '/../bootstrap.php';

use Sigep\Core\Router;

$router = new Router();
(require __DIR__ . '/../routes/api.php')($router);

$md = "# API do SIGEP — rotas\n\n> Gerado automaticamente por `php backend/bin/docs.php` a partir de `backend/routes/api.php`. Não edite à mão.\n\n";
$md .= "## Convenções\n\n";
$md .= "- Base: `/api/...`. Corpo e respostas em JSON (UTF-8).\n";
$md .= "- Sucesso: `{\"ok\": true, \"data\": ..., \"meta\": ...}`. Erro: `{\"ok\": false, \"error\": {\"code\", \"message\", \"details\"}}`.\n";
$md .= "- Autenticação: cookie de sessão `SIGEPSESS` (HttpOnly) criado em `POST /api/auth/login`. O front deve enviar `credentials: 'include'`.\n";
$md .= "- CSRF: toda rota autenticada que altera dados (POST/PUT/PATCH/DELETE) exige o cabeçalho `X-CSRF-Token` com o `csrfToken` devolvido no login ou em `GET /api/auth/me`.\n";
$md .= "- Códigos: 400 requisição inválida · 401 não autenticado · 403 sem permissão/CSRF · 404 não encontrado · 409 conflito (ex.: horário) · 422 dados inválidos · 429 muitas tentativas · 501 não implementado.\n";
$md .= "- Datas `AAAA-MM-DD`; horários `HH:MM`. Status de atendimento: Pendente, Confirmado, Em Andamento, Finalizado, Cancelado, Recusado.\n";
$md .= "- Permissão `pública` = sem login; `qualquer usuário logado` = só login; `a,b` = basta ter uma. O administrador (perfil `instituicao`) tem todas. Matriz completa em `backend/config/permissions.php`.\n\n";
$md .= "## Rotas\n\n| Método | Rota | Função | Dados esperados | Resposta | Permissão |\n|---|---|---|---|---|---|\n";
foreach ($router->routes() as $r) {
    $m = $r['meta'];
    $esc = fn (string $s): string => str_replace('|', '\\|', $s);
    $md .= sprintf("| %s | `%s` | %s | %s | %s | %s |\n", $r['method'], $r['pattern'], $esc($m['resumo']), $esc($m['entrada']), $esc($m['saida']), $esc($m['permissao']));
}
file_put_contents(__DIR__ . '/../docs/API.md', $md);
echo count($router->routes()) . " rotas documentadas em docs/API.md\n";
