<?php
declare(strict_types=1);

namespace Sigep\Core;

interface SessionInterface
{
    public function get(string $key, mixed $default = null): mixed;
    public function set(string $key, mixed $value): void;
    public function forget(string $key): void;
    /** Troca o ID da sessão (evita fixação de sessão no login). */
    public function regenerate(): void;
    public function destroy(): void;
    public function csrfToken(): string;
}
