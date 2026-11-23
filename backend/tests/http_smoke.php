<?php
declare(strict_types=1);

/**
 * Teste PELA REDE, em PHP puro (funciona no Windows, sem bash/curl/python):
 *   php backend/tests/http_smoke.php            (porta 8765; ou:  php backend/tests/http_smoke.php 9000)
 *
 * Sobe o servidor do SIGEP sozinho, entra como administrador, orientador e responsável de verdade (cookie + CSRF)
 * e percorre um fluxo completo: solicitar → aprovar → conflito → agendar direto → atendimento → histórico → auditoria → CSV.
 * ATENÇÃO: recria os dados de demonstração (apaga backend/storage/data/*.json) antes e depois.
 */
$root = dirname(__DIR__, 2);
$port = (int) ($argv[1] ?? 8765);
$base = "http://127.0.0.1:$port";
$criouEnv = false;

if (!is_file("$root/.env")) {
    copy("$root/.env.example", "$root/.env");
    $criouEnv = true;
}
$senha = '';
foreach (file("$root/.env", FILE_IGNORE_NEW_LINES) as $l) {
    if (str_starts_with(trim($l), 'DEMO_PASSWORD=')) {
        $senha = trim(preg_replace('/\s+#.*$/', '', substr(trim($l), 14)));
    }
}
$limpar = function () use ($root): void {
    foreach (glob("$root/backend/storage/data/*.json") ?: [] as $f) { @unlink($f); }
    foreach (glob("$root/backend/storage/sessions/sess_*") ?: [] as $f) { @unlink($f); }
};
$limpar();

$nulo = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root, "$root/backend/dev-router.php"], [1 => ['file', $nulo, 'w'], 2 => ['file', $nulo, 'w']], $pipes);
$encerrar = function () use (&$proc, $limpar, $criouEnv, $root): void {
    if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
    $limpar();
    if ($criouEnv) { @unlink("$root/.env"); }
};
register_shutdown_function($encerrar);

/** Cliente HTTP mínimo com "cookie jar" e token CSRF — cada instância é um navegador. */
final class Http
{
    public array $cookies = [];
    public ?string $csrf = null;
    public string $ultimoSetCookie = '';

    public function __construct(private string $base) {}

