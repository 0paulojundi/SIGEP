<?php
declare(strict_types=1);

namespace Sigep\Controllers;

use Sigep\Core\HttpException;
use Sigep\Core\Request;
use Sigep\Models\Perfil;
use Sigep\Core\Response;
use Sigep\Services\AgendamentoService;

/** Solicitações = agendamentos que ainda dependem de decisão (Pendente) ou foram recusados. */
final class SolicitacaoController extends Controller
{
    public function __construct(private AgendamentoService $svc)
    {
    }

    public function index(Request $r): Response
    {
        $u = $this->user($r);
        if ($u['perfil'] === Perfil::ADMIN) {
            throw HttpException::forbidden('A Instituição não visualiza nem gerencia solicitações de atendimento.');
        }
        return $this->ok($this->svc->listar($u, $r->query + ['status' => 'Pendente,Recusado']));
    }

    public function store(Request $r): Response { return $this->created($this->svc->solicitar($this->user($r), $r->body)); }
    public function aprovar(Request $r): Response { return $this->ok($this->svc->aprovar($this->user($r), $r->params['id'])); }
    public function recusar(Request $r): Response { return $this->ok($this->svc->recusar($this->user($r), $r->params['id'], $r->body)); }
}
