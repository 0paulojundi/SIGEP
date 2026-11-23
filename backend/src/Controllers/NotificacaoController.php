<?php
declare(strict_types=1);

namespace Sigep\Controllers;

use Sigep\Core\Request;
use Sigep\Core\Response;
use Sigep\Core\Validator;
use Sigep\Services\NotificationService;

final class NotificacaoController extends Controller
{
    public function __construct(private NotificationService $svc)
    {
    }

    public function index(Request $r): Response
    {
        $res = $this->svc->listar($this->user($r), $r->query);
        return $this->ok($res['itens'], ['naoLidas' => $res['naoLidas']]);
    }

    public function marcar(Request $r): Response
    {
        $d = Validator::validate($r->body, ['lida' => 'required|bool']);
        return $this->ok($this->svc->marcar($this->user($r), $r->params['id'], $d['lida']));
    }

    public function marcarTodas(Request $r): Response { return $this->ok(['marcadas' => $this->svc->marcarTodas($this->user($r))]); }
}
