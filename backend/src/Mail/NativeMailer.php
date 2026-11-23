<?php
declare(strict_types=1);

namespace Sigep\Mail;

/**
 * Usa a função mail() do PHP (disponível em hospedagens compartilhadas).
 * TODO(MAIL): para SMTP autenticado, crie um SmtpMailer que implemente MailerInterface e registre em App.php.
 */
final class NativeMailer implements MailerInterface
{
    public function __construct(private string $fromAddress, private string $fromName) {}

    public function send(string $to, string $subject, string $body): bool
    {
        $headers = [
            'From' => sprintf('=?UTF-8?B?%s?= <%s>', base64_encode($this->fromName), $this->fromAddress),
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
        ];
        $h = '';
        foreach ($headers as $k => $v) {
            $h .= $k . ': ' . str_replace(["\r", "\n"], '', $v) . "\r\n";
        }
        return mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $h);
    }
}
