<?php
declare(strict_types=1);

namespace Sigep\Services;

use Sigep\Core\Env;
use Sigep\Core\HttpException;
use Sigep\Core\Validator;
use Sigep\Models\Categoria;
use Sigep\Models\Perfil;
use Sigep\Repositories\Contracts\AgendamentoRepositoryInterface;
use Sigep\Repositories\Contracts\AlunoRepositoryInterface;
use Sigep\Repositories\Contracts\DisponibilidadeRepositoryInterface;
use Sigep\Repositories\Contracts\LoginThrottleRepositoryInterface;
use Sigep\Repositories\Contracts\ReuniaoRepositoryInterface;
use Sigep\Repositories\Contracts\UsuarioRepositoryInterface;
use Sigep\Support\Documentos;
use Sigep\Support\Presenter;
use Sigep\Support\Senha;

/** Usuários dos três perfis: cadastro, edição, status, perfil de acesso (RF01, RF09, RF10). */
final class UsuarioService
{
    public function __construct(
        private UsuarioRepositoryInterface $users,
        private AlunoRepositoryInterface $alunos,
        private AgendamentoRepositoryInterface $agendamentos,
        private ReuniaoRepositoryInterface $reunioes,
        private DisponibilidadeRepositoryInterface $disp,
        private LoginThrottleRepositoryInterface $throttle,
        private PasswordResetService $reset,
        private AuditService $audit,
        private PermissionService $perms
    ) {
    }

    // ---------- consultas ----------
    public function listar(array $ator, ?string $perfil, array $q): array
    {
        $rows = $perfil ? $this->users->byPerfil($perfil) : $this->users->all();
        if (!empty($q['perfil']) && !$perfil) {
            $rows = array_filter($rows, fn ($u) => $u['perfil'] === $q['perfil']);
        }
        if (!empty($q['status'])) {
            $rows = array_filter($rows, fn ($u) => $u['status'] === $q['status']);
        }
        if (!empty($q['busca'])) {
            $b = mb_strtolower((string) $q['busca']);
            $rows = array_filter($rows, fn ($u) => str_contains(mb_strtolower($u['nome'] . ' ' . $u['email']), $b));
        }
        usort($rows, fn ($a, $b) => strcmp($a['nome'], $b['nome']));
        return array_map(fn ($u) => Presenter::usuario($u, $ator), array_values($rows));
    }

    public function obter(array $ator, string $id, ?string $perfil = null): array
    {
        return Presenter::usuario($this->buscar($id, $perfil), $ator);
    }

    /** Lista enxuta (id, nome) para telas de escolha: orientadores ativos ou todos os possíveis participantes. */
    public function basico(?string $perfil): array
    {
        $rows = $perfil ? $this->users->byPerfil($perfil) : $this->users->all();
        $rows = array_filter($rows, fn ($u) => $u['status'] === 'Ativo');
        usort($rows, fn ($a, $b) => strcmp($a['nome'], $b['nome']));
        return array_map([Presenter::class, 'basico'], array_values($rows));
    }

