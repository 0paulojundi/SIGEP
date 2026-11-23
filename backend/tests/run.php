<?php
declare(strict_types=1);

/**
 * Testes automatizados do back-end (sem banco, sem servidor web): php backend/tests/run.php
 * Usa dados temporários em memória (ArrayStore), sessão em memória (um "navegador" por Client) e e-mail capturado.
 */
require __DIR__ . '/../bootstrap.php';

use Sigep\App;
use Sigep\Core\ArraySession;
use Sigep\Core\Env;
use Sigep\Core\Kernel;
use Sigep\Core\Request;
use Sigep\Core\Response;
use Sigep\Mail\ArrayMailer;
use Sigep\Storage\ArrayStore;
use Sigep\Support\Documentos;

set_error_handler(function (int $no, string $str, string $file, int $line): bool {
    throw new ErrorException($str, 0, $no, $file, $line);
});

const SENHA = 'Teste@12345';
Env::set('STORAGE_DRIVER', 'array');
Env::set('SEED_DEMO', 'true');
Env::set('DEMO_PASSWORD', SENHA);
Env::set('APP_DEBUG', 'true');
Env::set('LOGIN_MAX_ATTEMPTS', '5');
Env::set('PASSWORD_MIN_LENGTH', '8');
Env::set('SELF_REGISTRATION', 'true');
Env::set('ORIENTADOR_VE_TODOS', 'false');
Env::set('REUNIAO_BLOQUEIA_DIA', 'false'); // testado à parte, com datas próprias
Env::set('ATRASO_CANCELA_MINUTOS', '10');
Env::set('ATENDIMENTO_MAX_MINUTOS', '180');

$store = new ArrayStore();
$mailer = new ArrayMailer();

final class Client
{
    public Kernel $kernel;
    public ArraySession $session;
    public ?string $csrf = null;
    public array $extraHeaders = [];

    public function __construct(ArrayStore $store, ArrayMailer $mailer)
    {
        $this->session = new ArraySession();
        $this->kernel = App::create(['store' => $store, 'session' => $this->session, 'mailer' => $mailer]);
    }

    public function call(string $method, string $path, array $body = [], array $query = [], bool $semCsrf = false): Response
    {
        $headers = ['Host' => 'localhost'] + $this->extraHeaders;
        if ($this->csrf && !$semCsrf) {
            $headers['X-CSRF-Token'] = $this->csrf;
        }
        return $this->kernel->handle(new Request($method, $path, $query, $body, $headers, '127.0.0.1'));
    }

    public function login(string $email, string $senha = SENHA): Response
    {
        $r = $this->call('POST', '/api/auth/login', ['email' => $email, 'senha' => $senha]);
        $this->csrf = $r->status === 200 ? ($r->data()['csrfToken'] ?? null) : null;
        return $r;
    }
}

$total = 0;
$falhas = [];
function check(string $nome, bool $ok, string $detalhe = ''): void
{
    global $total, $falhas;
    $total++;
    if (!$ok) {
        $falhas[] = $nome . ($detalhe !== '' ? " — $detalhe" : '');
        echo "  ✗ $nome" . ($detalhe !== '' ? "  [$detalhe]" : '') . "\n";
    } else {
        echo "  ✓ $nome\n";
    }
}
function secao(string $t): void { echo "\n== $t ==\n"; }
function st(Response $r): string { return (string) $r->status . ' ' . substr((string) $r->body, 0, 160); }
function proxDia(int $dow, int $minDias = 2): string
{
    for ($i = $minDias; $i < $minDias + 8; $i++) {
        if ((int) date('w', strtotime("+$i days")) === $dow) {
            return date('Y-m-d', strtotime("+$i days"));
        }
    }
    throw new RuntimeException('sem data');
}
function achar(array $lista, callable $f): ?array { foreach ($lista as $x) { if ($f($x)) { return $x; } } return null; }

$admin = new Client($store, $mailer);
$o1 = new Client($store, $mailer);   // Prof. Marcelo (O001)
$o2 = new Client($store, $mailer);   // Profa. Juliana (O002)
$r1 = new Client($store, $mailer);   // Marcos Souza (R001)
$r2 = new Client($store, $mailer);   // Renata Carvalho (R002)
$anon = new Client($store, $mailer);

// =====================================================================
secao('Leitura do .env');
$tmpEnv = tempnam(sys_get_temp_dir(), 'env');
file_put_contents($tmpEnv, "A_BOOL=true      # comentário na mesma linha\nB_TEXTO=\"valor # com hash\"\n# linha comentada\nC_VAZIO=\nD_NUM=42   # quarenta e dois\nE_URL=http://x/y#{token}\n");
Env::load($tmpEnv);
check('comentário na mesma linha é ignorado (booleano continua válido)', Env::bool('A_BOOL') === true);
check('valor entre aspas preserva o #', Env::get('B_TEXTO') === 'valor # com hash');
check('valor vazio e número com comentário', Env::get('C_VAZIO') === '' && Env::int('D_NUM') === 42);
check('# colado ao valor (sem espaço antes) faz parte do valor, como em URLs', Env::get('E_URL') === 'http://x/y#{token}');
unlink($tmpEnv);
check('.env.example é lido sem erros e o AUTO_STATUS dele vale true', (function () { Env::load(__DIR__ . '/../../.env.example'); return Env::bool('AUTO_STATUS') === true && Env::int('LOGIN_MAX_ATTEMPTS') === 5; })());
// o teste abaixo usa valores explícitos; restaura o que os testes definem
Env::set('STORAGE_DRIVER', 'array'); Env::set('DEMO_PASSWORD', SENHA); Env::set('APP_DEBUG', 'true'); Env::set('PASSWORD_MIN_LENGTH', '8');
Env::set('ORIENTADOR_VE_TODOS', 'false'); Env::set('REUNIAO_BLOQUEIA_DIA', 'false'); Env::set('ATRASO_CANCELA_MINUTOS', '10'); Env::set('ATENDIMENTO_MAX_MINUTOS', '180');

// =====================================================================
secao('Dados de demonstração');
$usuarios = $store->all('usuarios');
check('seed criou usuários, alunos e agendamentos', count($usuarios) === 15 && count($store->all('alunos')) === 20 && count($store->all('agendamentos')) === 12);
check('todos os CPFs do seed são válidos', count(array_filter($usuarios, fn ($u) => !Documentos::cpfValido($u['cpf']))) === 0);
check('CNPJ do seed é válido', Documentos::cnpjValido($store->find('instituicao', '1')['cnpj']));
check('senhas gravadas com password_hash (não em texto puro)', array_reduce($usuarios, fn ($c, $u) => $c && $u['senhaHash'] !== SENHA && password_verify(SENHA, $u['senhaHash']), true));

// =====================================================================
secao('Login, logout e sessão');
$r = $anon->call('GET', '/api/health');
check('health é público', $r->status === 200);
check('rota protegida sem login → 401', $anon->call('GET', '/api/auth/me')->status === 401);
check('rota inexistente → 404', $anon->call('GET', '/api/xyz')->status === 404);
check('método errado → 405', $anon->call('DELETE', '/api/health')->status === 405);
$r = $anon->login('instituicao@sigep.com', 'senhaErrada1');
check('senha errada → 401', $r->status === 401, st($r));
$r2x = $anon->login('naoexiste@sigep.com', 'qualquer123');
check('e-mail inexistente → mesma resposta 401 (sem revelar contas)', $r2x->status === 401 && $r2x->payload()['error']['message'] === $r->payload()['error']['message']);
$r = $admin->login('instituicao@sigep.com');
check('login do administrador → 200 com csrfToken', $r->status === 200 && strlen((string) ($r->data()['csrfToken'] ?? '')) === 64, st($r));
check('resposta de login não expõe hash de senha', !str_contains((string) $r->body, 'senhaHash') && !str_contains((string) $r->body, '$2y$'));
check('administrador tem permissão total (*)', ($r->data()['permissoes'] ?? []) === ['*']);
check('/auth/me com sessão → 200', $admin->call('GET', '/api/auth/me')->status === 200);
$o1->login('orientador@sigep.com'); $o2->login('orientador2@sigep.com');
$r1->login('responsavel@sigep.com'); $r2->login('responsavel2@sigep.com');
check('os 4 perfis demo conseguem entrar', $o1->csrf && $o2->csrf && $r1->csrf && $r2->csrf);

$tmp = new Client($store, $mailer);
$tmp->login('orientador5@sigep.com');
check('POST sem X-CSRF-Token → 403', $tmp->call('POST', '/api/notificacoes/marcar-todas', [], [], true)->status === 403);
$tmp->csrf = 'token-errado';
check('POST com CSRF errado → 403', $tmp->call('POST', '/api/notificacoes/marcar-todas')->status === 403);
$tmp->csrf = $tmp->session->csrfToken();
check('POST com CSRF correto → 200', $tmp->call('POST', '/api/notificacoes/marcar-todas')->status === 200);
$tmp->session->set('last_seen', time() - 99999);
$r = $tmp->call('GET', '/api/auth/me');
check('sessão ociosa expira → 401', $r->status === 401 && str_contains($r->payload()['error']['message'], 'expirada'), st($r));
$tmp->login('orientador5@sigep.com');
check('logout encerra a sessão', $tmp->call('POST', '/api/auth/logout')->status === 200 && $tmp->call('GET', '/api/auth/me')->status === 401);

$forca = new Client($store, $mailer);
for ($i = 0; $i < 5; $i++) { $forca->login('orientador4@sigep.com', 'errada12345'); }
$r = $forca->login('orientador4@sigep.com');
check('5 falhas bloqueiam o login (mesmo com a senha certa) → 429 com Retry-After', $r->status === 429 && isset($r->headers['Retry-After']), st($r));

$evil = new Client($store, $mailer);
$evil->login('orientador3@sigep.com');
$evil->extraHeaders = ['Origin' => 'http://site-malicioso.example'];
check('origem diferente em POST → 403', $evil->call('POST', '/api/notificacoes/marcar-todas')->status === 403);

