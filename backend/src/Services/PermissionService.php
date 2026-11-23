<?php
declare(strict_types=1);

namespace Sigep\Services;

use Sigep\Core\HttpException;

/** Autorização por perfil (RF09). Consulta a matriz de config/permissions.php. */
final class PermissionService
{
    public function __construct(private array $matriz)
    {
    }

    /** $permissao pode ser uma lista separada por vírgula: basta ter UMA delas. */
    public function pode(array $user, string $permissao): bool
    {
        $minhas = $this->matriz[$user['perfil']] ?? [];
        if (in_array('*', $minhas, true)) {
            return true;
        }
        foreach (explode(',', $permissao) as $p) {
            if (in_array(trim($p), $minhas, true)) {
                return true;
            }
        }
        return false;
    }

    public function exigir(array $user, string $permissao): void
    {
        if (!$this->pode($user, $permissao)) {
            throw HttpException::forbidden();
        }
    }

    public function doPerfil(string $perfil): array
    {
        return $this->matriz[$perfil] ?? [];
    }

    public function matriz(): array
    {
        return $this->matriz;
    }
}
