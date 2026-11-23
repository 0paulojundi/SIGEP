<?php
declare(strict_types=1);

namespace Sigep\Controllers;

use Sigep\Core\Request;
use Sigep\Core\Response;
use Sigep\Services\DisponibilidadeService;

final class DisponibilidadeController extends Controller
{
    public function __construct(private DisponibilidadeService $svc)
    {
    }

    public function index(Request $r): Response { return $this->ok($this->svc->listar($this->user($r), $r->query['orientadorId'] ?? null)); }
    public function store(Request $r): Response { return $this->created($this->svc->criar($this->user($r), $r->body)); }
    public function update(Request $r): Response { return $this->ok($this->svc->atualizar($this->user($r), $r->params['id'], $r->body)); }
    public function destroy(Request $r): Response { $this->svc->excluir($this->user($r), $r->params['id']); return $this->ok(['mensagem' => 'Disponibilidade excluída.']); }
    public function horarios(Request $r): Response { return $this->ok($this->svc->horariosLivres($r->params['id'], (string) ($r->query['data'] ?? ''))); }
}
