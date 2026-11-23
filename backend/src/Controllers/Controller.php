<?php
declare(strict_types=1);

namespace Sigep\Controllers;

use Sigep\Core\HttpException;
use Sigep\Core\Request;
use Sigep\Core\Response;

/** Controllers só traduzem HTTP ⇄ serviços. Nenhuma regra de negócio fica aqui. */
abstract class Controller
{
    protected function user(Request $r): array
    {
        return $r->user() ?? throw HttpException::unauthorized();
    }

    protected function ok(mixed $data = null, ?array $meta = null): Response { return Response::ok($data, $meta); }
    protected function created(mixed $data): Response { return Response::created($data); }
}
