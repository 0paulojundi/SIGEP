<?php
declare(strict_types=1);

/** Ponto de entrada da API. Servidor local: php -S localhost:8000 -t backend backend/index.php */
require __DIR__ . '/bootstrap.php';

use Sigep\Core\HttpException;
use Sigep\Core\Request;
use Sigep\Core\Response;

try {
    $kernel = Sigep\App::create();
    $kernel->handle(Request::capture())->send();
} catch (HttpException $e) {
    Response::error($e->status, $e->errorCode, $e->getMessage())->send();
} catch (Throwable $e) {
    error_log('[SIGEP] ' . $e->getMessage());
    Response::error(500, 'erro_interno', 'Erro interno do servidor.')->send();
}
