<?php
declare(strict_types=1);

namespace Sigep\Controllers;

use Sigep\Core\Request;
use Sigep\Core\Response;
use Sigep\Services\ReuniaoService;
use Sigep\Services\UsuarioService;

final class ReuniaoController extends Controller
{
    public function __construct(private ReuniaoService $svc, private UsuarioService $usuarios)
    {
    }

    public function index(Request $r): Response { return $this->ok($this->svc->listar($this->user($r), $r->query)); }
    public function show(Request $r): Response { return $this->ok($this->svc->obter($this->user($r), $r->params['id'])); }
    public function store(Request $r): Response { return $this->created($this->svc->criar($this->user($r), $r->body)); }
    public function update(Request $r): Response { return $this->ok($this->svc->atualizar($this->user($r), $r->params['id'], $r->body)); }
    public function cancelar(Request $r): Response { return $this->ok($this->svc->cancelar($this->user($r), $r->params['id'])); }
    /** Pessoas que podem ser convidadas (todos os usuários ativos, só id/nome/perfil). */
    public function participantes(Request $r): Response { return $this->ok($this->usuarios->basico(null)); }
}
