<?php
declare(strict_types=1);

namespace Sigep\Controllers;

use Sigep\Core\Request;
use Sigep\Core\Response;
use Sigep\Core\Validator;
use Sigep\Services\AuditService;

final class AuditoriaController extends Controller
{
    public function __construct(private AuditService $svc)
    {
    }

    public function index(Request $r): Response
    {
        Validator::validate($r->query, ['de' => 'date', 'ate' => 'date']);
        $rows = $this->svc->buscar($r->query);
        $pag = max(1, (int) ($r->query['pagina'] ?? 1));
        $por = min(200, max(1, (int) ($r->query['porPagina'] ?? 50)));
        return $this->ok(array_slice($rows, ($pag - 1) * $por, $por), ['total' => count($rows), 'pagina' => $pag, 'porPagina' => $por]);
    }
}