    // ---------- escrita (administrador) ----------
    public function criar(array $ator, array $in, ?string $perfilFixo = null): array
    {
        $d = Validator::validate($in, [
            'nome' => 'required|string|min:3|max:120', 'email' => 'required|email|max:160', 'cpf' => 'required|cpf',
            'telefone' => 'nullable|phone', 'senha' => 'nullable|string|max:128',
            'perfil' => $perfilFixo ? 'nullable|string' : 'required|in:' . implode(',', Perfil::todos()),
        ]);
        $d['perfil'] = $perfilFixo ?? $d['perfil'];
        $this->garantirUnicos($d['email'], $d['cpf'] ?? null);

        $semSenha = empty($d['senha']);
        if (!$semSenha) {
            Senha::validar($d['senha'], $d['email']);
        }
        $u = $this->users->create([
            'perfil' => $d['perfil'], 'nome' => $d['nome'], 'email' => mb_strtolower($d['email']),
            'cpf' => Documentos::digitos($d['cpf']), 'telefone' => Documentos::digitos($d['telefone'] ?? ''),
            // sem senha informada: nasce com senha aleatória e recebe um link para definir a própria
            'senhaHash' => Senha::hash($semSenha ? Senha::aleatoria() : $d['senha']),
            'status' => 'Ativo', 'prefs' => $this->prefsPadrao(), 'criadoEm' => date('c'), 'atualizadoEm' => date('c'), 'ultimoLogin' => null,
        ]);
        if ($semSenha) {
            $this->reset->enviarDefinicaoInicial($u);
        }
        $this->audit->log($ator, 'Cadastrou ' . mb_strtolower(Perfil::rotulo($u['perfil'])) . " {$u['nome']} ({$u['id']})", Categoria::USUARIOS);
        $this->audit->log($ator, "Atribuiu o perfil " . Perfil::rotulo($u['perfil']) . " a {$u['nome']}", Categoria::PERMISSOES);
        return Presenter::usuario($u, $ator);
    }

    public function atualizar(array $ator, string $id, array $in, ?string $perfilFixo = null): array
    {
        $u = $this->buscar($id, $perfilFixo);
        $d = Validator::validate($in, [
            'nome' => 'string|min:3|max:120', 'email' => 'email|max:160', 'cpf' => 'cpf', 'telefone' => 'nullable|phone', 'senha' => 'nullable|string|max:128',
        ]);
        $mud = ['atualizadoEm' => date('c')];
        if (isset($d['nome'])) { $mud['nome'] = $d['nome']; }
        if (isset($d['email'])) { $mud['email'] = mb_strtolower($d['email']); }
        if (isset($d['cpf'])) { $mud['cpf'] = Documentos::digitos($d['cpf']); }
        if (array_key_exists('telefone', $d)) { $mud['telefone'] = Documentos::digitos($d['telefone'] ?? ''); }
        $this->garantirUnicos($mud['email'] ?? $u['email'], $mud['cpf'] ?? null, $id);
        $trocouSenha = !empty($d['senha']);
        if ($trocouSenha) {
            Senha::validar($d['senha'], $mud['email'] ?? $u['email']);
            $mud['senhaHash'] = Senha::hash($d['senha']);
        }
        $novo = $this->users->update($id, $mud);
        $this->audit->log($ator, "Alterou dados de {$novo['nome']} ({$id})" . ($trocouSenha ? ' e redefiniu a senha' : ''), Categoria::USUARIOS);
        return Presenter::usuario($novo, $ator);
    }

    public function alterarStatus(array $ator, string $id, string $status, ?string $perfilFixo = null): array
    {
        if (!in_array($status, ['Ativo', 'Inativo'], true)) {
            throw HttpException::unprocessable(['status' => 'Use Ativo ou Inativo.']);
        }
        $u = $this->buscar($id, $perfilFixo);
        if ($status === 'Inativo') {
            if ($u['id'] === $ator['id']) {
                throw HttpException::unprocessable(['status' => 'Você não pode desativar a própria conta.']);
            }
            $this->exigirOutroAdminAtivo($u);
        }
        $novo = $this->users->update($id, ['status' => $status, 'atualizadoEm' => date('c')]);
        $this->audit->log($ator, ($status === 'Ativo' ? 'Reativou ' : 'Desativou ') . "{$novo['nome']} ({$id})", Categoria::USUARIOS);
        return Presenter::usuario($novo, $ator);
    }