    /** @return array{status:int,headers:array,body:string,json:?array} */
    public function req(string $metodo, string $caminho, ?array $corpo = null, array $extra = [], bool $semCsrf = false): array
    {
        $h = ['Accept: application/json'];
        if ($this->cookies) {
            $h[] = 'Cookie: ' . implode('; ', array_map(fn ($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies));
        }
        if ($this->csrf && !$semCsrf) { $h[] = 'X-CSRF-Token: ' . $this->csrf; }
        if ($corpo !== null) { $h[] = 'Content-Type: application/json'; }
        foreach ($extra as $k => $v) { $h[] = "$k: $v"; }
        $ctx = stream_context_create(['http' => ['method' => $metodo, 'header' => implode("\r\n", $h), 'content' => $corpo !== null ? json_encode($corpo) : '', 'ignore_errors' => true, 'timeout' => 15]]);
        $raw = @file_get_contents($this->base . $caminho, false, $ctx);
        $linhas = $http_response_header ?? [];
        $status = isset($linhas[0]) && preg_match('#HTTP/\S+\s+(\d{3})#', $linhas[0], $m) ? (int) $m[1] : 0;
        $headers = [];
        foreach (array_slice($linhas, 1) as $linha) {
            if (!str_contains($linha, ':')) { continue; }
            [$k, $v] = explode(':', $linha, 2);
            $headers[strtolower(trim($k))] = trim($v);
            if (strtolower(trim($k)) === 'set-cookie') {
                $this->ultimoSetCookie = trim($v);
                [$nv] = explode(';', trim($v), 2);
                [$nome, $valor] = array_pad(explode('=', $nv, 2), 2, '');
                if ($valor === 'deleted' || $valor === '') { unset($this->cookies[$nome]); } else { $this->cookies[$nome] = $valor; }
            }
        }
        $json = json_decode((string) $raw, true);
        return ['status' => $status, 'headers' => $headers, 'body' => (string) $raw, 'json' => is_array($json) ? $json : null];
    }

    public function login(string $email, string $senha): array
    {
        $r = $this->req('POST', '/api/auth/login', ['email' => $email, 'senha' => $senha], [], true);
        $this->csrf = $r['status'] === 200 ? ($r['json']['data']['csrfToken'] ?? null) : null;
        return $r;
    }
}

$total = 0;
$falhas = [];
function ok(string $nome, bool $cond, string $detalhe = ''): void
{
    global $total, $falhas;
    $total++;
    if ($cond) { echo "  ✓ $nome\n"; return; }
    $falhas[] = $nome;
    echo "  ✗ $nome" . ($detalhe !== '' ? "   [$detalhe]" : '') . "\n";
}
function secao(string $t): void { echo "\n== $t ==\n"; }
function dado(array $r): mixed { return $r['json']['data'] ?? null; }
function resumo(array $r): string { return $r['status'] . ' ' . substr($r['body'], 0, 140); }
function proxDia(int $dow): string
{
    for ($i = 2; $i < 10; $i++) { if ((int) date('w', strtotime("+$i days")) === $dow) { return date('Y-m-d', strtotime("+$i days")); } }
    return date('Y-m-d', strtotime('+3 days'));
}

// ---- espera o servidor subir ----
$no_ar = false;
for ($i = 0; $i < 40 && !$no_ar; $i++) {
    $no_ar = @file_get_contents("$base/api/health") !== false;
    if (!$no_ar) { usleep(250000); }
}
if (!$no_ar) {
    echo "Não consegui subir o servidor na porta $port (talvez esteja ocupada). Tente: php backend/tests/http_smoke.php 9000\n";
    exit(2);
}

$anon = new Http($base); $adm = new Http($base); $o1 = new Http($base); $o2 = new Http($base); $r1 = new Http($base); $r2 = new Http($base); $r8 = new Http($base);

secao('Servidor, arquivos estáticos e bloqueios');
$r = $anon->req('GET', '/api/health');
ok('GET /api/health → 200 e {"ok":true}', $r['status'] === 200 && ($r['json']['ok'] ?? false) === true, resumo($r));
ok('cabeçalhos de segurança (nosniff, no-store)', ($r['headers']['x-content-type-options'] ?? '') === 'nosniff' && ($r['headers']['cache-control'] ?? '') === 'no-store');
ok('front servido em /frontend/sigep.html → 200', $anon->req('GET', '/frontend/sigep.html')['status'] === 200);
foreach (['/.env', '/backend/storage/data/usuarios.json', '/backend/src/App.php', '/backend/config/app.php'] as $p) {
    ok("arquivo interno bloqueado: $p → 403", $anon->req('GET', $p)['status'] === 403);
}
ok('rota inexistente → 404 em JSON', ($x = $anon->req('GET', '/api/nada')) && $x['status'] === 404 && ($x['json']['error']['code'] ?? '') === 'nao_encontrado');
$ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => '{quebrado', 'ignore_errors' => true]]);
@file_get_contents("$base/api/auth/login", false, $ctx);
ok('JSON malformado → 400', preg_match('#\s400\s#', $http_response_header[0] ?? '') === 1);

secao('Login com cookie de sessão e CSRF');
ok('rota protegida sem login → 401', $anon->req('GET', '/api/auth/me')['status'] === 401);
ok('senha errada → 401', $anon->login('instituicao@sigep.com', 'errada12345')['status'] === 401);
$r = $adm->login('instituicao@sigep.com', $senha);
ok('administrador entra → 200 com csrfToken', $r['status'] === 200 && strlen((string) $adm->csrf) === 64, resumo($r));
ok('cookie de sessão é HttpOnly e SameSite=Lax', stripos($adm->ultimoSetCookie, 'httponly') !== false && stripos($adm->ultimoSetCookie, 'samesite=lax') !== false, $adm->ultimoSetCookie);
ok('resposta de login não traz hash de senha', !str_contains($r['body'], 'senhaHash'));
ok('orientador, responsáveis entram', $o1->login('orientador@sigep.com', $senha)['status'] === 200 && $o2->login('orientador2@sigep.com', $senha)['status'] === 200
    && $r1->login('responsavel@sigep.com', $senha)['status'] === 200 && $r2->login('responsavel2@sigep.com', $senha)['status'] === 200 && $r8->login('responsavel8@sigep.com', $senha)['status'] === 200);
