<?php
declare(strict_types=1);

namespace Sigep\Controllers;

use Sigep\Core\Request;
use Sigep\Core\Response;
use Sigep\Services\AuditService;
use Sigep\Services\RelatorioService;
use Sigep\Models\Categoria;

final class RelatorioController extends Controller
{
    public function __construct(private RelatorioService $svc, private AuditService $audit)
    {
    }

    public function resumo(Request $r): Response { return $this->ok($this->svc->resumo($this->user($r), $r->query)); }

    public function exportar(Request $r): Response
    {
        $u = $this->user($r);
        $res = $this->svc->exportar($u, $r->query);
        $formato = strtolower((string) ($r->query['formato'] ?? 'csv'));
        $this->audit->log($u, 'Exportou relatório de atendimentos (' . strtoupper($formato) . ')', Categoria::ATENDIMENTOS, $r->ip);
        if ($formato === 'svg') {
            return new Response(200, ['Content-Type' => $res['mime'], 'Content-Disposition' => 'attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $res['arquivo']) . '"'], $res['conteudo']);
        }
        return Response::csv($res['conteudo'], $res['arquivo']);
    }
}