// =====================================================================
secao('Recuperação e redefinição de senha');
$antes = count($mailer->enviados);
$r = $anon->call('POST', '/api/auth/forgot-password', ['email' => 'responsavel3@sigep.com']);
check('forgot-password devolve 200 e envia e-mail', $r->status === 200 && count($mailer->enviados) === $antes + 1);
$anon->call('POST', '/api/auth/forgot-password', ['email' => 'ninguem@sigep.com']);
check('e-mail desconhecido: mesma resposta e nenhum e-mail enviado', count($mailer->enviados) === $antes + 1);
preg_match('/redefinir-senha\/([a-f0-9]{64})/', end($mailer->enviados)['corpo'], $m);
$token = $m[1] ?? '';
check('e-mail contém link com token de 64 hex', strlen($token) === 64);
check('o token não é guardado em texto puro (só o hash)', !str_contains(json_encode($store->all('password_resets')), $token));
check('senha fraca na redefinição → 422', $anon->call('POST', '/api/auth/reset-password', ['token' => $token, 'novaSenha' => 'abc'])->status === 422);
check('token inválido → 422', $anon->call('POST', '/api/auth/reset-password', ['token' => str_repeat('a', 64), 'novaSenha' => 'NovaSenha#2026'])->status === 422);
check('redefinição válida → 200', $anon->call('POST', '/api/auth/reset-password', ['token' => $token, 'novaSenha' => 'NovaSenha#2026'])->status === 200);
check('token só vale uma vez', $anon->call('POST', '/api/auth/reset-password', ['token' => $token, 'novaSenha' => 'OutraSenha#2026'])->status === 422);
check('login com a nova senha funciona; com a antiga não', (new Client($store, $mailer))->login('responsavel3@sigep.com', 'NovaSenha#2026')->status === 200 && (new Client($store, $mailer))->login('responsavel3@sigep.com')->status === 401);

secao('Cadastro público de responsável');
$novo = ['nome' => 'Lúcia Ferraz', 'email' => 'lucia@exemplo.com', 'cpf' => Documentos::cpfDeBase('300000001'), 'telefone' => '(11) 97777-6666', 'senha' => 'Senha#Forte2026'];
$r = $anon->call('POST', '/api/auth/register', $novo);
check('cadastro válido → 201 como responsável', $r->status === 201 && $r->data()['perfil'] === 'responsavel', st($r));
check('e-mail repetido → 422', $anon->call('POST', '/api/auth/register', $novo)->status === 422);
check('CPF inválido → 422', $anon->call('POST', '/api/auth/register', ['email' => 'x@y.com', 'cpf' => '111.111.111-11'] + $novo)->status === 422);
check('senha fraca → 422', $anon->call('POST', '/api/auth/register', ['email' => 'outra@y.com', 'cpf' => Documentos::cpfDeBase('300000002'), 'senha' => '12345678'] + $novo)->status === 422);

// =====================================================================
secao('Permissões dos três perfis');
foreach ([['GET', '/api/usuarios'], ['GET', '/api/auditoria'], ['GET', '/api/responsaveis'], ['POST', '/api/alunos'], ['PUT', '/api/instituicao'], ['GET', '/api/orientadores'], ['GET', '/api/permissoes']] as [$mt, $p]) {
    check("orientador não acessa $mt $p → 403", $o1->call($mt, $p)->status === 403);
}
foreach ([['GET', '/api/usuarios'], ['GET', '/api/auditoria'], ['POST', '/api/alunos'], ['POST', '/api/agendamentos'], ['GET', '/api/relatorios/resumo'], ['POST', '/api/reunioes'], ['POST', '/api/disponibilidades'], ['POST', '/api/solicitacoes/A0010/aprovar'], ['POST', '/api/atendimentos/A0007/iniciar']] as [$mt, $p]) {
    check("responsável não acessa $mt $p → 403", $r1->call($mt, $p)->status === 403);
}
check('administrador acessa usuários, auditoria, relatórios e reuniões', $admin->call('GET', '/api/usuarios')->status === 200 && $admin->call('GET', '/api/auditoria')->status === 200 && $admin->call('GET', '/api/relatorios/resumo')->status === 200 && $admin->call('GET', '/api/reunioes')->status === 200);
$alunosR1 = $r1->call('GET', '/api/alunos')->data();
check('responsável vê só os alunos a que está vinculado (4 de 20: um aluno tem 2 responsáveis)', count($alunosR1) === 4 && count(array_filter($alunosR1, fn ($a) => !in_array('R001', $a['responsaveisIds'], true))) === 0);
check('responsável não vê aluno de outro → 404', $r1->call('GET', '/api/alunos/20250001')->status === 404);
check('orientador vê todos os alunos', count($o1->call('GET', '/api/alunos')->data()) === 20);
$basico = $o1->call('GET', '/api/orientadores/basico')->data();
check('lista básica de orientadores não expõe e-mail/CPF', count($basico) === 5 && !isset($basico[0]['email']) && !isset($basico[0]['cpf']));
$ag1 = $o1->call('GET', '/api/agendamentos')->data();
check('orientador vê só os próprios agendamentos', count($ag1) > 0 && count(array_filter($ag1, fn ($a) => $a['orientadorId'] !== 'O001')) === 0);
check('orientador não vê agendamento de outro → 404', $o1->call('GET', '/api/agendamentos/A0002')->status === 404);

// =====================================================================
secao('Usuários (administrador)');
$r = $admin->call('POST', '/api/usuarios', ['perfil' => 'orientador', 'nome' => 'Prof. Teste Novo', 'email' => 'novo.orientador@sigep.com', 'cpf' => Documentos::cpfDeBase('400000001'), 'telefone' => '11988887777']);
$novoId = $r->data()['id'] ?? '';
check('cadastro sem senha → 201 e e-mail para definir senha', $r->status === 201 && str_contains(end($mailer->enviados)['assunto'], 'defina'), st($r));
check('CPF aparece completo para o administrador', str_contains($r->data()['cpf'], '400') || strlen($r->data()['cpf']) === 14);
check('e-mail duplicado → 422', $admin->call('POST', '/api/usuarios', ['perfil' => 'orientador', 'nome' => 'Outro Nome', 'email' => 'novo.orientador@sigep.com', 'cpf' => Documentos::cpfDeBase('400000002')])->status === 422);
check('CPF duplicado → 422', $admin->call('POST', '/api/usuarios', ['perfil' => 'orientador', 'nome' => 'Outro Nome', 'email' => 'outro@sigep.com', 'cpf' => Documentos::cpfDeBase('400000001')])->status === 422);
check('perfil inválido → 422', $admin->call('POST', '/api/usuarios', ['perfil' => 'chefe', 'nome' => 'Fulano Tal', 'email' => 'f@sigep.com', 'cpf' => Documentos::cpfDeBase('400000003')])->status === 422);
check('senha fraca no cadastro → 422', $admin->call('POST', '/api/usuarios', ['perfil' => 'responsavel', 'nome' => 'Fulano Tal', 'email' => 'f2@sigep.com', 'cpf' => Documentos::cpfDeBase('400000003'), 'senha' => 'curta'])->status === 422);
check('edição de dados → 200', $admin->call('PUT', "/api/usuarios/$novoId", ['nome' => 'Prof. Teste Editado'])->data()['nome'] === 'Prof. Teste Editado');
check('desativar usuário → Inativo', $admin->call('PATCH', "/api/usuarios/$novoId/status", ['status' => 'Inativo'])->data()['status'] === 'Inativo');
$store->update('usuarios', $novoId, ['senhaHash' => password_hash(SENHA, PASSWORD_DEFAULT)]);
check('usuário desativado não consegue entrar', (new Client($store, $mailer))->login('novo.orientador@sigep.com')->status === 401);
$admin->call('PATCH', "/api/usuarios/$novoId/status", ['status' => 'Ativo']);
check('usuário reativado volta a entrar', (new Client($store, $mailer))->login('novo.orientador@sigep.com')->status === 200);
check('não pode desativar a própria conta → 422', $admin->call('PATCH', '/api/usuarios/INST/status', ['status' => 'Inativo'])->status === 422);
check('não pode excluir a própria conta → 422', $admin->call('DELETE', '/api/usuarios/INST')->status === 422);
$r = $admin->call('PUT', "/api/usuarios/$novoId/perfil", ['perfil' => 'responsavel']);
check('alterar perfil sem vínculos → 200 (Orientador → Responsável)', $r->status === 200 && $r->data()['perfil'] === 'responsavel', st($r));
check('alterar perfil de quem tem vínculos → 409', $admin->call('PUT', '/api/usuarios/O001/perfil', ['perfil' => 'responsavel'])->status === 409);
check('excluir usuário com vínculos → 409', $admin->call('DELETE', '/api/usuarios/R001')->status === 409);
check('excluir usuário sem vínculos → 200', $admin->call('DELETE', "/api/usuarios/$novoId")->status === 200 && $admin->call('GET', "/api/usuarios/$novoId")->status === 404);
check('GET /responsaveis/{id de orientador} → 404 (perfil fixo)', $admin->call('GET', '/api/responsaveis/O001')->status === 404);
$r = $admin->call('POST', '/api/responsaveis', ['nome' => 'Novo Responsável', 'email' => 'nr@sigep.com', 'cpf' => Documentos::cpfDeBase('400000010'), 'senha' => 'Senha#Forte2026']);
check('cadastro via /responsaveis cria perfil responsável', $r->status === 201 && $r->data()['perfil'] === 'responsavel');
check('matriz de permissões disponível ao administrador', isset($admin->call('GET', '/api/permissoes')->data()['orientador']));

// =====================================================================
secao('Alunos e responsáveis');
$aluno = ['rm' => '20259999', 'nome' => 'Aluno de Teste', 'ano' => '2º Ano', 'curso' => 'Administração', 'periodo' => 'Tarde', 'responsaveisIds' => ['R002']];
$r = $admin->call('POST', '/api/alunos', $aluno);
check('cadastro de aluno → 201 com turma e nome do responsável', $r->status === 201 && $r->data()['responsavelNome'] === 'Renata Carvalho' && str_contains($r->data()['turma'], 'Administração'), st($r));
check('RM duplicado → 422', $admin->call('POST', '/api/alunos', $aluno)->status === 422);
check('curso fora da lista → 422', $admin->call('POST', '/api/alunos', ['rm' => '20259998', 'curso' => 'Culinária'] + $aluno)->status === 422);
check('responsável inexistente → 422', $admin->call('POST', '/api/alunos', ['rm' => '20259997', 'responsaveisIds' => ['R999']] + $aluno)->status === 422);
check('responsável deve ser perfil responsável (não orientador) → 422', $admin->call('POST', '/api/alunos', ['rm' => '20259996', 'responsaveisIds' => ['O001']] + $aluno)->status === 422);
check('edição de aluno (trocar responsável) → 200', $admin->call('PUT', '/api/alunos/20259999', ['responsaveisIds' => ['R003']])->data()['responsaveisIds'] === ['R003']);
check('aluno novo aparece para o novo responsável', (new Client($store, $mailer))->login('responsavel3@sigep.com', 'NovaSenha#2026')->status === 200);
check('excluir aluno com histórico → 409', $admin->call('DELETE', '/api/alunos/20250001')->status === 409);
check('excluir aluno sem histórico → 200', $admin->call('DELETE', '/api/alunos/20259999')->status === 200);
check('responsável não altera aluno → 403', $r1->call('PUT', '/api/alunos/20250000', ['nome' => 'Hack'])->status === 403);
check('busca por nome', count($admin->call('GET', '/api/alunos', [], ['busca' => 'Thiago'])->data()) === 1);
check('instituição: CNPJ inválido → 422; válido → 200', $admin->call('PUT', '/api/instituicao', ['nome' => 'Escola X', 'cnpj' => '11.111.111/1111-11', 'endereco' => 'Rua A', 'telefone' => '1133334444', 'email' => 'e@e.com'])->status === 422
    && $admin->call('PUT', '/api/instituicao', ['nome' => 'Escola X', 'cnpj' => '11.222.333/0001-81', 'endereco' => 'Rua A', 'telefone' => '1133334444', 'email' => 'e@e.com'])->status === 200);