ok('GET /api/auth/me com cookie → 200', $o1->req('GET', '/api/auth/me')['status'] === 200);
ok('POST sem X-CSRF-Token → 403', $o1->req('POST', '/api/notificacoes/marcar-todas', [], [], true)['status'] === 403);
ok('POST com X-CSRF-Token → 200', $o1->req('POST', '/api/notificacoes/marcar-todas', [])['status'] === 200);
ok('POST vindo de outra origem → 403', $o1->req('POST', '/api/notificacoes/marcar-todas', [], ['Origin' => 'http://site-malicioso.example'])['status'] === 403);

secao('Permissões pela rede');
ok('orientador não acessa /api/usuarios nem /api/auditoria (403)', $o1->req('GET', '/api/usuarios')['status'] === 403 && $o1->req('GET', '/api/auditoria')['status'] === 403);
ok('responsável não cria agendamento direto nem vê relatórios (403)', $r1->req('POST', '/api/agendamentos', ['alunoRm' => '20250008'])['status'] === 403 && $r1->req('GET', '/api/relatorios/resumo')['status'] === 403);
ok('responsável vê só os próprios alunos (3)', count(dado($r1->req('GET', '/api/alunos')) ?? []) === 3);
ok('administrador lista os 15 usuários e a matriz de permissões', count(dado($adm->req('GET', '/api/usuarios')) ?? []) === 15 && isset(dado($adm->req('GET', '/api/permissoes'))['orientador']));

secao('Fluxo: solicitar → aprovar → conflito');
$segunda = proxDia(1);
$h = $r1->req('GET', "/api/orientadores/O001/horarios?data=$segunda");
ok('responsável consulta horários livres do orientador (segunda: 08:00–11:30)', $h['status'] === 200 && count(dado($h)['horarios']) === 8, resumo($h));
$sol = ['alunoRm' => '20250008', 'orientadorId' => 'O001', 'data' => $segunda, 'horario' => '09:00', 'motivo' => 'Orientação de TCC', 'descricao' => 'Preciso de ajuda com o TCC.'];
$r = $r1->req('POST', '/api/solicitacoes', $sol);
ok('responsável solicita → 201 Pendente', $r['status'] === 201 && dado($r)['status'] === 'Pendente', resumo($r));
$s1 = dado($r)['id'] ?? '';
ok('horário fora da disponibilidade → 422', $r1->req('POST', '/api/solicitacoes', ['alunoRm' => '20250016', 'horario' => '14:00'] + $sol)['status'] === 422);
ok('aluno de outro responsável → 403', $r1->req('POST', '/api/solicitacoes', ['alunoRm' => '20250001'] + $sol)['status'] === 403);
$pend = dado($o1->req('GET', '/api/solicitacoes')) ?? [];
ok('orientador vê a solicitação pendente (com nome do aluno e do responsável)', ($x = current(array_filter($pend, fn ($a) => $a['id'] === $s1))) && $x['alunoNome'] === 'Isabela Ramos Teixeira' && $x['responsavelNome'] === 'Marcos Souza');
// 2ª solicitação no MESMO horário, de outro responsável: as duas podem ficar Pendentes; só uma poderá ser aprovada
$s2 = dado($r2->req('POST', '/api/solicitacoes', ['alunoRm' => '20250001', 'orientadorId' => 'O001', 'data' => $segunda, 'horario' => '09:00', 'motivo' => 'Orientação vocacional']))['id'] ?? '';
ok('outra solicitação no mesmo horário (outro aluno) é aceita como Pendente', $s2 !== '');
ok('outro orientador não aprova a solicitação alheia (403 ou 404, conforme ORIENTADOR_VE_TODOS)', in_array($o2->req('POST', "/api/solicitacoes/$s1/aprovar", [])['status'], [403, 404], true));
$r = $o1->req('POST', "/api/solicitacoes/$s1/aprovar", []);
ok('orientador aprova → Confirmado', $r['status'] === 200 && dado($r)['status'] === 'Confirmado', resumo($r));
$r = $o1->req('POST', "/api/solicitacoes/$s2/aprovar", []);
ok('2ª solicitação no mesmo horário: aprovar → 409 (conflito)', $r['status'] === 409, resumo($r));
ok('recusar com motivo → Recusado', ($x = $o1->req('POST', "/api/solicitacoes/$s2/recusar", ['motivoRecusa' => 'Horário ocupado'])) && (dado($x)['status'] ?? '') === 'Recusado', resumo($x));
ok('responsável foi notificado da aprovação', (bool) array_filter(dado($r1->req('GET', '/api/notificacoes')) ?? [], fn ($n) => str_contains($n['texto'], 'foi aprovada')));
ok('responsável da recusada foi notificado', (bool) array_filter(dado($r2->req('GET', '/api/notificacoes')) ?? [], fn ($n) => str_contains($n['texto'], 'foi recusada')));

