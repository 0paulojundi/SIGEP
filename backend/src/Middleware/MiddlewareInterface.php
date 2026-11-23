<?php
declare(strict_types=1);

namespace Sigep\Middleware;

use Sigep\Core\Request;
use Sigep\Core\Response;

interface MiddlewareInterface
{
    public function handle(Request $request, callable $next): Response;
}