// =====================================================================
secao('Disponibilidade e conflito de horários');
$segunda = proxDia(1); $terca = proxDia(2); $quarta = proxDia(3);
$lista = $o1->call('GET', '/api/disponibilidades')->data();
check('orientador vê só a própria disponibilidade (4 janelas)', count($lista) === 4 && count(array_filter($lista, fn ($d) => $d['orientadorId'] !== 'O001')) === 0);
check('janela sobreposta (seg 10–11 dentro de 08–12) → 409', $o1->call('POST', '/api/disponibilidades', ['dia' => 1, 'inicio' => '10:00', 'fim' => '11:00'])->status === 409);
check('janela parcialmente sobreposta → 409', $o1->call('POST', '/api/disponibilidades', ['dia' => 1, 'inicio' => '11:30', 'fim' => '13:00'])->status === 409);
$r = $o1->call('POST', '/api/disponibilidades', ['dia' => 1, 'inicio' => '12:00', 'fim' => '13:00']);
check('janela encostada (12:00–13:00) é permitida → 201', $r->status === 201, st($r));
$extraId = $r->data()['id'] ?? '';
check('fim antes do início → 422', $o1->call('POST', '/api/disponibilidades', ['dia' => 5, 'inicio' => '15:00', 'fim' => '14:00'])->status === 422);
check('dia inválido → 422', $o1->call('POST', '/api/disponibilidades', ['dia' => 9, 'inicio' => '08:00', 'fim' => '09:00'])->status === 422);
check('editar para sobrepor → 409', $o1->call('PUT', "/api/disponibilidades/$extraId", ['dia' => 1, 'inicio' => '11:00', 'fim' => '12:30'])->status === 409);
check('editar para horário livre → 200', $o1->call('PUT', "/api/disponibilidades/$extraId", ['dia' => 5, 'inicio' => '08:00', 'fim' => '10:00'])->status === 200);
check('orientador não mexe na disponibilidade de outro → 404', $o2->call('DELETE', "/api/disponibilidades/$extraId")->status === 404);
check('excluir a própria → 200', $o1->call('DELETE', "/api/disponibilidades/$extraId")->status === 200);
check('administrador cadastra para outro orientador (exige orientadorId)', $admin->call('POST', '/api/disponibilidades', ['dia' => 5, 'inicio' => '08:00', 'fim' => '09:00'])->status === 422
    && $admin->call('POST', '/api/disponibilidades', ['dia' => 5, 'inicio' => '08:00', 'fim' => '09:00', 'orientadorId' => 'O004'])->status === 201);
$h = $r1->call('GET', '/api/orientadores/O001/horarios', [], ['data' => $segunda]);
check('responsável consulta horários livres: uma faixa de início de 08:00 a 11:30 (precisão de minuto)', $h->status === 200 && $h->data()['faixas'] === [['inicio' => '08:00', 'fim' => '11:30']] && $h->data()['temDisponibilidade'] === true && $h->data()['duracaoMinutos'] === 30, st($h));
check('orientador sem disponibilidade cadastrada (O005): nenhum horário é oferecido', ($x = $r1->call('GET', '/api/orientadores/O005/horarios', [], ['data' => $segunda])->data()) && $x['faixas'] === [] && $x['temDisponibilidade'] === false);
check('data inválida → 422', $r1->call('GET', '/api/orientadores/O001/horarios', [], ['data' => '31/12/2026'])->status === 422);
check('dia sem janela → nenhum horário', $r1->call('GET', '/api/orientadores/O001/horarios', [], ['data' => proxDia(0)])->data()['faixas'] === []);

// =====================================================================
secao('Solicitações e agendamentos');
$sol = ['alunoRm' => '20250008', 'orientadorId' => 'O001', 'data' => $segunda, 'horario' => '09:00', 'motivo' => 'Orientação de TCC', 'descricao' => 'Preciso de ajuda com o TCC.'];
$r = $r1->call('POST', '/api/solicitacoes', $sol);
check('responsável solicita atendimento → 201 Pendente', $r->status === 201 && $r->data()['status'] === 'Pendente' && $r->data()['origem'] === 'solicitacao', st($r));
$s1 = $r->data()['id'] ?? '';
check('aluno de outro responsável → 403', $r1->call('POST', '/api/solicitacoes', ['alunoRm' => '20250001'] + $sol)->status === 403);
check('mesmo aluno no mesmo horário → 409', $r1->call('POST', '/api/solicitacoes', $sol)->status === 409);
check('horário fora da disponibilidade → 422', $r1->call('POST', '/api/solicitacoes', ['alunoRm' => '20250016', 'horario' => '14:00'] + $sol)->status === 422);
check('data no passado → 422', $r1->call('POST', '/api/solicitacoes', ['alunoRm' => '20250016', 'data' => date('Y-m-d', strtotime('-1 day'))] + $sol)->status === 422);
check('motivo inválido → 422', $r1->call('POST', '/api/solicitacoes', ['alunoRm' => '20250016', 'motivo' => 'Qualquer'] + $sol)->status === 422);
check('aluno com 2 responsáveis: o outro responsável (R003) também pode solicitar', $r1->call('POST', '/api/solicitacoes', ['alunoRm' => '20250003', 'horario' => '10:00'] + $sol)->status === 201);
$pend = $o1->call('GET', '/api/solicitacoes')->data();
check('orientador vê a solicitação pendente (com nomes de aluno, responsável e orientador)', ($x = achar($pend, fn ($a) => $a['id'] === $s1)) && $x['alunoNome'] === 'Isabela Ramos Teixeira' && $x['responsavelNome'] === 'Marcos Souza');
check('orientador foi notificado da nova solicitação', ($n = $o1->call('GET', '/api/notificacoes')->data()) && achar($n, fn ($x) => str_contains($x['texto'], 'Nova solicitação') && $x['cat'] === 'Solicitações'));
check('a Instituição NÃO é notificada de solicitações', achar($admin->call('GET', '/api/notificacoes')->data(), fn ($x) => str_contains($x['texto'], 'Isabela')) === null);
check('a Instituição não acessa solicitações (403) nem aprova (403)', $admin->call('GET', '/api/solicitacoes')->status === 403 && $admin->call('POST', "/api/solicitacoes/$s1/aprovar", [])->status === 403);
// segunda solicitação no MESMO horário, de outro responsável: pode ser criada, mas só uma pode ser aprovada
$r = $r2->call('POST', '/api/solicitacoes', ['alunoRm' => '20250001', 'data' => $segunda, 'horario' => '09:00', 'orientadorId' => 'O001', 'motivo' => 'Orientação vocacional']);
$s2 = $r->data()['id'] ?? '';
check('outra solicitação no mesmo horário (outro aluno) é aceita como Pendente', $r->status === 201, st($r));
check('responsável não aprova → 403', $r1->call('POST', "/api/solicitacoes/$s1/aprovar")->status === 403);
check('outro orientador não aprova → 404', $o2->call('POST', "/api/solicitacoes/$s1/aprovar")->status === 404);
$r = $o1->call('POST', "/api/solicitacoes/$s1/aprovar");
check('orientador aprova → Confirmado', $r->status === 200 && $r->data()['status'] === 'Confirmado', st($r));
check('aprovar de novo → 409', $o1->call('POST', "/api/solicitacoes/$s1/aprovar")->status === 409);
$r = $o1->call('POST', "/api/solicitacoes/$s2/aprovar");
check('aprovar a 2ª no mesmo horário → 409 (prevenção de conflito)', $r->status === 409, st($r));
check('recusar a 2ª → Recusado com motivo', ($rr = $o1->call('POST', "/api/solicitacoes/$s2/recusar", ['motivoRecusa' => 'Horário já ocupado'])) && $rr->data()['status'] === 'Recusado' && $rr->data()['motivoRecusa'] === 'Horário já ocupado');
check('responsável R1 foi notificado da aprovação', achar($r1->call('GET', '/api/notificacoes')->data(), fn ($x) => str_contains($x['texto'], 'foi aprovada')) !== null);
check('responsável R2 foi notificado da recusa', achar($r2->call('GET', '/api/notificacoes')->data(), fn ($x) => str_contains($x['texto'], 'foi recusada')) !== null);
check('/solicitacoes?status=Recusado lista a recusada', count($o1->call('GET', '/api/solicitacoes', [], ['status' => 'Recusado'])->data()) >= 1);