secao('Agendamento direto (sem solicitação)');
$terca = proxDia(2);
$dir = ['alunoRm' => '20250003', 'data' => $terca, 'horario' => '13:30', 'motivo' => 'Acompanhamento pedagógico'];
$r = $o1->req('POST', '/api/agendamentos', $dir);
ok('orientador agenda direto → 201 Confirmado (origem direta)', $r['status'] === 201 && dado($r)['status'] === 'Confirmado' && dado($r)['origem'] === 'direta', resumo($r));
ok('mesmo horário para outro aluno → 409', $o1->req('POST', '/api/agendamentos', ['alunoRm' => '20250005'] + $dir)['status'] === 409);
ok('data no passado → 422', $o1->req('POST', '/api/agendamentos', ['alunoRm' => '20250005', 'data' => date('Y-m-d', strtotime('-2 days'))] + $dir)['status'] === 422);

secao('Atendimento: iniciar → registrar → finalizar → histórico');
ok('responsável não inicia atendimento (403)', $r8->req('POST', '/api/atendimentos/A0007/iniciar', [])['status'] === 403);
ok('iniciar atendimento de data futura → 422', $adm->req('POST', '/api/atendimentos/A0008/iniciar', [])['status'] === 422);
$r = $o2->req('POST', '/api/atendimentos/A0007/iniciar', []);
ok('orientador inicia → Em Andamento com hora inicial real', $r['status'] === 200 && dado($r)['status'] === 'Em Andamento' && !empty(dado($r)['horaInicialReal']), resumo($r));
$r = $o2->req('POST', '/api/atendimentos/A0007/finalizar', ['observacoes' => 'só isso']);
ok('finalizar sem descrição e resultado → 422', $r['status'] === 422 && isset($r['json']['error']['details']['descricao']), resumo($r));
$r = $o2->req('POST', '/api/atendimentos/A0007/finalizar', ['descricao' => 'Conversa sobre frequência.', 'observacoes' => 'Nota interna.', 'resultado' => 'Plano combinado.']);
ok('finalizar com descrição e resultado → Finalizado', $r['status'] === 200 && dado($r)['status'] === 'Finalizado', resumo($r));
$x = dado($r8->req('GET', '/api/atendimentos/A0007')) ?? [];
ok('responsável NÃO vê o registro nem as observações (o orientador não liberou)', !isset($x['observacoes']) && !isset($x['descricao']) && !isset($x['resultado']));
ok('orientador libera o registro → responsável vê descrição e resultado, nunca as observações', $o2->req('PATCH', '/api/atendimentos/A0007/visibilidade', ['registroVisivel' => true])['status'] === 200
    && ($y = dado($r8->req('GET', '/api/atendimentos/A0007'))) && ($y['resultado'] ?? '') === 'Plano combinado.' && !isset($y['observacoes']));
