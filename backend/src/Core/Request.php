<?php
declare(strict_types=1);

namespace Sigep\Core;

final class Request
{
    /** Parâmetros da rota ({id}) preenchidos pelo Router. */
    public array $params = [];
    /** Dados anexados pelos middlewares (ex.: user). */
    public array $attributes = [];

    public function __construct(
        public string $method,
        public string $path,
        public array $query = [],
        public array $body = [],
        public array $headers = [],
        public string $ip = '127.0.0.1'
    ) {
        $this->method = strtoupper($method);
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    public static function capture(): self
    {
        $uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        if (basename($script) !== 'index.php') {
            $script = ''; // servidor embutido com roteador: SCRIPT_NAME vem igual ao caminho da requisição
        }
        $base = $script !== '' ? rtrim(str_replace('\\', '/', dirname($script)), '/') : '';
        if ($script !== '' && str_starts_with($uriPath, $script)) {
            $uriPath = substr($uriPath, strlen($script));
        } elseif ($base !== '' && str_starts_with($uriPath, $base)) {
            $uriPath = substr($uriPath, strlen($base));
        }
        $path = '/' . trim($uriPath, '/');

        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[str_replace('_', '-', substr($k, 5))] = (string) $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }

        $body = [];
        $raw = file_get_contents('php://input', false, null, 0, 1048577) ?: '';
        if (strlen($raw) > 1048576) {
            throw new HttpException(413, 'corpo_grande', 'Corpo da requisição muito grande.');
        }
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw HttpException::badRequest('JSON inválido no corpo da requisição.');
            }
            $body = $decoded;
        } elseif (!empty($_POST)) {
            $body = $_POST;
        }
        return new self($_SERVER['REQUEST_METHOD'] ?? 'GET', $path, $_GET, $body, $headers, $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function user(): ?array
    {
        return $this->attributes['user'] ?? null;
    }
}