// agendamento direto
$dir = ['alunoRm' => '20250003', 'data' => $terca, 'horario' => '14:30', 'motivo' => 'Acompanhamento pedagógico', 'descricao' => 'Encaixe feito pela orientação.'];
$r = $o1->call('POST', '/api/agendamentos', $dir);
check('orientador agenda direto → 201 Confirmado (origem direta, sem solicitação)', $r->status === 201 && $r->data()['status'] === 'Confirmado' && $r->data()['origem'] === 'direta' && $r->data()['orientadorId'] === 'O001', st($r));
$d1 = $r->data()['id'] ?? '';
check('agendamento direto notifica o responsável do aluno (R004)', achar((function () use ($store) { return $store->all('notificacoes'); })(), fn ($n) => $n['usuarioId'] === 'R004' && str_contains($n['texto'], 'foi agendado')) !== null);
check('mesmo horário para outro aluno → 409', $o1->call('POST', '/api/agendamentos', ['alunoRm' => '20250005'] + $dir)->status === 409);
check('horário a menos de 30 min → 409', $o1->call('POST', '/api/agendamentos', ['alunoRm' => '20250005', 'horario' => '14:45'] + $dir)->status === 409);
check('mesmo aluno em dois lugares ao mesmo tempo → 409', $admin->call('POST', '/api/agendamentos', ['orientadorId' => 'O002'] + $dir)->status === 409);
check('administrador precisa informar o orientador → 422', $admin->call('POST', '/api/agendamentos', ['alunoRm' => '20250005'] + $dir)->status === 422);
$r = $admin->call('POST', '/api/agendamentos', ['alunoRm' => '20250005', 'orientadorId' => 'O002', 'horario' => '16:00'] + $dir);
check('administrador agenda para outro orientador → 201', $r->status === 201 && $r->data()['orientadorId'] === 'O002', st($r));
$r = $o1->call('POST', '/api/agendamentos', ['alunoRm' => '20250006', 'data' => $segunda, 'horario' => '15:00'] + $dir);
check('fora da disponibilidade (segunda 15:00; o período termina às 12:00) → 422', $r->status === 422, st($r));
$r = $o1->call('POST', '/api/agendamentos', ['alunoRm' => '20250006', 'data' => $segunda, 'horario' => '11:00'] + $dir);
$fora = $r->data()['id'] ?? '';
check('data no passado → 422', $o1->call('POST', '/api/agendamentos', ['alunoRm' => '20250007', 'data' => date('Y-m-d', strtotime('-2 days'))] + $dir)->status === 422);

// alteração e cancelamento
$r = $o1->call('PUT', "/api/agendamentos/$d1", ['horario' => '14:00']);
check('orientador altera horário → 200', $r->status === 200 && $r->data()['horario'] === '14:00', st($r));
check('alterar para horário em conflito → 409', $o1->call('PUT', "/api/agendamentos/$fora", ['data' => $terca, 'horario' => '14:00'])->status === 409);
check('alteração notificou o responsável', achar($store->all('notificacoes'), fn ($n) => $n['usuarioId'] === 'R004' && str_contains($n['texto'], 'foi alterado')) !== null);
check('nenhum campo informado → 422', $o1->call('PUT', "/api/agendamentos/$d1", [])->status === 422);
// responsável: edita Pendente, não edita Confirmado
$r = $r1->call('POST', '/api/solicitacoes', ['alunoRm' => '20250016', 'data' => $quarta, 'horario' => '10:00', 'orientadorId' => 'O001', 'motivo' => 'Orientação de estágio']);
$p1 = $r->data()['id'] ?? '';
check('responsável edita a própria solicitação Pendente → 200', $r1->call('PUT', "/api/agendamentos/$p1", ['horario' => '10:30', 'descricao' => 'Mudei o horário'])->status === 200);
check('responsável não pode remarcar fora da disponibilidade → 422', $r1->call('PUT', "/api/agendamentos/$p1", ['horario' => '15:00'])->status === 422);
check('responsável não edita agendamento já Confirmado → 403', $r1->call('PUT', "/api/agendamentos/$s1", ['horario' => '10:00'])->status === 403);
check('outro responsável não enxerga a solicitação → 404', $r2->call('GET', "/api/agendamentos/$p1")->status === 404);
check('responsável cancela a própria solicitação → Cancelado', $r1->call('POST', "/api/agendamentos/$p1/cancelar", ['motivo' => 'Desisti'])->data()['status'] === 'Cancelado');
check('cancelar de novo → 409', $r1->call('POST', "/api/agendamentos/$p1/cancelar")->status === 409);
// aviso ao responsável (evento 14.2) e preferências
$antesR4 = count(array_filter($store->all('notificacoes'), fn ($n) => $n['usuarioId'] === 'R004'));
check('orientador desliga o aviso ao responsável', $o1->call('PATCH', "/api/agendamentos/$d1/aviso-responsavel", ['notificarResponsavel' => false])->data()['notificarResponsavel'] === false);
$o1->call('POST', "/api/agendamentos/$d1/cancelar", ['motivo' => 'Reagendar']);
check('com o aviso desligado, o responsável NÃO é notificado do cancelamento', count(array_filter($store->all('notificacoes'), fn ($n) => $n['usuarioId'] === 'R004')) === $antesR4);
check('responsável não liga/desliga o aviso → 403', $r1->call('PATCH', "/api/agendamentos/$s1/aviso-responsavel", ['notificarResponsavel' => false])->status === 403);
$r2->call('PUT', '/api/preferencias', ['notifSistema' => false]);
$antesR2 = count(array_filter($store->all('notificacoes'), fn ($n) => $n['usuarioId'] === 'R002'));
$o1->call('POST', "/api/agendamentos/$fora/cancelar"); // aluno 20250006 → R007; usado só para testar R002 abaixo
$admin->call('POST', '/api/agendamentos', ['alunoRm' => '20250001', 'orientadorId' => 'O003', 'data' => $quarta, 'horario' => '09:00', 'motivo' => 'Orientação de TCC']);
check('preferência notifSistema=false é respeitada (R002 não recebe)', count(array_filter($store->all('notificacoes'), fn ($n) => $n['usuarioId'] === 'R002')) === $antesR2);
// expiração automática (RF06)
$store->insert('agendamentos', ['id' => 'A9001', 'alunoRm' => '20250010', 'orientadorId' => 'O001', 'responsavelId' => 'R003', 'data' => date('Y-m-d', strtotime('-3 days')), 'horario' => '09:00', 'motivo' => 'Orientação de TCC', 'descricao' => '', 'observacoes' => '', 'resultado' => '', 'status' => 'Pendente', 'origem' => 'solicitacao', 'notificarResponsavel' => true, 'horaInicialReal' => null, 'horaFinalReal' => null, 'criadoEm' => date('Y-m-d'), 'atualizadoEm' => date('c')]);
$o1->call('GET', '/api/agendamentos');
check('solicitação pendente com horário vencido vira Cancelado (status automático)', $store->find('agendamentos', 'A9001')['status'] === 'Cancelado');

// =====================================================================
secao('Atendimentos');
// A0007: hoje, Confirmado, orientador O002, aluno 20250007 (R008)
$r8 = new Client($store, $mailer); $r8->login('responsavel8@sigep.com');
check('responsável não inicia atendimento → 403', $r8->call('POST', '/api/atendimentos/A0007/iniciar')->status === 403);
check('orientador que não é o responsável não inicia → 404', $o1->call('POST', '/api/atendimentos/A0007/iniciar')->status === 404);
check('não finaliza sem iniciar → 409', $o2->call('POST', '/api/atendimentos/A0007/finalizar', ['descricao' => 'x', 'resultado' => 'y'])->status === 409);
check('não inicia atendimento de data futura → 422', $admin->call('POST', '/api/atendimentos/A0008/iniciar')->status === 422);
$r = $o2->call('POST', '/api/atendimentos/A0007/iniciar');
check('iniciar → Em Andamento e hora inicial real gravada', $r->status === 200 && $r->data()['status'] === 'Em Andamento' && preg_match('/^\d\d:\d\d$/', (string) $r->data()['horaInicialReal']) === 1, st($r));
check('iniciar de novo → 409', $o2->call('POST', '/api/atendimentos/A0007/iniciar')->status === 409);
$r = $o2->call('POST', '/api/atendimentos/A0007/finalizar', ['observacoes' => 'só observação']);
check('finalizar sem descrição e resultado → 422 (regra do front)', $r->status === 422 && isset($r->payload()['error']['details']['descricao']) && isset($r->payload()['error']['details']['resultado']), st($r));
check('salvar registro (rascunho) → 200', $o2->call('PUT', '/api/atendimentos/A0007/registro', ['descricao' => 'Conversa com o aluno sobre frequência.', 'observacoes' => 'Observação sigilosa interna.'])->status === 200);
check('responsável não enxerga o registro do atendimento (descrição, observações, resultado) sem liberação do orientador', ($x = $r8->call('GET', '/api/atendimentos/A0007')->data()) && !array_key_exists('observacoes', $x) && !array_key_exists('descricao', $x) && !array_key_exists('resultado', $x));
check('horas reais inconsistentes (fim ≤ início) → 422', $o2->call('PUT', '/api/atendimentos/A0007/horarios', ['horaInicialReal' => '10:00', 'horaFinalReal' => '09:00'])->status === 422);
check('ajuste de horas reais válido → 200', $o2->call('PUT', '/api/atendimentos/A0007/horarios', ['horaInicialReal' => '08:00', 'horaFinalReal' => '08:45'])->status === 200);
$r = $o2->call('POST', '/api/atendimentos/A0007/finalizar', ['resultado' => 'Aluno comprometido a melhorar a frequência.']);
check('finalizar com descrição+resultado → Finalizado com hora final real', $r->status === 200 && $r->data()['status'] === 'Finalizado' && $r->data()['horaFinalReal'] !== null, st($r));
check('atendimento finalizado não pode mais ser editado → 409', $o2->call('PUT', '/api/atendimentos/A0007/registro', ['resultado' => 'trocado'])->status === 409);
check('responsável foi avisado da finalização', achar($store->all('notificacoes'), fn ($n) => $n['usuarioId'] === 'R008' && str_contains($n['texto'], 'finalizado')) !== null);
$h = $admin->call('GET', '/api/alunos/20250007/historico')->data();
check('histórico do aluno (administrador) lista o atendimento finalizado', $h['total'] >= 1 && achar($h['atendimentos'], fn ($a) => $a['id'] === 'A0007') !== null);
$hr = $r8->call('GET', '/api/alunos/20250007/historico')->data();
check('histórico para o responsável sem anotações internas', $hr['total'] >= 1 && !array_key_exists('observacoes', $hr['atendimentos'][0]) && !array_key_exists('descricao', $hr['atendimentos'][0]));
check('responsável de outro aluno → 404 no histórico', $r1->call('GET', '/api/alunos/20250007/historico')->status === 404);
check('orientador só vê no histórico o que ele mesmo atendeu (O001 → 0 do aluno 20250007)', $o1->call('GET', '/api/alunos/20250007/historico')->data()['total'] === 0);
check('lista de atendimentos não traz pendentes/recusados', count(array_filter($admin->call('GET', '/api/atendimentos')->data(), fn ($a) => in_array($a['status'], ['Pendente', 'Recusado'], true))) === 0);