    /** Evento 9.2 — Administrador vincula perfil ao usuário. Só é permitido sem vínculos que dependam do perfil atual. */
    public function alterarPerfil(array $ator, string $id, string $novoPerfil): array
    {
        if (!in_array($novoPerfil, Perfil::todos(), true)) {
            throw HttpException::unprocessable(['perfil' => 'Perfil inválido.']);
        }
        $u = $this->buscar($id);
        if ($u['id'] === $ator['id']) {
            throw HttpException::unprocessable(['perfil' => 'Você não pode alterar o próprio perfil.']);
        }
        if ($u['perfil'] === $novoPerfil) {
            return Presenter::usuario($u, $ator);
        }
        $this->exigirOutroAdminAtivo($u);
        if ($this->alunos->byResponsavel($id) || $this->agendamentos->byOrientador($id) || $this->agendamentos->byResponsavel($id)
            || $this->disp->byOrientador($id) || $this->reunioes->paraUsuario($id)) {
            throw HttpException::conflict('Não é possível alterar o perfil: há alunos, agendamentos, disponibilidades ou reuniões vinculados. Desative o usuário e crie outro.');
        }
        $novo = $this->users->update($id, ['perfil' => $novoPerfil, 'atualizadoEm' => date('c')]);
        $this->audit->log($ator, "Alterou o perfil de {$novo['nome']}: " . Perfil::rotulo($u['perfil']) . ' → ' . Perfil::rotulo($novoPerfil), Categoria::PERMISSOES);
        return Presenter::usuario($novo, $ator);
    }

    public function excluir(array $ator, string $id, ?string $perfilFixo = null): void
    {
        $u = $this->buscar($id, $perfilFixo);
        if ($u['id'] === $ator['id']) {
            throw HttpException::unprocessable(['id' => 'Você não pode excluir a própria conta.']);
        }
        $this->exigirOutroAdminAtivo($u);
        if ($this->alunos->byResponsavel($id) || $this->agendamentos->byOrientador($id) || $this->agendamentos->byResponsavel($id)) {
            throw HttpException::conflict('Há alunos ou atendimentos vinculados. Em vez de excluir, desative o usuário.');
        }
        foreach ($this->disp->byOrientador($id) as $d) {
            $this->disp->delete($d['id']);
        }
        $this->users->delete($id);
        $this->audit->log($ator, "Excluiu {$u['nome']} ({$id})", Categoria::USUARIOS);
    }

    // ---------- auto-serviço ----------
    /** "Cadastre-se" do front: cria um responsável. Pode ser desligado com SELF_REGISTRATION=false. */
    public function autoCadastro(array $in, string $ip): array
    {
        if (!Env::bool('SELF_REGISTRATION', true)) {
            throw HttpException::forbidden('O cadastro público está desativado. Procure a secretaria.');
        }
        $chave = 'registro|' . hash('sha256', $ip);
        if ($this->throttle->tentativasRecentes($chave, 3600) >= 10) {
            throw HttpException::tooMany(3600);
        }
        $this->throttle->registrar($chave);
        $d = Validator::validate($in, [
            'nome' => 'required|string|min:3|max:120', 'email' => 'required|email|max:160', 'cpf' => 'required|cpf',
            'telefone' => 'nullable|phone', 'senha' => 'required|string|max:128',
        ]);
        $this->garantirUnicos($d['email'], $d['cpf']);
        Senha::validar($d['senha'], $d['email']);
        $u = $this->users->create([
            'perfil' => Perfil::RESPONSAVEL, 'nome' => $d['nome'], 'email' => mb_strtolower($d['email']),
            'cpf' => Documentos::digitos($d['cpf']), 'telefone' => Documentos::digitos($d['telefone'] ?? ''),
            'senhaHash' => Senha::hash($d['senha']), 'status' => 'Ativo', 'prefs' => $this->prefsPadrao(),
            'criadoEm' => date('c'), 'atualizadoEm' => date('c'), 'ultimoLogin' => null,
        ]);
        $this->audit->log($u, 'Cadastro de responsável realizado pelo próprio usuário', Categoria::USUARIOS, $ip);
        return Presenter::usuario($u, $u);
    }

