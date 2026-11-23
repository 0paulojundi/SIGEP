<?php
declare(strict_types=1);

namespace Sigep\Services;

use Sigep\Core\Env;
use Sigep\Core\HttpException;
use Sigep\Mail\MailerInterface;
use Sigep\Models\Categoria;
use Sigep\Repositories\Contracts\LoginThrottleRepositoryInterface;
use Sigep\Repositories\Contracts\PasswordResetRepositoryInterface;
use Sigep\Repositories\Contracts\UsuarioRepositoryInterface;
use Sigep\Support\Senha;

/** RF01 (evento 1.3) — recuperação e redefinição de senha. O token vai por e-mail; no banco fica só o hash dele. */
final class PasswordResetService
{
    public function __construct(
        private UsuarioRepositoryInterface $users,
        private PasswordResetRepositoryInterface $tokens,
        private LoginThrottleRepositoryInterface $throttle,
        private MailerInterface $mailer,
        private AuditService $audit
    ) {
    }

    /** Resposta sempre igual (não revela se o e-mail existe). */
    public function solicitar(string $email, string $ip): void
    {
        $email = mb_strtolower(trim($email));
        $key = 'reset|' . hash('sha256', $email . '|' . $ip);
        if ($this->throttle->tentativasRecentes($key, 900) >= 3) {
            return; // silencioso
        }
        $this->throttle->registrar($key);
        $u = $this->users->findByEmail($email);
        if ($u && $u['status'] === 'Ativo') {
            $this->enviar($u, false);
        }
    }

    public function enviarDefinicaoInicial(array $u): void
    {
        $this->enviar($u, true);
    }

    private function enviar(array $u, bool $inicial): void
    {
        $this->tokens->invalidarDoUsuario($u['id']);
        $token = bin2hex(random_bytes(32));
        $this->tokens->create([
            'usuarioId' => $u['id'], 'tokenHash' => hash('sha256', $token),
            'expiraEm' => date('c', time() + Env::int('PASSWORD_RESET_MINUTES', 60) * 60),
            'usadoEm' => null, 'criadoEm' => date('c'),
        ]);
        $url = str_replace('{token}', $token, (string) Env::get('PASSWORD_RESET_URL', 'http://localhost:8000/frontend/sigep.html#redefinir-senha/{token}'));
        $assunto = $inicial ? 'SIGEP — defina sua senha de acesso' : 'SIGEP — redefinição de senha';
        $corpo = "Olá, {$u['nome']}.\n\n" . ($inicial ? "Sua conta no SIGEP foi criada. Defina sua senha pelo link abaixo:" : "Recebemos um pedido para redefinir sua senha. Use o link abaixo:")
            . "\n\n$url\n\nO link vale por " . Env::int('PASSWORD_RESET_MINUTES', 60) . " minutos e só pode ser usado uma vez. Se você não pediu isso, ignore esta mensagem.";
        $this->mailer->send($u['email'], $assunto, $corpo);
        $this->audit->log($u, $inicial ? 'Link de definição de senha enviado' : 'Redefinição de senha solicitada', Categoria::AUTENTICACAO);
    }

    public function redefinir(string $token, string $novaSenha): void
    {
        $t = $this->tokens->findByHash(hash('sha256', $token));
        if (!$t || !empty($t['usadoEm']) || strtotime($t['expiraEm']) < time()) {
            throw HttpException::unprocessable(['token' => 'Link inválido ou expirado. Solicite um novo.']);
        }
        $u = $this->users->find($t['usuarioId']);
        if (!$u || $u['status'] !== 'Ativo') {
            throw HttpException::unprocessable(['token' => 'Link inválido ou expirado. Solicite um novo.']);
        }
        Senha::validar($novaSenha, $u['email'], 'novaSenha');
        $this->users->update($u['id'], ['senhaHash' => Senha::hash($novaSenha), 'atualizadoEm' => date('c')]);
        $this->tokens->invalidarDoUsuario($u['id']);
        $this->audit->log($u, 'Senha redefinida', Categoria::AUTENTICACAO);
    }
}