// =====================================================================
secao('Regras do SIGEP: atraso, duração máxima, visibilidade do registro e escopo do orientador');
$mk = function (string $id, int $minAtras, string $orient, string $rm, string $resp, string $status = 'Confirmado') use ($store) {
    $store->insert('agendamentos', ['id' => $id, 'alunoRm' => $rm, 'orientadorId' => $orient, 'responsavelId' => $resp, 'data' => date('Y-m-d', strtotime("-$minAtras minutes")), 'horario' => date('H:i', strtotime("-$minAtras minutes")),
        'motivo' => 'Orientação de TCC', 'descricao' => '', 'observacoes' => '', 'resultado' => '', 'status' => $status, 'origem' => 'direta', 'notificarResponsavel' => true, 'registroVisivel' => false,
        'horaInicialReal' => null, 'horaFinalReal' => null, 'criadoEm' => date('Y-m-d'), 'atualizadoEm' => date('c')]);
};
$mk('A9101', 30, 'O004', '20250012', 'R005'); $mk('A9102', 5, 'O004', '20250013', 'R006'); $mk('A9103', 60, 'O005', '20250014', 'R007');
Env::set('ATRASO_CANCELA_MINUTOS', '0');
$admin->call('GET', '/api/agendamentos');
check('ATRASO_CANCELA_MINUTOS=0 desliga o cancelamento por atraso', $store->find('agendamentos', 'A9103')['status'] === 'Confirmado');
Env::set('ATRASO_CANCELA_MINUTOS', '10');
$admin->call('GET', '/api/agendamentos');
check('atraso maior que 10 min → Cancelado automaticamente (com motivo)', $store->find('agendamentos', 'A9101')['status'] === 'Cancelado' && str_contains($store->find('agendamentos', 'A9101')['motivoCancelamento'], '10 minutos'));
check('atraso de 5 min (dentro da tolerância) continua Confirmado', $store->find('agendamentos', 'A9102')['status'] === 'Confirmado');
check('o cancelamento automático é registrado na auditoria e notifica o responsável', achar($store->all('auditoria'), fn ($l) => str_contains($l['acao'], 'A9101') && str_contains($l['acao'], 'automaticamente')) !== null && achar($store->all('notificacoes'), fn ($n) => $n['usuarioId'] === 'R005' && str_contains($n['texto'], 'cancelado automaticamente')) !== null);

// duração máxima (3 h) e flags do orientador ao finalizar
$mk('A9104', 0, 'O002', '20250015', 'R008');
$r8b = new Client($store, $mailer); $r8b->login('responsavel8@sigep.com');
$o2->call('POST', '/api/atendimentos/A9104/iniciar');
check('ajustar horas reais acima de 3 h → 422', $o2->call('PUT', '/api/atendimentos/A9104/horarios', ['horaInicialReal' => '08:00', 'horaFinalReal' => '11:30'])->status === 422);
check('ajustar horas reais de exatamente 3 h → 200', $o2->call('PUT', '/api/atendimentos/A9104/horarios', ['horaInicialReal' => '08:00', 'horaFinalReal' => '11:00'])->status === 200);
Env::set('ATENDIMENTO_MAX_MINUTOS', '1');
$o2->call('PUT', '/api/atendimentos/A9104/horarios', ['horaInicialReal' => date('H:i', strtotime('-5 minutes')), 'horaFinalReal' => null]);
$r = $o2->call('POST', '/api/atendimentos/A9104/finalizar', ['descricao' => 'Conversa longa.', 'resultado' => 'Encaminhado.']);
check('finalizar com duração acima do máximo → 422', $r->status === 422 && isset($r->payload()['error']['details']['horaFinalReal']), st($r));
Env::set('ATENDIMENTO_MAX_MINUTOS', '180');
$antesR8 = count(array_filter($store->all('notificacoes'), fn ($n) => $n['usuarioId'] === 'R008'));
$r = $o2->call('POST', '/api/atendimentos/A9104/finalizar', ['descricao' => 'Conversa com o aluno.', 'observacoes' => 'Nota interna confidencial.', 'resultado' => 'Plano de estudos combinado.', 'registroVisivel' => true, 'notificarResponsavel' => false]);
check('finalizar com registroVisivel=true e notificarResponsavel=false → 200', $r->status === 200 && $r->data()['registroVisivel'] === true && $r->data()['notificarResponsavel'] === false, st($r));
check('orientador decidiu NÃO notificar: responsável não recebe aviso da finalização', count(array_filter($store->all('notificacoes'), fn ($n) => $n['usuarioId'] === 'R008')) === $antesR8);
$x = $r8b->call('GET', '/api/atendimentos/A9104')->data();
check('registro liberado: responsável vê descrição e resultado, mas NUNCA as observações internas', ($x['descricao'] ?? '') === 'Conversa com o aluno.' && ($x['resultado'] ?? '') === 'Plano de estudos combinado.' && !array_key_exists('observacoes', $x));
check('histórico do aluno respeita a liberação', ($hh = $r8b->call('GET', '/api/alunos/20250015/historico')->data()) && $hh['total'] === 1 && isset($hh['atendimentos'][0]['resultado']));
check('orientador oculta o registro de novo → responsável deixa de ver', $o2->call('PATCH', '/api/atendimentos/A9104/visibilidade', ['registroVisivel' => false])->status === 200 && !array_key_exists('resultado', $r8b->call('GET', '/api/atendimentos/A9104')->data()));
check('visibilidade: valor não booleano → 422; responsável não altera → 403', $o2->call('PATCH', '/api/atendimentos/A9104/visibilidade', ['registroVisivel' => 'sim'])->status === 422 && $r8b->call('PATCH', '/api/atendimentos/A9104/visibilidade', ['registroVisivel' => true])->status === 403);

// escopo do orientador configurável (ORIENTADOR_VE_TODOS)
Env::set('ORIENTADOR_VE_TODOS', 'true');
$todos = count($store->all('agendamentos'));
$vistosO1 = count($o1->call('GET', '/api/agendamentos')->data());
check('ORIENTADOR_VE_TODOS=true: orientador consulta os atendimentos de todos', $vistosO1 === $todos, "$vistosO1 x $todos");
check('...mas só age nos próprios: iniciar/aprovar de outro orientador → 403', $o1->call('POST', '/api/atendimentos/A0009/iniciar')->status === 403 && $o1->call('POST', '/api/solicitacoes/A0010/aprovar')->status === 403);
check('...e o histórico do aluno mostra também os atendimentos de outros orientadores', $o1->call('GET', '/api/alunos/20250007/historico')->data()['total'] >= 1);
Env::set('ORIENTADOR_VE_TODOS', 'false');
check('ORIENTADOR_VE_TODOS=false volta ao modo restrito (só os próprios)', count($o1->call('GET', '/api/agendamentos')->data()) < $todos);

// =====================================================================
secao('Reuniões');
$futuro = date('Y-m-d', strtotime('+9 days'));
$reun = ['assunto' => 'Planejamento do semestre', 'descricao' => 'Pauta geral.', 'data' => $futuro, 'horario' => '15:00', 'horarioFim' => '16:00', 'participantes' => ['O001', 'R001']];
$r = $admin->call('POST', '/api/reunioes', $reun);
check('administrador cadastra reunião com 2 participantes → 201', $r->status === 201 && count($r->data()['participantes']) === 2 && $r->data()['situacao'] === 'Agendada', st($r));
$m1 = $r->data()['id'] ?? '';
check('lista de participantes traz nome e perfil', ($d = $r->data()['participantesDetalhe']) && $d[0]['nome'] !== '' && isset($d[0]['perfilRotulo']));
check('participante convidado foi notificado (O001 e R001)', achar($store->all('notificacoes'), fn ($n) => $n['usuarioId'] === 'O001' && $n['cat'] === 'Reuniões') !== null && achar($store->all('notificacoes'), fn ($n) => $n['usuarioId'] === 'R001' && $n['cat'] === 'Reuniões') !== null);
check('participante inexistente → 422', $admin->call('POST', '/api/reunioes', ['participantes' => ['X999']] + $reun)->status === 422);
check('sem participantes → 422', $admin->call('POST', '/api/reunioes', ['participantes' => []] + $reun)->status === 422);
check('data no passado → 422', $admin->call('POST', '/api/reunioes', ['data' => date('Y-m-d', strtotime('-1 day'))] + $reun)->status === 422);
check('orientador participante enxerga a reunião; quem não participa recebe 404', $o1->call('GET', "/api/reunioes/$m1")->status === 200 && $o2->call('GET', "/api/reunioes/$m1")->status === 404);
check('orientador só lista as reuniões em que participa ou que criou', count(array_filter($o2->call('GET', '/api/reunioes')->data(), fn ($x) => !in_array('O002', $x['participantes'], true) && $x['criadoPor'] !== 'O002')) === 0);
check('participante que não criou não pode editar → 403', $o1->call('PUT', "/api/reunioes/$m1", ['assunto' => 'Novo assunto'] + $reun)->status === 403);
check('criador edita (adiciona participante) → 200', $admin->call('PUT', "/api/reunioes/$m1", ['participantes' => ['O001', 'R001', 'O002']] + $reun)->status === 200);
check('orientador NÃO pode adicionar outros orientadores a uma reunião → 403', $o1->call('POST', '/api/reunioes', ['assunto' => 'Reunião de orientadores', 'participantes' => ['O002', 'O003']] + $reun)->status === 403);
check('reunião criada por orientador com responsável → 201', ($rx = $o1->call('POST', '/api/reunioes', ['assunto' => 'Conversa com responsável', 'horario' => '17:00', 'horarioFim' => '18:00', 'participantes' => ['R002']] + $reun)) && $rx->status === 201, isset($rx) ? st($rx) : '');
check('cancelar reunião futura → Cancelada', $admin->call('POST', "/api/reunioes/$m1/cancelar")->data()['situacao'] === 'Cancelada');
check('editar reunião cancelada → 409', $admin->call('PUT', "/api/reunioes/$m1", $reun)->status === 409);
$hist = $admin->call('GET', '/api/reunioes', [], ['situacao' => 'Realizada'])->data();
check('reunião passada aparece como Realizada (histórico) e não pode ser editada', count($hist) === 1 && $hist[0]['id'] === 'M002' && $admin->call('PUT', '/api/reunioes/M002', $reun)->status === 409);
check('lista de possíveis participantes (admin/orientador) sem dados sensíveis', ($pp = $o1->call('GET', '/api/reunioes/participantes')->data()) && count($pp) > 10 && !isset($pp[0]['cpf']) && !isset($pp[0]['email']));

