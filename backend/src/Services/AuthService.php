<?php
declare(strict_types=1);

namespace Sigep\Services;

use Sigep\Core\Env;
use Sigep\Core\HttpException;
use Sigep\Core\SessionInterface;
use Sigep\Models\Categoria;
use Sigep\Repositories\Contracts\LoginThrottleRepositoryInterface;
use Sigep\Repositories\Contracts\UsuarioRepositoryInterface;
use Sigep\Support\Presenter;
use Sigep\Support\Senha;

/** RF01 — gerenciar sessão: login, logout e usuário logado. */
final class AuthService
{
    private static ?string $hashFalso = null;

    public function __construct(
        private UsuarioRepositoryInterface $users,
        private LoginThrottleRepositoryInterface $throttle,
        private SessionInterface $session,
        private AuditService $audit,
        private PermissionService $perms
    ) {
    }

    public function login(string $email, string $senha, string $ip): array
    {
        $email = mb_strtolower(trim($email));
        $chave = 'login|' . hash('sha256', $email . '|' . $ip);
        $max = Env::int('LOGIN_MAX_ATTEMPTS', 5);
        $janela = Env::int('LOGIN_WINDOW_SECONDS', 900);
        if ($this->throttle->tentativasRecentes($chave, $janela) >= $max) {
            throw HttpException::tooMany($janela);
        }

        $u = $this->users->findByEmail($email);
        // password_verify também roda quando o e-mail não existe, para o tempo de resposta não revelar contas.
        self::$hashFalso ??= Senha::hash('sigep-hash-falso');
        $confere = Senha::verificar($senha, $u['senhaHash'] ?? self::$hashFalso);
        if (!$u || !$confere || $u['status'] !== 'Ativo') {
            $this->throttle->registrar($chave);
            $this->audit->log(null, 'Tentativa de login sem sucesso', Categoria::AUTENTICACAO, $ip);
            throw HttpException::unauthorized('E-mail ou senha inválidos.');
        }
        $this->throttle->limpar($chave);

        $mudancas = ['ultimoLogin' => date('c')];
        if (Senha::precisaRehash($u['senhaHash'])) {
            $mudancas['senhaHash'] = Senha::hash($senha);
        }
        $u = $this->users->update($u['id'], $mudancas);

        $this->session->regenerate(); // evita fixação de sessão
        $this->session->set('uid', $u['id']);
        $this->session->set('login_at', time());
        $this->session->set('last_seen', time());
        $this->audit->log($u, 'Login realizado', Categoria::AUTENTICACAO, $ip);
        return $this->sessaoDe($u);
    }

    public function logout(?array $user, string $ip): void
    {
        if ($user) {
            $this->audit->log($user, 'Logout realizado', Categoria::AUTENTICACAO, $ip);
        }
        $this->session->destroy();
    }

    public function sessaoDe(array $u): array
    {
        return [
            'usuario' => Presenter::usuario($u, $u),
            'permissoes' => $this->perms->doPerfil($u['perfil']),
            'csrfToken' => $this->session->csrfToken(),
        ];
    }
}
