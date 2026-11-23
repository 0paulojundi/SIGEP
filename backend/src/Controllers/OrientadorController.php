<?php
declare(strict_types=1);

namespace Sigep\Controllers;

use Sigep\Core\Request;
use Sigep\Core\Response;
use Sigep\Models\Perfil;

final class OrientadorController extends UsuarioController
{
    protected ?string $perfil = Perfil::ORIENTADOR;

    /** Lista enxuta (id, nome) de orientadores ativos — usada pelo responsável ao solicitar atendimento. */
    public function basico(Request $r): Response { return $this->ok($this->svc->basico(Perfil::ORIENTADOR)); }
}
