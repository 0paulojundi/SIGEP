<?php
declare(strict_types=1);

namespace Sigep\Controllers;

use Sigep\Core\Request;
use Sigep\Core\Response;
use Sigep\Services\AgendamentoService;

final class AgendamentoController extends Controller
{
    public function __construct(private AgendamentoService $svc)
    {
    }

    public function index(Request $r): Response { return $this->ok($this->svc->listar($this->user($r), $r->query)); }
    public function show(Request $r): Response { return $this->ok($this->svc->obter($this->user($r), $r->params['id'])); }
    public function store(Request $r): Response { return $this->created($this->svc->agendarDireto($this->user($r), $r->body)); }
    public function update(Request $r): Response { return $this->ok($this->svc->atualizar($this->user($r), $r->params['id'], $r->body)); }
    public function cancelar(Request $r): Response { return $this->ok($this->svc->cancelar($this->user($r), $r->params['id'], $r->body)); }
    public function avisoResponsavel(Request $r): Response { return $this->ok($this->svc->definirAvisoAoResponsavel($this->user($r), $r->params['id'], $r->input('notificarResponsavel'))); }
}