    public function atualizarProprioPerfil(array $ator, array $in): array
    {
        $d = Validator::validate($in, ['nome' => 'string|min:3|max:120', 'email' => 'email|max:160', 'telefone' => 'nullable|phone']);
        $mud = ['atualizadoEm' => date('c')];
        if (isset($d['nome'])) { $mud['nome'] = $d['nome']; }
        if (isset($d['email'])) { $mud['email'] = mb_strtolower($d['email']); }
        if (array_key_exists('telefone', $d)) { $mud['telefone'] = Documentos::digitos($d['telefone'] ?? ''); }
        $this->garantirUnicos($mud['email'] ?? $ator['email'], null, $ator['id']);
        $novo = $this->users->update($ator['id'], $mud);
        $this->audit->log($novo, 'Atualizou os próprios dados', Categoria::USUARIOS);
        return Presenter::usuario($novo, $novo);
    }

    public function trocarSenha(array $ator, string $atual, string $nova): void
    {
        if (!Senha::verificar($atual, $ator['senhaHash'])) {
            throw HttpException::unprocessable(['senhaAtual' => 'Senha atual incorreta.']);
        }
        Senha::validar($nova, $ator['email'], 'novaSenha');
        $this->users->update($ator['id'], ['senhaHash' => Senha::hash($nova), 'atualizadoEm' => date('c')]);
        $this->audit->log($ator, 'Alterou a própria senha', Categoria::AUTENTICACAO);
    }

    public function preferencias(array $ator): array
    {
        return array_merge($this->prefsPadrao(), $ator['prefs'] ?? []);
    }

    public function salvarPreferencias(array $ator, array $in): array
    {
        $novas = $this->preferencias($ator);
        foreach (array_keys($this->prefsPadrao()) as $k) {
            if (array_key_exists($k, $in)) {
                if (!is_bool($in[$k])) {
                    throw HttpException::unprocessable([$k => 'Deve ser verdadeiro ou falso.']);
                }
                $novas[$k] = $in[$k];
            }
        }
        $this->users->update($ator['id'], ['prefs' => $novas]);
        return $novas;
    }

    // ---------- internos ----------
    private function prefsPadrao(): array
    {
        // mesmas chaves da tela Configurações do front
        return ['notifEmail' => true, 'notifSistema' => true, 'confirmarExclusao' => true, 'conflitoHorario' => true];
    }

    private function buscar(string $id, ?string $perfil = null): array
    {
        $u = $this->users->find($id);
        if (!$u || ($perfil && $u['perfil'] !== $perfil)) {
            throw HttpException::notFound('Usuário não encontrado.');
        }
        return $u;
    }

    private function garantirUnicos(string $email, ?string $cpf, ?string $ignorarId = null): void
    {
        $e = [];
        $porEmail = $this->users->findByEmail($email);
        if ($porEmail && $porEmail['id'] !== $ignorarId) {
            $e['email'] = 'Este e-mail já está em uso.';
        }
        if ($cpf) {
            $porCpf = $this->users->findByCpf(Documentos::digitos($cpf));
            if ($porCpf && $porCpf['id'] !== $ignorarId) {
                $e['cpf'] = 'Este CPF já está cadastrado.';
            }
        }
        if ($e) {
            throw HttpException::unprocessable($e);
        }
    }

    /** O sistema nunca pode ficar sem administrador ativo. */
    private function exigirOutroAdminAtivo(array $alvo): void
    {
        if ($alvo['perfil'] !== Perfil::ADMIN || $alvo['status'] !== 'Ativo') {
            return;
        }
        $outros = array_filter($this->users->byPerfil(Perfil::ADMIN), fn ($u) => $u['id'] !== $alvo['id'] && $u['status'] === 'Ativo');
        if (!$outros) {
            throw HttpException::conflict('Esta operação deixaria o sistema sem administrador ativo.');
        }
    }
}
