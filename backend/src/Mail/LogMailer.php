<?php
declare(strict_types=1);

namespace Sigep\Mail;

/**
 * Padrão em desenvolvimento: NÃO envia e-mail, apenas grava em storage/logs/mail.log (contém o link de redefinição!).
 * Em produção use MAIL_DRIVER=native (ou implemente um SmtpMailer) — este log não pode ficar acessível.
 */
final class LogMailer implements MailerInterface
{
    public function __construct(private string $logFile) {}

    public function send(string $to, string $subject, string $body): bool
    {
        $line = json_encode(['quando' => date('c'), 'para' => $to, 'assunto' => $subject, 'corpo' => $body], JSON_UNESCAPED_UNICODE);
        return @file_put_contents($this->logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
    }
}
