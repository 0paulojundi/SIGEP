<?php
declare(strict_types=1);

namespace Sigep\Controllers;

use Sigep\Core\Request;
use Sigep\Core\Response;

final class SistemaController extends Controller
{
    public function health(Request $r): Response { return $this->ok(['status' => 'ok', 'sistema' => 'SIGEP API', 'hora' => date('c')]); }
}
