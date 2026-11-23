<?php
declare(strict_types=1);

namespace Sigep\Controllers;

use Sigep\Core\Request;
use Sigep\Core\Response;
use Sigep\Services\AlunoService;
use Sigep\Services\AtendimentoService;

final class AlunoController extends Controller
{
    public function __construct(private AlunoService $svc, private AtendimentoService $atend)
    {
    }

    public function index(Request $r): Response { return $this->ok($this->svc->listar($this->user($r), $r->query)); }
    public function show(Request $r): Response { return $this->ok($this->svc->obter($this->user($r), $r->params['rm'])); }
    public function store(Request $r): Response { return $this->created($this->svc->criar($this->user($r), $r->body)); }
    public function update(Request $r): Response { return $this->ok($this->svc->atualizar($this->user($r), $r->params['rm'], $r->body)); }
    public function destroy(Request $r): Response { $this->svc->excluir($this->user($r), $r->params['rm']); return $this->ok(['mensagem' => 'Aluno excluído.']); }
    public function historico(Request $r): Response { return $this->ok($this->atend->historicoDoAluno($this->user($r), $r->params['rm'])); }
}
