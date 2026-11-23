<?php
declare(strict_types=1);

namespace Sigep\Controllers;

use Sigep\Models\Perfil;

final class ResponsavelController extends UsuarioController
{
    protected ?string $perfil = Perfil::RESPONSAVEL;
}
