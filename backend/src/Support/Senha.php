<?php
declare(strict_types=1);

namespace Sigep\Support;

use Sigep\Core\Env;
use Sigep\Core\HttpException;

/** Política e hash de senhas (password_hash / password_verify). */
final class Senha
{
    public static function hash(string $senha): string
    {
        return password_hash($senha, PASSWORD_DEFAULT);
    }

    public static function verificar(string $senha, string $hash): bool
    {
        return password_verify($senha, $hash);
    }

    public static function precisaRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_DEFAULT);
    }

    public static function validar(string $senha, ?string $email = null, string $campo = 'senha'): void
    {
        $min = Env::int('PASSWORD_MIN_LENGTH', 8);
        $erro = null;
        if (mb_strlen($senha) < $min) {
            $erro = "A senha deve ter ao menos $min caracteres.";
        } elseif (!preg_match('/[A-Za-z]/', $senha) || !preg_match('/\d/', $senha)) {
            $erro = 'A senha deve ter letras e números.';
        } elseif ($email !== null && mb_strtolower($senha) === mb_strtolower($email)) {
            $erro = 'A senha não pode ser igual ao e-mail.';
        }
        if ($erro) {
            throw HttpException::unprocessable([$campo => $erro]);
        }
    }

    public static function aleatoria(): string
    {
        return bin2hex(random_bytes(16));
    }
}
