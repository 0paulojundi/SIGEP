<?php
declare(strict_types=1);

namespace Sigep\Models;

/** Perfis de acesso. O valor 'instituicao' é o mesmo usado no front-end (Administrador/Instituição). */
final class Perfil
{
    public const ADMIN = 'instituicao';
    public const ORIENTADOR = 'orientador';
    public const RESPONSAVEL = 'responsavel';

    public static function todos(): array { return [self::ADMIN, self::ORIENTADOR, self::RESPONSAVEL]; }

    public static function rotulo(string $p): string
    {
        return ['instituicao' => 'Administrador', 'orientador' => 'Orientador', 'responsavel' => 'Responsável'][$p] ?? $p;
    }

    /** Prefixo dos IDs gerados (mesmo padrão do front: ADM001, O001, R001). */
    public static function prefixo(string $p): string
    {
        return ['instituicao' => 'ADM', 'orientador' => 'O', 'responsavel' => 'R'][$p] ?? 'U';
    }
}