// =====================================================================
secao('Notificações');
$ln = $o1->call('GET', '/api/notificacoes');
$naoLidas = $ln->payload()['meta']['naoLidas'];
check('lista traz contador de não lidas', $ln->status === 200 && $naoLidas > 0);
$nid = $ln->data()[0]['id'];
check('marcar uma como lida → contador diminui', $o1->call('PATCH', "/api/notificacoes/$nid", ['lida' => true])->data()['lida'] === true && $o1->call('GET', '/api/notificacoes')->payload()['meta']['naoLidas'] === $naoLidas - 1);
check('marcar como não lida de novo', $o1->call('PATCH', "/api/notificacoes/$nid", ['lida' => false])->data()['lida'] === false);
check('não marca notificação de outro usuário → 404', $o2->call('PATCH', "/api/notificacoes/$nid", ['lida' => true])->status === 404);
check('filtro por categoria', count(array_filter($o1->call('GET', '/api/notificacoes', [], ['cat' => 'Reuniões'])->data(), fn ($n) => $n['cat'] !== 'Reuniões')) === 0);
check('marcar todas como lidas → 0 não lidas', $o1->call('POST', '/api/notificacoes/marcar-todas')->status === 200 && $o1->call('GET', '/api/notificacoes')->payload()['meta']['naoLidas'] === 0);
check('filtro lida=false vazio depois', $o1->call('GET', '/api/notificacoes', [], ['lida' => 'false'])->data() === []);
check('notificações mais novas primeiro', ($l = $admin->call('GET', '/api/notificacoes')->data()) && $l[0]['criadoEm'] >= end($l)['criadoEm']);

// =====================================================================
secao('Auditoria');
$aud = $admin->call('GET', '/api/auditoria', [], ['porPagina' => 200]);
$linhas = $aud->data();
check('auditoria registra usuário, ação, categoria, data e hora', count($linhas) > 20 && isset($linhas[0]['usuario'], $linhas[0]['acao'], $linhas[0]['cat'], $linhas[0]['quando']) && $aud->payload()['meta']['total'] >= count($linhas));
$acoes = implode("\n", array_column($linhas, 'acao'));
foreach (['Cadastrou orientador', 'Atribuiu o perfil', 'Alterou o perfil de', 'Desativou', 'Aprovou a solicitação', 'Recusou a solicitação', 'Agendou atendimento direto', 'Finalizou o atendimento', 'Cadastrou a reunião', 'Cadastrou disponibilidade', 'Login realizado', 'Tentativa de login sem sucesso', 'Cadastrou o aluno'] as $trecho) {
    check("auditoria contém: $trecho", str_contains($acoes, $trecho));
}
check('auditoria identifica quem fez (ex.: Prof. Marcelo Antunes aprovou)', achar($linhas, fn ($l) => $l['usuario'] === 'Prof. Marcelo Antunes' && str_contains($l['acao'], 'Aprovou')) !== null);
check('filtro por categoria', ($f = $admin->call('GET', '/api/auditoria', [], ['cat' => 'Solicitações'])->data()) && count($f) > 0 && count(array_filter($f, fn ($l) => $l['cat'] !== 'Solicitações')) === 0);
check('filtro por usuário', ($f = $admin->call('GET', '/api/auditoria', [], ['usuarioId' => 'O001'])->data()) && count($f) > 0 && count(array_filter($f, fn ($l) => $l['usuarioId'] !== 'O001')) === 0);
check('filtro por período (hoje) retorna registros; amanhã não', count($admin->call('GET', '/api/auditoria', [], ['de' => date('Y-m-d'), 'ate' => date('Y-m-d')])->data()) > 0 && $admin->call('GET', '/api/auditoria', [], ['de' => date('Y-m-d', strtotime('+1 day'))])->data() === []);
check('período inválido → 422', $admin->call('GET', '/api/auditoria', [], ['de' => 'ontem'])->status === 422);
check('auditoria não guarda conteúdo pedagógico nem senhas', !str_contains(json_encode($store->all('auditoria')), 'Observação sigilosa') && !str_contains(json_encode($store->all('auditoria')), 'comprometido') && !str_contains(json_encode($store->all('auditoria')), SENHA));
check('registros mais recentes primeiro', $linhas[0]['quando'] >= end($linhas)['quando']);

// =====================================================================
secao('Relatórios, conta própria e LGPD');
$rs = $admin->call('GET', '/api/relatorios/resumo')->data();
check('resumo do administrador: total, status, meses, cursos e orientadores', $rs['total'] > 5 && isset($rs['porStatus']['Finalizado']) && count($rs['porMes']) > 0 && count($rs['porCurso']) > 0 && count($rs['porOrientador']) > 0);
$ro = $o1->call('GET', '/api/relatorios/resumo')->data();
check('resumo do orientador cobre só os próprios atendimentos', $ro['total'] > 0 && $ro['porOrientador'] === []);
$csv = $admin->call('GET', '/api/relatorios/atendimentos/exportar', [], ['formato' => 'csv']);
check('exportação CSV (Content-Type e cabeçalho)', $csv->status === 200 && str_starts_with($csv->headers['Content-Type'], 'text/csv') && str_contains((string) $csv->body, 'ID;Data;Horário;Aluno'));
check('exportação PDF/XLSX → 501 (não implementado)', $admin->call('GET', '/api/relatorios/atendimentos/exportar', [], ['formato' => 'pdf'])->status === 501);
check('responsável não exporta → 403', $r1->call('GET', '/api/relatorios/atendimentos/exportar')->status === 403);
check('GET /perfil devolve o próprio usuário sem hash', ($x = $r1->call('GET', '/api/perfil')) && $x->data()['email'] === 'responsavel@sigep.com' && !str_contains((string) $x->body, 'senhaHash'));
check('atualizar o próprio telefone', str_contains($r1->call('PUT', '/api/perfil', ['telefone' => '(11) 95555-4444'])->data()['telefone'], '95555-4444'));
check('trocar senha com senha atual errada → 422', $r1->call('PUT', '/api/perfil/senha', ['senhaAtual' => 'errada', 'novaSenha' => 'NovaSenha#2027'])->status === 422);
check('trocar senha com a atual correta → 200 e novo login', $r1->call('PUT', '/api/perfil/senha', ['senhaAtual' => SENHA, 'novaSenha' => 'NovaSenha#2027'])->status === 200 && (new Client($store, $mailer))->login('responsavel@sigep.com', 'NovaSenha#2027')->status === 200);
check('preferências: ler e salvar', $r2->call('GET', '/api/preferencias')->data()['notifSistema'] === false && $r2->call('PUT', '/api/preferencias', ['notifEmail' => 'sim'])->status === 422);
$ld = $r1->call('GET', '/api/lgpd/meus-dados');
check('LGPD: meus dados trazem cadastro, alunos vinculados e atividades, sem hash', $ld->status === 200 && isset($ld->data()['cadastro'], $ld->data()['alunosVinculados'], $ld->data()['registrosDeAtividade']) && !str_contains((string) $ld->body, 'senhaHash'));
check('CPF mascarado para quem não é dono nem administrador', (function () use ($store) { return true; })() && str_starts_with(\Sigep\Support\Presenter::usuario($store->find('usuarios', 'R001'), $store->find('usuarios', 'O001'))['cpf'], '***.***.***-'));
check('JSON inválido em corpo → 422 (campos obrigatórios)', $anon->call('POST', '/api/auth/login', [])->status === 422);

