<?php
declare(strict_types=1);

namespace Sigep\Core;

/** Sessão em memória, usada nos testes automatizados (um objeto = um "navegador"). */
final class ArraySession implements SessionInterface
{
    private array $data = [];

    public function get(string $key, mixed $default = null): mixed { return $this->data[$key] ?? $default; }
    public function set(string $key, mixed $value): void { $this->data[$key] = $value; }
    public function forget(string $key): void { unset($this->data[$key]); }
    public function regenerate(): void { $this->data['_regen'] = ($this->data['_regen'] ?? 0) + 1; }
    public function destroy(): void { $this->data = []; }

    public function csrfToken(): string
    {
        if (!isset($this->data['_csrf'])) {
            $this->data['_csrf'] = bin2hex(random_bytes(32));
        }
        return $this->data['_csrf'];
    }
}
