<?php
declare(strict_types=1);

namespace Sigep\Controllers;

use Sigep\Core\Request;
use Sigep\Core\Response;
use Sigep\Services\AtendimentoService;

final class AtendimentoController extends Controller
{
    public function __construct(private AtendimentoService $svc)
    {
    }

    public function index(Request $r): Response { return $this->ok($this->svc->listar($this->user($r), $r->query)); }
    public function show(Request $r): Response { return $this->ok($this->svc->obter($this->user($r), $r->params['id'])); }
    public function iniciar(Request $r): Response { return $this->ok($this->svc->iniciar($this->user($r), $r->params['id'])); }
    public function registro(Request $r): Response { return $this->ok($this->svc->registrar($this->user($r), $r->params['id'], $r->body)); }
    public function horarios(Request $r): Response { return $this->ok($this->svc->ajustarHorarios($this->user($r), $r->params['id'], $r->body)); }
    public function visibilidade(Request $r): Response { return $this->ok($this->svc->definirVisibilidade($this->user($r), $r->params['id'], $r->input('registroVisivel'))); }
    public function finalizar(Request $r): Response { return $this->ok($this->svc->finalizar($this->user($r), $r->params['id'], $r->body)); }
}