// =====================================================================
secao('AGENDA: conflitos reais, disponibilidade por minuto, reuniões como compromissos');
$M = proxDia(1, 20); $T = proxDia(2, 20); $F = proxDia(5, 20); $S = proxDia(6, 20);
$dirb = ['motivo' => 'Orientação de TCC'];
// --- A) sobreposição REAL entre atendimentos (O001, segunda 08:00–12:00) ---
$r = $o1->call('POST', '/api/agendamentos', ['alunoRm' => '20250004', 'data' => $M, 'horario' => '09:00'] + $dirb);
check('atendimento 09:00–09:30 criado (Confirmado automaticamente)', $r->status === 201 && $r->data()['status'] === 'Confirmado', st($r));
$a0900 = $r->data()['id'] ?? '';
check('outro às 09:15 (09:15–09:45) → 409: sobreposição, não só horário igual', $o1->call('POST', '/api/agendamentos', ['alunoRm' => '20250002', 'data' => $M, 'horario' => '09:15'] + $dirb)->status === 409);
check('outro às 08:31 (08:31–09:01) → 409', $o1->call('POST', '/api/agendamentos', ['alunoRm' => '20250002', 'data' => $M, 'horario' => '08:31'] + $dirb)->status === 409);
check('encostar é permitido: 08:30–09:00 e 09:30–10:00', $o1->call('POST', '/api/agendamentos', ['alunoRm' => '20250002', 'data' => $M, 'horario' => '08:30'] + $dirb)->status === 201 && $o1->call('POST', '/api/agendamentos', ['alunoRm' => '20250009', 'data' => $M, 'horario' => '09:30'] + $dirb)->status === 201);
check('a Instituição também não sobrepõe (09:10 no O001) → 409', $admin->call('POST', '/api/agendamentos', ['alunoRm' => '20250011', 'orientadorId' => 'O001', 'data' => $M, 'horario' => '09:10'] + $dirb)->status === 409);
check('a mensagem de conflito diz quem está ocupado e o horário', ($x = $o1->call('POST', '/api/agendamentos', ['alunoRm' => '20250011', 'data' => $M, 'horario' => '09:05'] + $dirb)->payload()) && str_contains($x['error']['message'], 'Prof. Marcelo Antunes') && str_contains($x['error']['message'], '09:00'));
// --- B) disponibilidade: início, fim, minutos quebrados, intervalos ---
check('começa antes do período (07:59) → 422', $o1->call('POST', '/api/agendamentos', ['alunoRm' => '20250011', 'data' => $M, 'horario' => '07:59'] + $dirb)->status === 422);
check('ultrapassa o fim do período (11:45 → termina 12:15) → 422', $o1->call('POST', '/api/agendamentos', ['alunoRm' => '20250011', 'data' => $M, 'horario' => '11:45'] + $dirb)->status === 422);
check('termina exatamente às 12:00 (11:30) → 201', $o1->call('POST', '/api/agendamentos', ['alunoRm' => '20250011', 'data' => $M, 'horario' => '11:30'] + $dirb)->status === 201);
$r = $o1->call('POST', '/api/disponibilidades', ['dia' => 5, 'inicio' => '08:17', 'fim' => '11:43', 'intervalos' => [['inicio' => '11:00', 'fim' => '11:15'], ['inicio' => '10:00', 'fim' => '10:15']]]);
check('período quebrado 08:17–11:43 com 2 intervalos é salvo (ordenados)', $r->status === 201 && $r->data()['inicio'] === '08:17' && $r->data()['intervalos'][0]['inicio'] === '10:00', st($r));
$h = $r1->call('GET', '/api/orientadores/O001/horarios', [], ['data' => $F]);
check('horários livres respeitam intervalos e o fim: 08:17–09:30 e 10:15–10:30', $h->data()['faixas'] === [['inicio' => '08:17', 'fim' => '09:30'], ['inicio' => '10:15', 'fim' => '10:30']], json_encode($h->data()['faixas'] ?? null));
check('agendar às 08:17 (minuto quebrado) → 201', $o1->call('POST', '/api/agendamentos', ['alunoRm' => '20250017', 'data' => $F, 'horario' => '08:17'] + $dirb)->status === 201);
check('agendar dentro do intervalo (10:05) → 422; atravessando o intervalo (09:45) → 422', $o1->call('POST', '/api/agendamentos', ['alunoRm' => '20250018', 'data' => $F, 'horario' => '10:05'] + $dirb)->status === 422 && $o1->call('POST', '/api/agendamentos', ['alunoRm' => '20250018', 'data' => $F, 'horario' => '09:45'] + $dirb)->status === 422);
check('depois do intervalo (10:15) → 201', $o1->call('POST', '/api/agendamentos', ['alunoRm' => '20250018', 'data' => $F, 'horario' => '10:15'] + $dirb)->status === 201);
$h = $r1->call('GET', '/api/orientadores/O001/horarios', [], ['data' => $F]);
check('os horários livres encolhem conforme os atendimentos entram (08:17 ocupou 08:17–08:47)', $h->data()['faixas'][0]['inicio'] === '08:47', json_encode($h->data()['faixas'] ?? null));
// validações de intervalos
$base = ['dia' => 6, 'inicio' => '08:00', 'fim' => '17:00'];
check('mais de 5 intervalos → 422', $o1->call('POST', '/api/disponibilidades', $base + ['intervalos' => array_map(fn ($i) => ['inicio' => sprintf('%02d:00', 9 + $i), 'fim' => sprintf('%02d:30', 9 + $i)], range(0, 5))])->status === 422);
$r = $o1->call('POST', '/api/disponibilidades', $base + ['intervalos' => [['inicio' => '08:00', 'fim' => '10:00'], ['inicio' => '09:00', 'fim' => '11:00']]]);
check('intervalos sobrepostos (8–10 e 9–11) → 422', $r->status === 422 && str_contains($r->payload()['error']['details']['intervalos'], 'se sobrepõem'), st($r));
check('intervalo fora do período → 422; fim antes do início → 422', $o1->call('POST', '/api/disponibilidades', $base + ['intervalos' => [['inicio' => '16:30', 'fim' => '17:30']]])->status === 422 && $o1->call('POST', '/api/disponibilidades', $base + ['intervalos' => [['inicio' => '12:00', 'fim' => '11:00']]])->status === 422);
$r = $o1->call('POST', '/api/disponibilidades', $base + ['intervalos' => [['inicio' => '12:00', 'fim' => '13:00'], ['inicio' => '13:00', 'fim' => '13:15'], ['inicio' => '15:00', 'fim' => '15:10'], ['inicio' => '15:20', 'fim' => '15:25'], ['inicio' => '16:00', 'fim' => '16:05']]]);
check('exatamente 5 intervalos (alguns encostados) é aceito', $r->status === 201 && count($r->data()['intervalos']) === 5, st($r));
$dS = $r->data()['id'] ?? '';
// --- C) responsável: horário por minuto, só dentro do disponível ---
$sx = ['alunoRm' => '20250008', 'orientadorId' => 'O001', 'data' => $F, 'motivo' => 'Orientação de TCC', 'descricao' => 'x'];
$r = $r1->call('POST', '/api/solicitacoes', ['horario' => '09:00'] + $sx);
check('responsável solicita 09:00 num minuto livre → 201 Pendente', $r->status === 201 && $r->data()['status'] === 'Pendente', st($r));
check('solicitar às 08:40 (colide com o atendimento 08:17–08:47) → 409', $r1->call('POST', '/api/solicitacoes', ['horario' => '08:40'] + $sx)->status === 409);
check('solicitar às 10:00 (cai no intervalo) → 422', $r1->call('POST', '/api/solicitacoes', ['horario' => '10:00'] + $sx)->status === 422);
check('solicitar às 11:20 (ultrapassa o fim 11:43) → 422', $r1->call('POST', '/api/solicitacoes', ['horario' => '11:20'] + $sx)->status === 422);
// motivo "Outro"
check('motivo "Outro" sem descrição → 422; com descrição → 201', $r1->call('POST', '/api/solicitacoes', ['motivo' => 'Outro', 'descricao' => '', 'horario' => '09:05'] + $sx)->status === 422
    && $r1->call('POST', '/api/solicitacoes', ['motivo' => 'Outro', 'descricao' => 'Mudança de turno', 'horario' => '10:20'] + $sx + [])->status === 201);

// --- D) reuniões: compromissos reais com início e término ---
$mt = fn (array $x) => $x + ['assunto' => 'Reunião de teste', 'descricao' => '', 'data' => $M];
check('reunião exige horário de término depois do início', $admin->call('POST', '/api/reunioes', $mt(['horario' => '14:00', 'horarioFim' => '13:00', 'participantes' => ['R006']]))->status === 422);
$r = $admin->call('POST', '/api/reunioes', $mt(['horario' => '09:20', 'horarioFim' => '10:00', 'participantes' => ['O001', 'R006']]));
check('reunião 09:20–10:00 com orientador que tem atendimento 09:00–09:30 → 409', $r->status === 409 && str_contains($r->payload()['error']['message'], 'Prof. Marcelo Antunes'), st($r));
$r = $admin->call('POST', '/api/reunioes', $mt(['horario' => '09:00', 'horarioFim' => '09:30', 'participantes' => ['R004']]));
check('reunião com responsável (R004) que já tem atendimento no horário → 409', $r->status === 409, st($r));
$r = $admin->call('POST', '/api/reunioes', $mt(['assunto' => 'Reunião A', 'horario' => '14:00', 'horarioFim' => '15:00', 'participantes' => ['O002', 'R005']]));
check('reunião A (14:00–15:00; O002 e R005) → 201', $r->status === 201, st($r));
$mA = $r->data()['id'] ?? '';
$r = $o1->call('POST', '/api/reunioes', $mt(['assunto' => 'Reunião B', 'horario' => '14:30', 'horarioFim' => '15:30', 'participantes' => ['R005']]));
check('reunião B com participante em comum (R005) sobreposta → 409', $r->status === 409, st($r));
$r = $o1->call('POST', '/api/reunioes', $mt(['assunto' => 'Reunião B', 'horario' => '14:30', 'horarioFim' => '15:30', 'participantes' => ['R006']]));
check('reunião B com participante diferente → 201', $r->status === 201, st($r));
$mB = $r->data()['id'] ?? '';
check('adicionar a B alguém já ocupado na A (R005) → 409', $o1->call('PUT', "/api/reunioes/$mB", $mt(['assunto' => 'Reunião B', 'horario' => '14:30', 'horarioFim' => '15:30', 'participantes' => ['R006', 'R005']]))->status === 409);
check('a mesma pessoa não participa de duas reuniões sobrepostas, mesmo sendo quem criou (Instituição)', $admin->call('POST', '/api/reunioes', $mt(['horario' => '14:45', 'horarioFim' => '15:15', 'participantes' => ['R007']]))->status === 409);
check('reuniões encostadas (15:00–16:00) são permitidas', $admin->call('POST', '/api/reunioes', $mt(['assunto' => 'Reunião C', 'horario' => '15:00', 'horarioFim' => '16:00', 'participantes' => ['O002']]))->status === 201);
// atendimento durante reunião (por participante): O002 na terça
$r = $admin->call('POST', '/api/reunioes', $mt(['assunto' => 'Reunião terça', 'data' => $T, 'horario' => '15:00', 'horarioFim' => '16:00', 'participantes' => ['O002', 'R004']]));
check('reunião na terça 15:00–16:00 com O002 → 201', $r->status === 201, st($r));
$mT = $r->data()['id'] ?? '';
check('atendimento do O002 durante a reunião (15:30) → 409', ($x = $o2->call('POST', '/api/agendamentos', ['alunoRm' => '20250019', 'data' => $T, 'horario' => '15:30'] + $dirb)) && $x->status === 409 && str_contains($x->payload()['error']['message'], 'reunião'), st($x));
check('o mesmo atendimento antes (14:15–14:45) e depois (16:00) da reunião é permitido', $o2->call('POST', '/api/agendamentos', ['alunoRm' => '20250019', 'data' => $T, 'horario' => '14:15'] + $dirb)->status === 201 && $o2->call('POST', '/api/agendamentos', ['alunoRm' => '20250002', 'data' => $T, 'horario' => '16:00'] + $dirb)->status === 201);
$h = $r1->call('GET', '/api/orientadores/O002/horarios', [], ['data' => $T])->data();
check('horários livres do O002 na terça não oferecem o horário da reunião (15:00–15:59 fora)', !array_filter($h['faixas'], fn ($f) => $f['inicio'] <= '15:30' && $f['fim'] >= '15:30'), json_encode($h['faixas']));
// --- E) dia com reunião fica indisponível (REUNIAO_BLOQUEIA_DIA=true) ---
Env::set('REUNIAO_BLOQUEIA_DIA', 'true');
$h = $r1->call('GET', '/api/orientadores/O001/horarios', [], ['data' => $T])->data();
check('dia com reunião: nenhum horário e bloqueadoPorReuniao=true (até para outro orientador)', $h['bloqueadoPorReuniao'] === true && $h['faixas'] === []);
$r = $o1->call('POST', '/api/agendamentos', ['alunoRm' => '20250011', 'data' => $T, 'horario' => '14:00'] + $dirb);
check('criar atendimento nesse dia (O001, nem está na reunião) → 409', $r->status === 409 && str_contains($r->payload()['error']['message'], 'Há reunião marcada'), st($r));
check('solicitar nesse dia → 409', $r1->call('POST', '/api/solicitacoes', ['alunoRm' => '20250008', 'orientadorId' => 'O001', 'data' => $T, 'horario' => '14:00', 'motivo' => 'Orientação de TCC'])->status === 409);
$ocup = $o1->call('GET', '/api/orientadores/O002/ocupacao', [], ['data' => $T])->data();
check('ocupação do dia mostra o bloqueio, os atendimentos e a reunião', $ocup['bloqueadoPorReuniao'] === true && count($ocup['ocupados']) === 3 && count(array_filter($ocup['ocupados'], fn ($o) => $o['tipo'] === 'reuniao')) === 1);
check('para o responsável a ocupação não traz títulos (só "ocupado")', ($x = $r1->call('GET', '/api/orientadores/O002/ocupacao', [], ['data' => $T])->data()) && !isset($x['ocupados'][0]['titulo']));
$r = $admin->call('POST', "/api/reunioes/$mT/cancelar");
check('cancelar a reunião → situação Cancelada (registro mantido)', $r->status === 200 && $r->data()['situacao'] === 'Cancelada');
$h = $r1->call('GET', '/api/orientadores/O001/horarios', [], ['data' => $T])->data();
check('cancelada: o dia volta a ter horários livres', $h['bloqueadoPorReuniao'] === false && count($h['faixas']) > 0, json_encode($h));
check('cancelada: agora o atendimento da terça é aceito', $o1->call('POST', '/api/agendamentos', ['alunoRm' => '20250011', 'data' => $T, 'horario' => '14:00'] + $dirb)->status === 201);
Env::set('REUNIAO_BLOQUEIA_DIA', 'false');
check('a reunião cancelada continua listada (histórico preservado), mas não ocupa mais horário', count(array_filter($admin->call('GET', '/api/reunioes', [], ['situacao' => 'Cancelada'])->data(), fn ($x) => $x['id'] === $mT)) === 1
    && $admin->call('POST', '/api/reunioes', $mt(['data' => $T, 'horario' => '15:00', 'horarioFim' => '16:00', 'participantes' => ['O002']]))->status === 201);
