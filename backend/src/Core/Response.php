<?php
declare(strict_types=1);

namespace Sigep\Core;

final class Response
{
    public function __construct(public int $status = 200, public array $headers = [], public ?string $body = null) {}

    public static function ok(mixed $data = null, ?array $meta = null, int $status = 200): self
    {
        $payload = ['ok' => true, 'data' => $data];
        if ($meta !== null) {
            $payload['meta'] = $meta;
        }
        return self::json($payload, $status);
    }

    public static function created(mixed $data): self { return self::ok($data, null, 201); }

    public static function error(int $status, string $code, string $message, array $details = [], array $headers = []): self
    {
        $err = ['code' => $code, 'message' => $message];
        if ($details) {
            $err['details'] = $details;
        }
        return self::json(['ok' => false, 'error' => $err], $status, $headers);
    }

    public static function json(array $payload, int $status = 200, array $headers = []): self
    {
        return new self($status, $headers + ['Content-Type' => 'application/json; charset=utf-8'],
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    public static function csv(string $content, string $filename): self
    {
        return new self(200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename) . '"',
        ], "\xEF\xBB\xBF" . $content);
    }

    /** Campo "data" do corpo JSON (útil nos testes). */
    public function data(): mixed
    {
        $d = json_decode((string) $this->body, true);
        return is_array($d) ? ($d['data'] ?? null) : null;
    }

    public function payload(): array
    {
        $d = json_decode((string) $this->body, true);
        return is_array($d) ? $d : [];
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header("$k: $v");
        }
        echo (string) $this->body;
    }
}