ok('histórico do aluno traz o atendimento finalizado', ($hh = dado($adm->req('GET', '/api/alunos/20250007/historico'))) && $hh['total'] >= 1);
ok('responsável de outro aluno não vê o histórico (404)', $r1->req('GET', '/api/alunos/20250007/historico')['status'] === 404);

secao('Administrador: usuários, auditoria e relatórios');
$r = $adm->req('POST', '/api/usuarios', ['perfil' => 'orientador', 'nome' => 'Prof. Novo Teste', 'email' => 'novo@sigep.com', 'cpf' => '529.982.247-25', 'telefone' => '11988887777']);
ok('cadastra orientador (sem senha → e-mail de definição) → 201', $r['status'] === 201, resumo($r));
ok('e-mail duplicado → 422', $adm->req('POST', '/api/usuarios', ['perfil' => 'orientador', 'nome' => 'Outro Nome', 'email' => 'novo@sigep.com', 'cpf' => '111.444.777-35'])['status'] === 422);
ok('CPF inválido → 422', $adm->req('POST', '/api/usuarios', ['perfil' => 'orientador', 'nome' => 'Outro Nome', 'email' => 'outro@sigep.com', 'cpf' => '111.111.111-11'])['status'] === 422);
$audit = dado($adm->req('GET', '/api/auditoria?porPagina=200')) ?? [];
$acoes = implode("\n", array_column($audit, 'acao'));
foreach (['Aprovou a solicitação', 'Recusou a solicitação', 'Agendou atendimento direto', 'Finalizou o atendimento', 'Cadastrou orientador', 'Login realizado'] as $t) {
    ok("auditoria registrou: $t", str_contains($acoes, $t));
}
ok('auditoria diz quem fez (Prof. Marcelo Antunes aprovou)', (bool) array_filter($audit, fn ($l) => $l['usuario'] === 'Prof. Marcelo Antunes' && str_contains($l['acao'], 'Aprovou')));
ok('auditoria não guarda conteúdo pedagógico', !str_contains(json_encode($audit), 'Nota interna') && !str_contains(json_encode($audit), 'Plano combinado'));
ok('filtro de auditoria por categoria', ($f = dado($adm->req('GET', '/api/auditoria?cat=' . rawurlencode('Solicitações')))) && count($f) > 0 && !array_filter($f, fn ($l) => $l['cat'] !== 'Solicitações'));
$res = dado($adm->req('GET', '/api/relatorios/resumo')) ?? [];
ok('resumo de relatórios (total, status, meses, cursos)', ($res['total'] ?? 0) > 10 && isset($res['porStatus']['Finalizado']) && count($res['porMes'] ?? []) > 0);
$csv = $adm->req('GET', '/api/relatorios/atendimentos/exportar?formato=csv');
ok('exportação CSV (text/csv, cabeçalho em português)', str_starts_with($csv['headers']['content-type'] ?? '', 'text/csv') && str_contains($csv['body'], 'ID;Data;Horário;Aluno'));
ok('exportação PDF → 501 (ainda não implementada)', $adm->req('GET', '/api/relatorios/atendimentos/exportar?formato=pdf')['status'] === 501);

secao('Encerrar sessão');
ok('logout → 200 e depois /api/auth/me → 401', $o1->req('POST', '/api/auth/logout', [])['status'] === 200 && $o1->req('GET', '/api/auth/me')['status'] === 401);

echo "\n──────────────────────────────────────────\n";
echo 'Resultado: ' . ($total - count($falhas)) . "/$total verificações passaram.\n";
if ($falhas) {
    echo "FALHAS:\n - " . implode("\n - ", $falhas) . "\n";
    exit(1);
}
echo "Tudo certo.\n";