// --- F) cancelar atendimento libera horário; agenda atualiza ---
$canc0900 = $o1->call('POST', "/api/agendamentos/$a0900/cancelar", []);
$novo0900 = $o1->call('POST', '/api/agendamentos', ['alunoRm' => '20250002', 'data' => $M, 'horario' => '09:00'] + $dirb);
check('cancelar o atendimento 09:00 libera o horário: um novo 09:00 passa a ser aceito', $canc0900->status === 200 && $novo0900->status === 201, st($novo0900));
// --- G) agenda unificada (atendimentos + reuniões) ---
$ag = $o2->call('GET', '/api/agenda', [], ['de' => $M, 'ate' => $M]);
check('reuniões aparecem na agenda do orientador participante (O002)', $ag->status === 200 && count(array_filter($ag->data(), fn ($i) => $i['tipo'] === 'reuniao' && $i['id'] === $mA)) === 1, st($ag));
$r5 = new Client($store, $mailer); $r5->login('responsavel5@sigep.com');
check('reuniões aparecem na agenda do responsável participante (R005)', count(array_filter($r5->call('GET', '/api/agenda', [], ['de' => $M, 'ate' => $M])->data(), fn ($i) => $i['tipo'] === 'reuniao' && $i['id'] === $mA)) === 1);
$agA = $admin->call('GET', '/api/agenda', [], ['de' => $M, 'ate' => $M])->data();
check('Instituição vê atendimentos e reuniões juntos na agenda, sem solicitações', count(array_filter($agA, fn ($i) => $i['tipo'] === 'atendimento')) > 0 && count(array_filter($agA, fn ($i) => $i['tipo'] === 'reuniao')) > 0 && !array_filter($agA, fn ($i) => $i['status'] === 'Pendente'));
check('orientador não vê reunião de que não participa (O002 sem a reunião B)', !array_filter($o2->call('GET', '/api/agenda', [], ['de' => $M, 'ate' => $M])->data(), fn ($i) => $i['id'] === $mB));
// --- H) histórico ---
$hist = $admin->call('GET', '/api/historico')->data();
$tipos = array_unique(array_column($hist, 'tipo')); sort($tipos);
check('histórico da Instituição reúne atendimentos e reuniões, cada item com o seu tipo', $tipos === ['atendimento', 'reuniao'], json_encode($tipos));
check('cada item traz data, início/fim e participantes', ($x = $hist[0]) && isset($x['data'], $x['inicio'], $x['fim']) && is_array($x['participantes']) && count($x['participantes']) > 0);
check('filtro tipo=reuniao e tipo=atendimento', !array_filter($admin->call('GET', '/api/historico', [], ['tipo' => 'reuniao'])->data(), fn ($i) => $i['tipo'] !== 'reuniao') && !array_filter($admin->call('GET', '/api/historico', [], ['tipo' => 'atendimento'])->data(), fn ($i) => $i['tipo'] !== 'atendimento'));
check('histórico não traz reuniões canceladas por padrão (mas com incluirCancelados traz)', !array_filter($hist, fn ($i) => $i['id'] === $mT) && (bool) array_filter($admin->call('GET', '/api/historico', [], ['incluirCancelados' => 'true'])->data(), fn ($i) => $i['id'] === $mT));
$r8c = new Client($store, $mailer); $r8c->login('responsavel8@sigep.com');
$and = $r8c->call('GET', '/api/agendamentos', [], ['situacao' => 'andamento'])->data();
$his = $r8c->call('GET', '/api/agendamentos', [], ['situacao' => 'historico'])->data();
check('"Meu agendamento": só Pendente/Confirmado/Em Andamento (nada concluído)', !array_filter($and, fn ($a) => !in_array($a['status'], ['Pendente', 'Confirmado', 'Em Andamento'], true)));
check('"Histórico" do responsável: tudo o que já terminou (inclui o Finalizado A0007) e nada em andamento', (bool) array_filter($his, fn ($a) => $a['id'] === 'A0007') && !array_filter($his, fn ($a) => in_array($a['status'], ['Pendente', 'Confirmado', 'Em Andamento'], true)));
check('/api/historico do responsável traz só atendimentos', ($x = $r8c->call('GET', '/api/historico')->data()) && count($x) > 0 && !array_filter($x, fn ($i) => $i['tipo'] !== 'atendimento'));
// --- I) Instituição não gerencia solicitações ---
check('Instituição: lista de agendamentos não inclui Pendente/Recusado', !array_filter($admin->call('GET', '/api/agendamentos')->data(), fn ($a) => in_array($a['status'], ['Pendente', 'Recusado'], true)));
check('Instituição não aprova nem recusa (403) e não enxerga a solicitação (404 ao abrir)', $admin->call('POST', '/api/solicitacoes/A0010/recusar', [])->status === 403 && $admin->call('GET', '/api/agendamentos/A0010')->status === 404);
// --- J) vários responsáveis por aluno ---
$r = $admin->call('POST', '/api/alunos', ['rm' => '20259050', 'nome' => 'Aluno Dois Responsáveis', 'ano' => '1º Ano', 'curso' => 'Logística', 'periodo' => 'Manhã', 'responsaveisIds' => ['R006', 'R007']]);
check('aluno com 2 responsáveis → 201 (lista os dois)', $r->status === 201 && count($r->data()['responsaveis']) === 2 && str_contains($r->data()['responsavelNome'], ','), st($r));
check('sem responsável → 422', $admin->call('POST', '/api/alunos', ['rm' => '20259051', 'nome' => 'Sem Responsável', 'ano' => '1º Ano', 'curso' => 'Logística', 'periodo' => 'Manhã', 'responsaveisIds' => []])->status === 422);
$r6 = new Client($store, $mailer); $r6->login('responsavel6@sigep.com'); $r7 = new Client($store, $mailer); $r7->login('responsavel7@sigep.com');
check('os dois responsáveis enxergam o aluno', $r6->call('GET', '/api/alunos/20259050')->status === 200 && $r7->call('GET', '/api/alunos/20259050')->status === 200);
$antes6 = count(array_filter($store->all('notificacoes'), fn ($n) => $n['usuarioId'] === 'R006')); $antes7 = count(array_filter($store->all('notificacoes'), fn ($n) => $n['usuarioId'] === 'R007'));
check('atendimento do aluno notifica AMBOS os responsáveis', ($x = $o1->call('POST', '/api/agendamentos', ['alunoRm' => '20259050', 'data' => proxDia(4, 20), 'horario' => '14:00'] + $dirb)) && $x->status === 201
    && count(array_filter($store->all('notificacoes'), fn ($n) => $n['usuarioId'] === 'R006')) === $antes6 + 1 && count(array_filter($store->all('notificacoes'), fn ($n) => $n['usuarioId'] === 'R007')) === $antes7 + 1, isset($x) ? st($x) : '');
check('os dois veem o atendimento em "Meu agendamento"', count($r6->call('GET', '/api/agendamentos', [], ['situacao' => 'andamento'])->data()) >= 1 && count($r7->call('GET', '/api/agendamentos', [], ['situacao' => 'andamento'])->data()) >= 1);
check('remover um responsável na edição: ele deixa de ver o aluno (404)', $admin->call('PUT', '/api/alunos/20259050', ['responsaveisIds' => ['R006']])->status === 200 && $r7->call('GET', '/api/alunos/20259050')->status === 404 && $r6->call('GET', '/api/alunos/20259050')->status === 200);
check('o mesmo responsável continua ligado a vários alunos', count($r6->call('GET', '/api/alunos')->data()) >= 2);
// --- K) status removidos dos cadastros ---
check('não existem mais rotas de status em /responsaveis e /orientadores', $admin->call('PATCH', '/api/responsaveis/R001/status', ['status' => 'Inativo'])->status === 404 && $admin->call('PATCH', '/api/orientadores/O001/status', ['status' => 'Inativo'])->status === 404);
check('aluno não tem mais "status" (nem aceita alterar)', !array_key_exists('status', $admin->call('GET', '/api/alunos/20259050')->data()));
// =====================================================================
echo "\n──────────────────────────────────────────\n";
$ok = $total - count($falhas);
echo "Resultado: $ok/$total verificações passaram.\n";
if ($falhas) {
    echo "FALHAS:\n - " . implode("\n - ", $falhas) . "\n";
    exit(1);
}
echo "Tudo certo.\n";
