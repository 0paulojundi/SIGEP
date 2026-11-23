<?php
declare(strict_types=1);

namespace Sigep\Controllers;

use Sigep\Core\Request;
use Sigep\Core\Response;
use Sigep\Services\InstituicaoService;

final class InstituicaoController extends Controller
{
    public function __construct(private InstituicaoService $svc)
    {
    }

    public function show(Request $r): Response { return $this->ok($this->svc->obter()); }
    public function update(Request $r): Response { return $this->ok($this->svc->atualizar($this->user($r), $r->body)); }
}
