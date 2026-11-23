<?php
declare(strict_types=1);

namespace Sigep\Core;

/** Erro de negócio/HTTP que vira uma resposta JSON padronizada. */
final class HttpException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
        public readonly array $headers = []
    ) {
        parent::__construct($message, $status);
    }

    public static function badRequest(string $m = 'Requisição inválida.', array $d = []): self { return new self(400, 'requisicao_invalida', $m, $d); }
    public static function unauthorized(string $m = 'Autenticação necessária.'): self { return new self(401, 'nao_autenticado', $m); }
    public static function forbidden(string $m = 'Você não tem permissão para esta operação.'): self { return new self(403, 'sem_permissao', $m); }
    public static function notFound(string $m = 'Registro não encontrado.'): self { return new self(404, 'nao_encontrado', $m); }
    public static function conflict(string $m, array $d = []): self { return new self(409, 'conflito', $m, $d); }
    public static function unprocessable(array $errors, string $m = 'Dados inválidos.'): self { return new self(422, 'dados_invalidos', $m, $errors); }
    public static function tooMany(int $retryAfter): self { return new self(429, 'muitas_tentativas', 'Muitas tentativas. Tente novamente mais tarde.', [], ['Retry-After' => (string) $retryAfter]); }
    public static function notImplemented(string $m): self { return new self(501, 'nao_implementado', $m); }
}
