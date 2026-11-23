<?php
declare(strict_types=1);

namespace Sigep\Controllers;

use Sigep\Core\Request;
use Sigep\Core\Response;
use Sigep\Services\ConsultaAgendaService;

final class AgendaController extends Controller
{
    public function __construct(private ConsultaAgendaService $svc)
    {
    }

    public function agenda(Request $r): Response { return $this->ok($this->svc->agenda($this->user($r), $r->query)); }
    public function historico(Request $r): Response { return $this->ok($this->svc->historico($this->user($r), $r->query)); }
    public function ocupacao(Request $r): Response { return $this->ok($this->svc->ocupacao($this->user($r), $r->params['id'], (string) ($r->query['data'] ?? ''))); }
}
