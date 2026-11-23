<?php
declare(strict_types=1);

namespace Sigep\Controllers;

use Sigep\Core\Request;
use Sigep\Core\Response;
use Sigep\Core\SessionInterface;
use Sigep\Core\Validator;
use Sigep\Services\AuthService;
use Sigep\Services\PasswordResetService;
use Sigep\Services\UsuarioService;

final class AuthController extends Controller
{
    public function __construct(
        private AuthService $auth,
        private UsuarioService $usuarios,
        private PasswordResetService $reset,
        private SessionInterface $session
    ) {
    }

    public function login(Request $r): Response
    {
        $d = Validator::validate($r->body, ['email' => 'required|email', 'senha' => 'required|string|max:128']);
        return $this->ok($this->auth->login($d['email'], $d['senha'], $r->ip));
    }

    public function logout(Request $r): Response
    {
        $this->auth->logout($r->user(), $r->ip);
        return $this->ok(['mensagem' => 'Sessão encerrada.']);
    }

    public function me(Request $r): Response { return $this->ok($this->auth->sessaoDe($this->user($r))); }

    public function csrf(Request $r): Response { return $this->ok(['csrfToken' => $this->session->csrfToken()]); }

    public function registrar(Request $r): Response { return $this->created($this->usuarios->autoCadastro($r->body, $r->ip)); }

    public function esqueciSenha(Request $r): Response
    {
        $d = Validator::validate($r->body, ['email' => 'required|email']);
        $this->reset->solicitar($d['email'], $r->ip);
        return $this->ok(['mensagem' => 'Se o e-mail estiver cadastrado, você receberá as instruções para redefinir a senha.']);
    }

    public function redefinirSenha(Request $r): Response
    {
        $d = Validator::validate($r->body, ['token' => 'required|string|max:128', 'novaSenha' => 'required|string|max:128']);
        $this->reset->redefinir($d['token'], $d['novaSenha']);
        return $this->ok(['mensagem' => 'Senha redefinida. Faça login com a nova senha.']);
    }
}
