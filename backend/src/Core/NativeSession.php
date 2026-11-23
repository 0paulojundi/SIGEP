<?php
declare(strict_types=1);

namespace Sigep\Core;

/** Sessão nativa do PHP com cookie endurecido (HttpOnly, SameSite, Secure quando HTTPS). */
final class NativeSession implements SessionInterface
{
    public function __construct(private string $savePath, private string $name = 'SIGEPSESS', private int $lifetime = 28800)
    {
    }

    private function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (!is_dir($this->savePath)) {
            @mkdir($this->savePath, 0700, true);
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) $this->lifetime);
        session_name($this->name);
        session_save_path($this->savePath);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public function get(string $key, mixed $default = null): mixed { $this->start(); return $_SESSION[$key] ?? $default; }
    public function set(string $key, mixed $value): void { $this->start(); $_SESSION[$key] = $value; }
    public function forget(string $key): void { $this->start(); unset($_SESSION[$key]); }
    public function regenerate(): void { $this->start(); session_regenerate_id(true); }

    public function destroy(): void
    {
        $this->start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
        }
        session_destroy();
    }

    public function csrfToken(): string
    {
        $this->start();
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }
}
