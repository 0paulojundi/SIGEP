<?php
declare(strict_types=1);

namespace Sigep\Mail;

/** Guarda as mensagens em memória (usado só nos testes automatizados). */
final class ArrayMailer implements MailerInterface
{
    /** @var array<int,array{para:string,assunto:string,corpo:string}> */
    public array $enviados = [];

    public function send(string $to, string $subject, string $body): bool
    {
        $this->enviados[] = ['para' => $to, 'assunto' => $subject, 'corpo' => $body];
        return true;
    }
}
